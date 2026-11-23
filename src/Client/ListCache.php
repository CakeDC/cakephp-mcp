<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Cake\Cache\Cache;
use Cake\I18n\DateTime;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use Mcp\Schema\Enum\CacheScope;

/**
 * `Mcp.clients.<name>.cache`: results (`tools/list`, `resources/list`, `resources/templates/list`,
 * `prompts/list`, `resources/read` and `server/discover`) that carry the cache hints of the 2026-07-28 revision
 * (`ttlMs`, `cacheScope`) are kept in that Cake cache config for their ttl. A `private` result is keyed by user in user mode and by client in app mode, and
 * always by the credential it was asked with (the user's access token, the configured token or headers, what a
 * `token` callable returns): it is never served for another token, nor after a reconnection with another account.
 * A `public` one is keyed by client. No hints, no caching. A `notifications/*\/list_changed` drops the entries of
 * its lists, and a `notifications/resources/updated` the reads of its URI.
 */
final class ListCache
{
    /**
     * @var list<string>
     */
    public const METHODS = [
        'tools/list',
        'resources/list',
        'resources/templates/list',
        'prompts/list',
        'resources/read',
        'server/discover',
    ];

    /**
     * The largest result kept, in bytes of JSON: a resource can be a large blob.
     */
    public const MAX_ENTRY_BYTES = 512 * 1024;

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client, with `cache` set.
     * @param string|null $userId The user in user mode; null in app mode.
     * @param string $credential What the requests are authorized with; only its hash is kept, in the key.
     */
    public function __construct(private ClientConfig $config, private ?string $userId, private string $credential = '')
    {
    }

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client.
     * @param string|null $userId The user in user mode; null in app mode.
     * @param string $credential What the requests are authorized with.
     * @return self|null Null when the client has no `cache`.
     */
    public static function for(ClientConfig $config, ?string $userId, string $credential = ''): ?self
    {
        return $config->cache === null ? null : new self($config, $userId, $credential);
    }

    /**
     * @param string $credential What the requests are authorized with.
     * @return self This cache for that credential.
     */
    public function withCredential(string $credential): self
    {
        return new self($this->config, $this->userId, $credential);
    }

    /**
     * @param string $method A method of METHODS.
     * @param string|null $cursor The page cursor of a list, the URI of `resources/read`, null for `server/discover`.
     * @return object|array<string, mixed>|null What was kept (the SDK's result, or the raw result of
     *   `server/discover`), when one is fresh for this caller.
     */
    public function get(string $method, ?string $cursor): object|array|null
    {
        foreach ([CacheScope::Private, CacheScope::Public] as $scope) {
            $entry = Cache::read($this->key($method, $cursor, $scope), (string)$this->config->cache);
            if (
                is_array($entry)
                && is_int($entry['expires'] ?? null)
                && $entry['expires'] > self::nowMs()
                && (is_object($entry['page'] ?? null) || is_array($entry['page'] ?? null))
            ) {
                return $entry['page'];
            }
        }

        return null;
    }

    /**
     * Keeps $page for the `ttlMs` of $result, under its `cacheScope`; does nothing without both hints.
     *
     * @param string $method A method of METHODS.
     * @param string|null $cursor The page cursor, or the URI of `resources/read`.
     * @param array<string, mixed> $result The raw `result` of the server's answer, for its hints.
     * @param object|array<string, mixed> $page What to keep: the SDK's result made from it, or the raw result.
     * @return void
     */
    public function put(string $method, ?string $cursor, array $result, object|array $page): void
    {
        $ttl = $result['ttlMs'] ?? null;
        $scope = is_string($result['cacheScope'] ?? null) ? CacheScope::tryFrom($result['cacheScope']) : null;
        if (!in_array($method, self::METHODS, true) || !is_int($ttl) || $ttl <= 0 || $scope === null) {
            return;
        }
        if (strlen((string)json_encode($result)) > self::MAX_ENTRY_BYTES) {
            return;
        }
        // The SDK learns the Mcp-Param headers of a tool from the listing it fetched itself: a tool list whose
        // tools have x-mcp-header annotations is always fetched.
        if ($method === 'tools/list' && str_contains((string)json_encode($result['tools'] ?? []), 'x-mcp-header')) {
            return;
        }
        Cache::write(
            $this->key($method, $cursor, $scope),
            ['expires' => self::nowMs() + $ttl, 'page' => $page],
            (string)$this->config->cache,
        );
    }

    /**
     * Drops every entry of $method for this client, all users and pages included, or only those of $part (the URI
     * of a `resources/read`).
     *
     * @param string $method A method of METHODS.
     * @param string|null $part Only the entries for this cursor or URI; null for all.
     * @return void
     */
    public function forget(string $method, ?string $part = null): void
    {
        $key = $this->generationKey($part === null ? $method : $method . "\0" . $part);
        Cache::write($key, bin2hex(random_bytes(8)), (string)$this->config->cache);
    }

    /**
     * @param string $method A list method.
     * @param string|null $cursor The page cursor.
     * @param \Mcp\Schema\Enum\CacheScope $scope The scope.
     * @return string
     */
    private function key(string $method, ?string $cursor, CacheScope $scope): string
    {
        $owner = match (true) {
            $scope === CacheScope::Public => 'public',
            $this->userId !== null => 'user:' . TableConnectionStore::connectionKey($this->config->name, $this->userId),
            default => 'app',
        };
        if ($scope === CacheScope::Private) {
            $owner .= ':' . hash('sha256', $this->credential);
        }
        $generation = Cache::read($this->generationKey($method), (string)$this->config->cache);
        $own = $cursor === null
            ? null
            : Cache::read($this->generationKey($method . "\0" . $cursor), (string)$this->config->cache);

        return 'mcp_client_list_' . hash('sha256', (string)json_encode([
            $this->config->name,
            // A client that now points to another server never gets what the previous one answered.
            $this->config->url,
            $method,
            $cursor,
            $owner,
            is_string($generation) ? $generation : '',
            is_string($own) ? $own : '',
        ]));
    }

    /**
     * @param string $method A method of METHODS, maybe with a cursor or URI after a NUL.
     * @return string
     */
    private function generationKey(string $method): string
    {
        return 'mcp_client_list_gen_' . hash('sha256', $this->config->name . "\0" . $method);
    }

    /**
     * @return int Milliseconds since the epoch, on Cake's (testable) clock.
     */
    private static function nowMs(): int
    {
        return (int)DateTime::now()->format('Uv');
    }
}
