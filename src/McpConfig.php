<?php
declare(strict_types=1);

namespace CakeDC\Mcp;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Utility\Hash;
use CakeDC\Mcp\Authorization\AllowAllToolAuthorizer;
use CakeDC\Mcp\Client\ClientConfig;
use InvalidArgumentException;
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
            'cache' => null,
            'configure' => null,
        ],
        'discovery' => ['scanDirs' => ['src/Mcp'], 'excludeDirs' => [], 'cache' => '_cake_mcp_'],
        'schema' => ['normalize' => false],
        'catalog' => ['enabled' => false, 'tools' => [], 'maxCalls' => 5],
        'apps' => ['enabled' => false],
        'session' => ['cache' => 'mcp_sessions', 'ttl' => 3600],
        'http' => ['allowedHosts' => null, 'allowedOrigins' => [], 'maxBodyBytes' => 4 * 1024 * 1024],
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
        'audit' => [
            'enabled' => false,
            'methods' => ['tools/call'],
            'log' => true,
            'arguments' => true,
            'redact' => ['password', 'token', 'secret'],
            'maxLength' => 200,
        ],
    ];

    /**
     * The server the top-level `Mcp` config describes.
     */
    public const DEFAULT_SERVER = 'default';

    /**
     * Sections a named server replaces whole when it sets them, so a narrower server never picks up the default
     * server's scopes, rules or tools. `server.configure` follows the same rule.
     *
     * @var list<string>
     */
    private const REPLACED_SECTIONS = ['auth', 'authorization', 'discovery'];

    /**
     * @param string|null $path Dot path inside the server's config, or null for all of it.
     * @param string $server The server: `default` (the top-level `Mcp` config) or a key of `Mcp.servers`.
     * @return mixed
     * @throws \InvalidArgumentException When the server is not configured or its config is invalid.
     */
    public static function get(?string $path = null, string $server = self::DEFAULT_SERVER): mixed
    {
        $config = self::resolve($server);

        return $path === null ? $config : Hash::get($config, $path);
    }

    /**
     * The resolved config of a server, as `get(null, $server)`.
     *
     * @param string $server The server: `default` or a key of `Mcp.servers`.
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When the server is not configured or its config is invalid.
     */
    public static function for(string $server): array
    {
        return self::resolve($server);
    }

    /**
     * The servers the plugin serves: `default` first, then the keys of `Mcp.servers` in order, without the named
     * servers whose config is invalid. Those are listed by `serverErrors()`: the plugin logs them, does not
     * connect their routes and `bin/cake mcp check` fails on them, while the rest of the application keeps working.
     *
     * @return list<string>
     */
    public static function servers(): array
    {
        $servers = [self::DEFAULT_SERVER];
        foreach (array_keys(self::named()[0]) as $name) {
            try {
                self::resolve($name);
            } catch (InvalidArgumentException) {
                continue;
            }
            $servers[] = $name;
        }

        return $servers;
    }

    /**
     * The entries of `Mcp.servers` that cannot be served, as entry name => what is wrong. The name is
     * `Mcp.servers` when the whole setting is not an array of server name => config.
     *
     * @return array<string, string>
     */
    public static function serverErrors(): array
    {
        [$valid, $errors] = self::named();
        foreach (array_keys($valid) as $name) {
            try {
                self::resolve($name);
            } catch (InvalidArgumentException $e) {
                $errors[$name] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * The server whose route or protected resource metadata route $request is for, or null.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return string|null
     */
    public static function serverFor(ServerRequestInterface $request): ?string
    {
        $path = $request instanceof ServerRequest ? $request->getPath() : $request->getUri()->getPath();
        $path = '/' . trim($path, '/');
        foreach (self::servers() as $server) {
            $route = self::get('route', $server);
            if (!is_string($route) || trim($route, '/') === '') {
                continue;
            }
            $route = '/' . trim($route, '/');
            if ($path === $route || $path === self::METADATA_PREFIX . $route) {
                return $server;
            }
        }

        return null;
    }

    /**
     * @param string $server The server name.
     * @return array<string, mixed> The resolved config of $server.
     */
    private static function resolve(string $server): array
    {
        $top = (array)Configure::read('Mcp');
        unset($top['servers']);
        $default = self::merge(self::DEFAULTS, $top);
        if ($server === self::DEFAULT_SERVER) {
            return $default;
        }

        [$valid, $errors] = self::named();
        if (isset($errors[$server])) {
            throw new InvalidArgumentException($errors[$server]);
        }
        $named = $valid[$server] ?? null;
        if ($named === null) {
            throw new InvalidArgumentException(sprintf('"Mcp.servers.%s" is not configured.', $server));
        }
        $config = $default;
        unset($named['servers']);
        foreach (self::REPLACED_SECTIONS as $section) {
            if (!array_key_exists($section, $named)) {
                continue;
            }
            // A null (an unset env() value, say) or an empty array is a misconfiguration, never a request for the
            // plugin's permissive defaults: fail closed, as the default server does.
            if (!is_array($named[$section]) || $named[$section] === []) {
                throw new InvalidArgumentException(sprintf(
                    '"Mcp.servers.%s.%s" must be a non-empty array; leave it out to use the default server\'s.',
                    $server,
                    $section,
                ));
            }
            $config[$section] = self::merge(self::DEFAULTS[$section], $named[$section]);
            unset($named[$section]);
        }
        $hasConfigure = is_array($named['server'] ?? null) && array_key_exists('configure', $named['server']);
        if ($hasConfigure) {
            $config['server']['configure'] = $named['server']['configure'];
            unset($named['server']['configure']);
        }

        return self::merge($config, $named);
    }

    /**
     * The entries of `Mcp.servers` whose shape is valid, and the others with what is wrong with them. One wrong
     * entry never hides the valid ones.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
     */
    private static function named(): array
    {
        $servers = Configure::read('Mcp.servers') ?? [];
        if (!is_array($servers)) {
            return [[], ['Mcp.servers' => '"Mcp.servers" must be an array of server name => config.']];
        }
        $valid = [];
        $errors = [];
        foreach ($servers as $name => $config) {
            $name = (string)$name;
            if (!is_array($config)) {
                $errors[$name] = sprintf(
                    '"Mcp.servers.%s" must be the config array of the server; "Mcp.servers" is an array of server '
                    . 'name => config.',
                    $name,
                );
            } elseif (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
                $errors[$name] = sprintf(
                    'The server name "%s" in "Mcp.servers" may only have letters, digits, "-" and "_".',
                    $name,
                );
            } elseif ($name === self::DEFAULT_SERVER) {
                $errors[$name] = '"Mcp.servers.default" is not allowed: the top-level "Mcp" config is the default '
                    . 'server.';
            } elseif (!is_string($config['route'] ?? null) || trim($config['route'], '/') === '') {
                $errors[$name] = sprintf(
                    '"Mcp.servers.%s.route" must be the path of the server, for example "/mcp/%s".',
                    $name,
                    $name,
                );
            } else {
                $valid[$name] = $config;
            }
        }

        /** @var array<string, array<string, mixed>> $valid */
        return [$valid, $errors];
    }

    /**
     * The cache config used for the discovery cache, or null when it is disabled.
     * Always null in debug mode, so new tools show up without clearing caches.
     *
     * @param string $server The server.
     * @return string|null
     */
    public static function discoveryCache(string $server = self::DEFAULT_SERVER): ?string
    {
        $name = self::get('discovery.cache', $server);
        if (Configure::read('debug') || !is_string($name) || $name === '') {
            return null;
        }

        return $name;
    }

    /**
     * The largest request body the endpoint reads, in bytes (`http.maxBodyBytes`, 4 MiB by default, the
     * SDK's own limit). The same value goes to the SDK transport and to its AuthorizationMiddleware, and
     * BodyLimitMiddleware answers a larger body with 413 before either reads it.
     *
     * @param string $server The server.
     * @return int
     * @throws \InvalidArgumentException When the setting is not a positive integer, so the endpoint fails closed.
     */
    public static function maxBodyBytes(string $server = self::DEFAULT_SERVER): int
    {
        $value = self::get('http.maxBodyBytes', $server);
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException('`Mcp.http.maxBodyBytes` must be a positive integer (bytes).');
        }

        return $value;
    }

    /**
     * Hosts the DNS-rebinding protection accepts. Defaults to the host of
     * `App.fullBaseUrl` plus localhost; the SDK default (localhost only) would
     * reject every request to a real domain.
     *
     * @param string $server The server.
     * @return list<string>
     */
    public static function allowedHosts(string $server = self::DEFAULT_SERVER): array
    {
        $hosts = self::get('http.allowedHosts', $server);
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
     * The RBAC rule (cakedc/auth, `config/permissions.php`) that lets every request reach the MCP endpoint and
     * its protected resource metadata. cakedc/users authorizes the route before the controller runs, so without
     * it an anonymous request is redirected to the login page instead of receiving the plugin's 401 and its
     * metadata. The plugin decides access itself (authentication, scopes, then the tool authorizer).
     *
     * With a user mode client in `Mcp.clients`, a second rule lets logged-in users reach its connect and callback
     * routes.
     *
     * @return list<array<string, mixed>>
     */
    public static function rbacPermissions(): array
    {
        $rules = [
            [
                'role' => '*',
                'plugin' => 'CakeDC/Mcp',
                'controller' => ['Mcp', 'ProtectedResource'],
                'action' => ['handle', 'metadata'],
                'bypassAuth' => true,
            ],
        ];
        if (ClientConfig::anyUserMode()) {
            // The sign-in routes of user mode clients: any logged-in user (the controller checks the rest).
            $rules[] = [
                'role' => '*',
                'plugin' => 'CakeDC/Mcp',
                'controller' => ['McpClient'],
                'action' => ['connect', 'callback'],
            ];
        }

        return $rules;
    }

    /**
     * Whether $request is for the endpoint of any MCP server or its protected resource metadata. Use it to skip
     * Cake's CSRF check there, and to keep other bearer-token authenticators off these paths.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return bool
     */
    public static function isEndpoint(ServerRequestInterface $request): bool
    {
        return self::serverFor($request) !== null;
    }
}
