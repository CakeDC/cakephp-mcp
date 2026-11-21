<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Cake\Cache\Cache;
use Cake\I18n\DateTime;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use Mcp\Schema\Enum\CacheScope;

/**
 * `Mcp.clients.<name>.cache`: list results (`tools/list`, `resources/list`, `resources/templates/list`,
 * `prompts/list`) that carry the cache hints of the 2026-07-28 revision (`ttlMs`, `cacheScope`) are kept in that
 * Cake cache config for their ttl. A `private` result is keyed by user in user mode and by client in app mode; a
 * `public` one by client. No hints, no caching. A `notifications/*\/list_changed` drops the entries of its lists.
 */
final class ListCache
{
    /**
     * @var list<string>
     */
    public const METHODS = ['tools/list', 'resources/list', 'resources/templates/list', 'prompts/list'];

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client, with `cache` set.
     * @param string|null $userId The user in user mode; null in app mode.
     */
    public function __construct(private ClientConfig $config, private ?string $userId)
    {
    }

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client.
     * @param string|null $userId The user in user mode; null in app mode.
     * @return self|null Null when the client has no `cache`.
     */
    public static function for(ClientConfig $config, ?string $userId): ?self
    {
        return $config->cache === null ? null : new self($config, $userId);
    }

    /**
     * @param string $method A list method.
     * @param string|null $cursor The page cursor.
     * @return object|null The cached page (the SDK's list result), when one is fresh for this caller.
     */
    public function get(string $method, ?string $cursor): ?object
    {
        foreach ([CacheScope::Private, CacheScope::Public] as $scope) {
            $entry = Cache::read($this->key($method, $cursor, $scope), (string)$this->config->cache);
            if (
                is_array($entry)
                && is_int($entry['expires'] ?? null)
                && $entry['expires'] > self::nowMs()
                && is_object($entry['page'] ?? null)
            ) {
                return $entry['page'];
            }
        }

        return null;
    }

    /**
     * Keeps $page for the `ttlMs` of $result, under its `cacheScope`; does nothing without both hints.
     *
     * @param string $method A list method.
     * @param string|null $cursor The page cursor.
     * @param array<string, mixed> $result The raw `result` of the server's answer, for its hints.
     * @param object $page The SDK's list result made from it.
     * @return void
     */
    public function put(string $method, ?string $cursor, array $result, object $page): void
    {
        $ttl = $result['ttlMs'] ?? null;
        $scope = is_string($result['cacheScope'] ?? null) ? CacheScope::tryFrom($result['cacheScope']) : null;
        if (!in_array($method, self::METHODS, true) || !is_int($ttl) || $ttl <= 0 || $scope === null) {
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
     * Drops every entry of $method for this client, all users and pages included.
     *
     * @param string $method A list method.
     * @return void
     */
    public function forget(string $method): void
    {
        Cache::write($this->generationKey($method), bin2hex(random_bytes(8)), (string)$this->config->cache);
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
        $generation = Cache::read($this->generationKey($method), (string)$this->config->cache);

        return 'mcp_client_list_' . hash('sha256', (string)json_encode([
            $this->config->name,
            $method,
            $cursor,
            $owner,
            is_string($generation) ? $generation : '',
        ]));
    }

    /**
     * @param string $method A list method.
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
