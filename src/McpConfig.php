<?php
declare(strict_types=1);

namespace CakeDC\Mcp;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Utility\Hash;
use CakeDC\Mcp\Authorization\AllowAllToolAuthorizer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the plugin configuration (`Mcp` in Configure) merged over its defaults.
 *
 * The merge happens on every read, so the host may write `Mcp` at any point of
 * its bootstrap. Lists (such as `discovery.scanDirs`) replace the default list. An empty array
 * over an associative default keeps the default, so `Mcp.identity => []` cannot disable authentication.
 */
final class McpConfig
{
    /**
     * Path prefix of the protected resource metadata (RFC 9728), followed by the MCP route.
     */
    public const METADATA_PREFIX = '/.well-known/oauth-protected-resource';

    /**
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'route' => '/mcp',
        'server' => [
            'name' => 'CakePHP MCP Server',
            'version' => '1.0.0',
            'title' => null,
            'description' => null,
            'websiteUrl' => null,
            'instructions' => null,
            'configure' => null,
        ],
        'discovery' => ['scanDirs' => ['src/Mcp'], 'excludeDirs' => [], 'cache' => '_cake_mcp_'],
        'session' => ['cache' => 'mcp_sessions', 'ttl' => 3600],
        'http' => ['allowedHosts' => null, 'allowedOrigins' => []],
        'identity' => ['required' => true, 'attribute' => 'identity', 'scopes' => null],
        'authorization' => ['authorizer' => AllowAllToolAuthorizer::class, 'rbac' => []],
        'auth' => [
            'mode' => 'host',
            'resource' => null,
            'resourceName' => null,
            'resourceDocumentation' => null,
            'scopes' => ['default' => [], 'methods' => [], 'tools' => [], 'implies' => []],
            'identity' => null,
            'external' => [
                'issuer' => null,
                'audience' => null,
                'algorithms' => ['RS256'],
                'scopeClaim' => 'scope',
                'tokenType' => null,
                'leeway' => 0,
                'cache' => 'mcp_oauth',
                'httpClient' => null,
            ],
        ],
        'stdio' => ['identity' => null, 'trusted' => true],
    ];

    /**
     * @param string|null $path Dot path inside the `Mcp` config, or null for all of it.
     * @return mixed
     */
    public static function get(?string $path = null): mixed
    {
        $config = self::merge(self::DEFAULTS, (array)Configure::read('Mcp'));

        return $path === null ? $config : Hash::get($config, $path);
    }

    /**
     * The cache config used for the discovery cache, or null when it is disabled.
     * Always null in debug mode, so new tools show up without clearing caches.
     *
     * @return string|null
     */
    public static function discoveryCache(): ?string
    {
        $name = self::get('discovery.cache');
        if (Configure::read('debug') || !is_string($name) || $name === '') {
            return null;
        }

        return $name;
    }

    /**
     * Hosts the DNS-rebinding protection accepts. Defaults to the host of
     * `App.fullBaseUrl` plus localhost; the SDK default (localhost only) would
     * reject every request to a real domain.
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $hosts = self::get('http.allowedHosts');
        if (is_array($hosts)) {
            return array_values(array_map(fn(mixed $host): string => strtolower((string)$host), $hosts));
        }

        $allowed = ['localhost', '127.0.0.1', '[::1]'];
        $host = parse_url((string)Configure::read('App.fullBaseUrl'), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            array_unshift($allowed, strtolower($host));
        }

        return array_values(array_unique($allowed));
    }

    /**
     * Recursive merge where associative arrays merge and lists are replaced.
     *
     * @param array<mixed> $base The defaults.
     * @param array<mixed> $override The host values.
     * @return array<mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                $value === [] && isset($base[$key]) && is_array($base[$key])
                && $base[$key] !== [] && !array_is_list($base[$key])
            ) {
                // An empty array over an associative default is a misconfiguration, not an intent to clear it.
                continue;
            }
            if (
                is_array($value) && isset($base[$key]) && is_array($base[$key])
                && !array_is_list($value) && !array_is_list($base[$key])
            ) {
                $base[$key] = self::merge($base[$key], $value);

                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Whether $request is for the MCP endpoint or its protected resource metadata. Use it to skip
     * Cake's CSRF check there, and to keep other bearer-token authenticators off these paths.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return bool
     */
    public static function isEndpoint(ServerRequestInterface $request): bool
    {
        $route = self::get('route');
        if (!is_string($route) || trim($route, '/') === '') {
            return false;
        }
        $route = '/' . trim($route, '/');
        $path = $request instanceof ServerRequest ? $request->getPath() : $request->getUri()->getPath();
        $path = '/' . trim($path, '/');

        return $path === $route || $path === self::METADATA_PREFIX . $route;
    }
}
