<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Server;

use InvalidArgumentException;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Server\Wire\CachePolicy;

/**
 * Reads `Mcp.server.cache` into the SDK's cache policy: the `ttlMs` and `cacheScope` hints that results of the
 * 2026-07-28 protocol carry on cacheable methods (`tools/list`, `resources/read`...).
 */
final class CacheHints
{
    /**
     * @param mixed $config `null` or `[]` (no hints), or `['ttl' => ms, 'scope' => 'public'|'private', 'methods' => [method => [...]]]`.
     * @return \Mcp\Server\Wire\CachePolicy|null Null keeps the SDK default (private, not cached).
     * @throws \InvalidArgumentException When the config is not of that shape.
     */
    public static function fromConfig(mixed $config): ?CachePolicy
    {
        // Both mean "no hints": a named server writes one of them to drop the default server's.
        if ($config === null || $config === []) {
            return null;
        }
        if (!is_array($config)) {
            throw new InvalidArgumentException('"Mcp.server.cache" must be null or an array.');
        }
        [$ttl, $scope] = self::hint($config, 'Mcp.server.cache');
        $policy = CachePolicy::default($ttl, $scope);

        $methods = $config['methods'] ?? [];
        if (!is_array($methods)) {
            throw new InvalidArgumentException('"Mcp.server.cache.methods" must be an array of method => hint.');
        }
        foreach ($methods as $method => $hint) {
            $path = sprintf('Mcp.server.cache.methods.%s', $method);
            if (!is_string($method) || !is_array($hint)) {
                throw new InvalidArgumentException(sprintf('"%s" must be an array with ttl and scope.', $path));
            }
            [$methodTtl, $methodScope] = self::hint($hint + ['ttl' => $ttl, 'scope' => $scope->value], $path);
            $policy = $policy->withMethod($method, $methodTtl, $methodScope);
        }

        return $policy;
    }

    /**
     * $policy with `tools/list` private: an authorizer can give each user different tools, so a shared cache must
     * never serve one user's list to another.
     *
     * @param \Mcp\Server\Wire\CachePolicy $policy The policy.
     * @return \Mcp\Server\Wire\CachePolicy
     */
    public static function privateToolList(CachePolicy $policy): CachePolicy
    {
        return self::private($policy, ['tools/list']);
    }

    /**
     * $policy with $methods private, keeping their ttl: results an authorizer filters per caller.
     *
     * @param \Mcp\Server\Wire\CachePolicy $policy The policy.
     * @param list<string> $methods The methods.
     * @return \Mcp\Server\Wire\CachePolicy
     */
    public static function private(CachePolicy $policy, array $methods): CachePolicy
    {
        foreach ($methods as $method) {
            if ($policy->scopeFor($method) === CacheScope::Public) {
                $policy = $policy->withMethod($method, $policy->ttlFor($method), CacheScope::Private);
            }
        }

        return $policy;
    }

    /**
     * @param array<mixed> $hint An array with optional `ttl` and `scope`.
     * @param string $path The config path, for the error message.
     * @return array{0: int, 1: \Mcp\Schema\Enum\CacheScope}
     */
    private static function hint(array $hint, string $path): array
    {
        $ttl = $hint['ttl'] ?? 0;
        if (!is_int($ttl) || $ttl < 0) {
            throw new InvalidArgumentException(sprintf('"%s.ttl" must be zero or more milliseconds.', $path));
        }
        $scope = is_string($hint['scope'] ?? null) ? CacheScope::tryFrom($hint['scope']) : null;
        if (array_key_exists('scope', $hint) && $scope === null) {
            throw new InvalidArgumentException(sprintf('"%s.scope" must be "public" or "private".', $path));
        }

        return [$ttl, $scope ?? CacheScope::Private];
    }
}
