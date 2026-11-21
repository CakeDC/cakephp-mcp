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
     * The values a route parameter takes: a slug.
     */
    public const ROUTE_VALUE = '[A-Za-z0-9_-]+';

    /**
     * Route parameter names Cake routing or the plugin read, which a route of a server cannot use.
     *
     * @var list<string>
     */
    public const RESERVED_PARAMS = ['server', 'plugin', 'controller', 'action', 'prefix', 'pass'];

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
            'icons' => null,
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
     * server's scopes, rules or tools. `server.configure` follows the same rule. `identity` is never inherited: a
     * named server that does not set it gets the plugin's defaults (an identity is required).
     *
     * @var list<string>
     */
    /**
     * The plugin's controllers that answer at a server's route and at its metadata route.
     */
    private const ENDPOINT_CONTROLLERS = ['Mcp', 'ProtectedResource'];

    private const REPLACED_SECTIONS = ['auth', 'authorization', 'discovery', 'identity'];

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
        // Once the router has run, its answer: a fixed route of the application wins over a server's pattern, and
        // a route the application connected itself to the plugin's controller is the default server's.
        $params = $request->getAttribute('params');
        if (is_array($params) && isset($params['controller'])) {
            $ours = ($params['plugin'] ?? null) === 'CakeDC/Mcp'
                && in_array($params['controller'], self::ENDPOINT_CONTROLLERS, true);
            if (!$ours) {
                return null;
            }
            $server = $params['server'] ?? null;
            $server = is_string($server) && $server !== '' ? $server : self::DEFAULT_SERVER;

            return in_array($server, self::servers(), true) ? $server : null;
        }
        $path = self::requestPath($request);
        foreach (self::servers() as $server) {
            $route = self::get('route', $server);
            if (!is_string($route) || trim($route, '/') === '') {
                continue;
            }
            $metadata = self::METADATA_PREFIX . '/' . trim($route, '/');
            if (self::routeValues($route, $path) !== null || self::routeValues($metadata, $path) !== null) {
                return $server;
            }
        }

        return null;
    }

    /**
     * The path of $request as the router matches it: each segment percent-decoded, an encoded slash kept inside its
     * segment, without trailing slashes.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return string
     * @internal
     */
    public static function requestPath(ServerRequestInterface $request): string
    {
        $path = $request instanceof ServerRequest ? $request->getPath() : $request->getUri()->getPath();
        if (str_contains($path, '%')) {
            $path = implode('/', array_map(
                fn(string $part): string => str_replace('/', '%2f', urldecode($part)),
                explode('/', $path),
            ));
        }

        return '/' . trim($path, '/');
    }

    /**
     * The parameters of a route such as `/mcp/{tenant}`, in order.
     *
     * @param mixed $route A route.
     * @return list<string>
     */
    public static function routeParams(mixed $route): array
    {
        if (!is_string($route)) {
            return [];
        }
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $route, $matches);

        return $matches[1];
    }

    /**
     * What is wrong with the parameters of a named server's route, or null. A parameter is a whole path segment,
     * `{name}` with a name of letters, digits and `_`, each name once; its values are slugs (letters, digits, `-`
     * and `_`).
     *
     * @param string $route The route.
     * @return string|null
     */
    public static function routeError(string $route): ?string
    {
        $names = [];
        foreach (explode('/', trim($route, '/')) as $segment) {
            if (!str_contains($segment, '{') && !str_contains($segment, '}')) {
                continue;
            }
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $match) !== 1) {
                return sprintf(
                    'the segment "%s" is not a parameter: write a whole segment as {name}, with letters, digits '
                    . 'and "_"',
                    $segment,
                );
            }
            if (in_array($match[1], $names, true)) {
                return sprintf('the parameter {%s} is there twice', $match[1]);
            }
            // Cake would put the value in that routing key, so a request could pick another server or controller.
            if (in_array($match[1], self::RESERVED_PARAMS, true) || str_starts_with($match[1], '_')) {
                return sprintf('{%s} is a name Cake routing uses: name the parameter otherwise', $match[1]);
            }
            $names[] = $match[1];
        }

        return null;
    }

    /**
     * The values of $route's parameters in $path, or null when $path is not that route.
     *
     * @param string $route A route, maybe with parameters.
     * @param string $path A request path.
     * @return array<string, string>|null
     */
    public static function routeValues(string $route, string $path): ?array
    {
        $route = '/' . trim($route, '/');
        $path = '/' . trim($path, '/');
        $params = self::routeParams($route);
        if ($params === []) {
            return $route === $path ? [] : null;
        }
        $segments = [];
        foreach (explode('/', trim($route, '/')) as $segment) {
            $segments[] = preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $param) === 1
                ? '(?P<' . $param[1] . '>' . self::ROUTE_VALUE . ')'
                : preg_quote($segment, '#');
        }
        $regex = '#^/' . implode('/', $segments) . '$#';
        if (preg_match($regex, $path, $match) !== 1) {
            return null;
        }
        $values = [];
        foreach ($params as $name) {
            $values[$name] = (string)$match[$name];
        }

        return $values;
    }

    /**
     * $template (a route, a resource URL, an audience) with its `{name}` parameters replaced by $values.
     *
     * @param string $template The template.
     * @param array<string, string> $values Parameter name => value.
     * @return string
     */
    public static function withRouteValues(string $template, array $values): string
    {
        $pairs = [];
        foreach ($values as $name => $value) {
            $pairs['{' . $name . '}'] = $value;
        }

        return strtr($template, $pairs);
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
        // A typo in a section name would be ignored, and the server would run with what it meant to replace.
        foreach (array_keys($named) as $key) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                throw new InvalidArgumentException(sprintf(
                    '"Mcp.servers.%s.%s" is not a server setting; the settings are %s.',
                    $server,
                    $key,
                    implode(', ', array_keys(self::DEFAULTS)),
                ));
            }
        }
        if (!array_key_exists('auth', $named)) {
            // A server with no HTTP route (served over stdio only) takes no tokens: nothing to inherit.
            $config['auth'] = $named['route'] === false
                ? self::DEFAULTS['auth']
                : self::inheritedAuth($config['auth'], $default['route'], (string)$named['route'], $server);
        }
        if (!array_key_exists('identity', $named)) {
            // Never the default server's: a public default server must not make a named one public.
            $config['identity'] = self::DEFAULTS['identity'];
        }
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
            self::assertSectionKeys($server, $section, $named[$section]);
            $config[$section] = self::merge(self::DEFAULTS[$section], $named[$section]);
            unset($named[$section]);
        }
        // Replaced, not merged: `[]` or `null` must drop the default server's hook and cache hints.
        foreach (['configure', 'cache'] as $key) {
            if (is_array($named['server'] ?? null) && array_key_exists($key, $named['server'])) {
                $config['server'][$key] = $named['server'][$key] === [] ? null : $named['server'][$key];
                unset($named['server'][$key]);
            }
        }

        $config = self::merge($config, $named);
        self::assertBindsRouteValues($server, $config);

        return $config;
    }

    /**
     * On a route with parameters, a token is bound to one value only when what it is checked against, a configured
     * `auth.resource` or external `audience`, holds every parameter. Without one the endpoint would accept a token
     * for `/mcp/acme` on `/mcp/globex`: fail closed. Host mode checks no token, and a resource or audience left out
     * is derived from the request, so neither is refused (`bin/cake mcp check` warns about host mode).
     *
     * @param string $server The named server.
     * @param array<string, mixed> $config Its resolved config.
     * @return void
     * @throws \InvalidArgumentException When a configured resource or audience leaves a parameter out.
     */
    private static function assertBindsRouteValues(string $server, array $config): void
    {
        $params = self::routeParams($config['route'] ?? null);
        $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];
        $mode = $auth['mode'] ?? null;
        if ($params === [] || $mode === null || $mode === 'host') {
            return;
        }
        $bound = [];
        if (is_string($auth['resource'] ?? null) && $auth['resource'] !== '') {
            $bound['auth.resource'] = [$auth['resource']];
        }
        $external = is_array($auth['external'] ?? null) ? $auth['external'] : [];
        if ($mode === 'external' && ($external['audience'] ?? null) !== null) {
            $bound['auth.external.audience'] = array_filter((array)$external['audience'], 'is_string');
        }
        foreach ($bound as $key => $values) {
            foreach ($values as $value) {
                $missing = array_values(array_diff($params, self::routeParams($value)));
                if ($missing === []) {
                    continue;
                }
                throw new InvalidArgumentException(sprintf(
                    '"Mcp.servers.%s.%s" is "%s", which has no {%s}: a token for one value of %s would be accepted '
                    . 'for another. Put {%s} in it, or leave auth.resource and external.audience out to derive them '
                    . 'from the request.',
                    $server,
                    $key,
                    $value,
                    $missing[0],
                    $missing[0],
                    implode('}, {', $missing),
                ));
            }
        }
    }

    /**
     * A replaced section starts again from the plugin's defaults, which are the permissive ones: the key that
     * decides how open the server is must be written, and an unknown key (a typo) fails.
     *
     * @param string $server The named server.
     * @param string $section A key of REPLACED_SECTIONS.
     * @param array<mixed> $values What the named server wrote for it.
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function assertSectionKeys(string $server, string $section, array $values): void
    {
        $required = ['auth' => 'mode', 'authorization' => 'authorizer'][$section] ?? null;
        if ($required !== null && !array_key_exists($required, $values)) {
            throw new InvalidArgumentException(sprintf(
                '"Mcp.servers.%s.%s.%s" must be set: a named server\'s "%s" starts again from the plugin\'s '
                . 'defaults, so without it the server would use the default %s.',
                $server,
                $section,
                $required,
                $section,
                $section === 'auth' ? 'host mode, where tokens and scopes are not checked' : 'AllowAllToolAuthorizer',
            ));
        }
        if ($section !== 'authorization') {
            // `auth` is checked key by key when the endpoint reads it; `discovery` and `identity` open nothing.
            return;
        }
        foreach (array_keys($values) as $key) {
            if (!array_key_exists($key, self::DEFAULTS[$section])) {
                throw new InvalidArgumentException(sprintf(
                    '"Mcp.servers.%s.%s.%s" is not a setting of "%s"; the settings are %s.',
                    $server,
                    $section,
                    $key,
                    $section,
                    implode(', ', array_keys(self::DEFAULTS[$section])),
                ));
            }
        }
    }

    /**
     * The default server's `auth` as a named server that does not write its own inherits it: with its own
     * resource, so neither server accepts the other's tokens.
     *
     * @param mixed $auth The default server's resolved `auth`.
     * @param mixed $defaultRoute The default server's route.
     * @param string $route The named server's route.
     * @param string $server The named server.
     * @return mixed
     * @throws \InvalidArgumentException When the inherited auth names an external audience, which cannot be
     *   derived for another server.
     */
    private static function inheritedAuth(mixed $auth, mixed $defaultRoute, string $route, string $server): mixed
    {
        if (!is_array($auth)) {
            return $auth;
        }
        $audience = is_array($auth['external'] ?? null) ? $auth['external']['audience'] ?? null : null;
        if (($auth['mode'] ?? null) === 'external' && $audience !== null) {
            throw new InvalidArgumentException(sprintf(
                '"Mcp.servers.%s.auth" must be set: the default server\'s "Mcp.auth.external.audience" cannot be '
                . 'shared, or this server would accept the default server\'s tokens. Give it its own "auth" with '
                . 'its own audience.',
                $server,
            ));
        }
        if (is_string($auth['resource'] ?? null) && $auth['resource'] !== '') {
            $auth['resource'] = self::resourceAt($auth['resource'], $defaultRoute, $route);
        }

        return $auth;
    }

    /**
     * $resource with its path moved from the default server's route to $route. A path prefix before the default
     * route (a proxy's, say) stays; a path that does not end with the default route is replaced whole.
     *
     * @param string $resource The default server's `auth.resource`.
     * @param mixed $defaultRoute The default server's route.
     * @param string $route The named server's route.
     * @return string
     */
    private static function resourceAt(string $resource, mixed $defaultRoute, string $route): string
    {
        $parts = parse_url($resource);
        if (
            $parts === false || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            // Left as written: the endpoint refuses it with the same message as the default server's.
            return $resource;
        }
        $path = rtrim($parts['path'] ?? '', '/');
        $prefix = '';
        if (is_string($defaultRoute) && trim($defaultRoute, '/') !== '') {
            $defaultPath = '/' . trim($defaultRoute, '/');
            if (str_ends_with($path, $defaultPath)) {
                $prefix = substr($path, 0, -strlen($defaultPath));
            }
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . $prefix . '/' . trim($route, '/');
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
            if (is_int($name)) {
                // PHP keeps a numeric key an int: a list written by mistake, or a name like "2024".
                $errors['Mcp.servers.' . $name] = sprintf(
                    '"Mcp.servers.%d": a server name must be a word, not a number; "Mcp.servers" is an array of '
                    . 'server name => config, not a list.',
                    $name,
                );
                continue;
            }
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
            } elseif (
                ($config['route'] ?? null) !== false
                && (!is_string($config['route'] ?? null) || trim($config['route'], '/') === '')
            ) {
                $errors[$name] = sprintf(
                    '"Mcp.servers.%s.route" must be the path of the server, for example "/mcp/%s", or false for a '
                    . 'server served over stdio only.',
                    $name,
                    $name,
                );
            } elseif (is_string($config['route']) && self::routeError($config['route']) !== null) {
                $errors[$name] = sprintf(
                    '"Mcp.servers.%s.route": %s.',
                    $name,
                    (string)self::routeError($config['route']),
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
     * routes, and a third lets anyone fetch its Client ID Metadata Document (`client.json`).
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
            // Their Client ID Metadata Document, which authorization servers fetch without a session.
            $rules[] = [
                'role' => '*',
                'plugin' => 'CakeDC/Mcp',
                'controller' => ['McpClient'],
                'action' => ['metadata'],
                'bypassAuth' => true,
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
