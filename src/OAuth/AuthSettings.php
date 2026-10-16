<?php
declare(strict_types=1);

namespace CakeDC\Mcp\OAuth;

use Cake\Core\Configure;
use CakeDC\Mcp\McpConfig;
use Closure;
use InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;

/**
 * The validated `Mcp.auth` configuration: how the MCP endpoint authenticates its callers.
 *
 * Unlike the rest of `Mcp`, unknown keys are rejected: a typo in a security setting must not fail open.
 */
final class AuthSettings
{
    public const HOST = 'host';
    public const OAUTH = 'oauth';
    public const EXTERNAL = 'external';

    /**
     * @param string $mode One of the mode constants.
     * @param string $resource The canonical resource URL of the MCP endpoint; empty in host mode.
     * @param string|null $resourceName Shown in the protected resource metadata.
     * @param string|null $resourceDocumentation Documentation URL in the metadata.
     * @param list<string> $defaultScopes Scopes every MCP request needs.
     * @param array<string, list<string>> $methodScopes Scopes per JSON-RPC method.
     * @param array<string, list<string>> $toolScopes Scopes per tool.
     * @param array<string, list<string>> $implies Scope hierarchy: a key also grants its scopes.
     * @param \Closure|null $identity `fn(AccessToken $token, mixed $identity): mixed`.
     * @param \CakeDC\Mcp\OAuth\ExternalSettings|null $external The external mode settings.
     */
    private function __construct(
        public readonly string $mode,
        public readonly string $resource,
        public readonly ?string $resourceName,
        public readonly ?string $resourceDocumentation,
        public readonly array $defaultScopes,
        public readonly array $methodScopes,
        public readonly array $toolScopes,
        public readonly array $implies,
        public readonly ?Closure $identity,
        public readonly ?ExternalSettings $external,
    ) {
    }

    /**
     * Reads and validates `Mcp.auth`.
     *
     * @return self
     * @throws \InvalidArgumentException For an invalid setting, named in the message.
     */
    public static function fromConfig(): self
    {
        $auth = McpConfig::get('auth');
        $auth = is_array($auth) ? $auth : [];
        $defaults = McpConfig::DEFAULTS['auth'];
        self::assertKeys($auth, $defaults, 'Mcp.auth');

        $mode = $auth['mode'] ?? self::HOST;
        if (!in_array($mode, [self::HOST, self::OAUTH, self::EXTERNAL], true)) {
            throw new InvalidArgumentException(sprintf(
                '"Mcp.auth.mode" must be "host", "oauth" or "external", got %s.',
                is_scalar($mode) ? '"' . $mode . '"' : get_debug_type($mode),
            ));
        }
        $scopes = is_array($auth['scopes'] ?? null) ? $auth['scopes'] : [];
        self::assertKeys($scopes, $defaults['scopes'], 'Mcp.auth.scopes');
        $external = is_array($auth['external'] ?? null) ? $auth['external'] : [];
        self::assertKeys($external, $defaults['external'], 'Mcp.auth.external');

        $identity = $auth['identity'] ?? null;
        if ($identity !== null && !is_callable($identity)) {
            throw new InvalidArgumentException('"Mcp.auth.identity" must be a callable or null.');
        }
        $resource = $mode === self::HOST ? '' : self::resource($auth['resource'] ?? null);

        return new self(
            $mode,
            $resource,
            self::optionalString($auth['resourceName'] ?? null, 'Mcp.auth.resourceName'),
            self::optionalString($auth['resourceDocumentation'] ?? null, 'Mcp.auth.resourceDocumentation'),
            self::scopeList($scopes['default'] ?? [], 'Mcp.auth.scopes.default'),
            self::scopeMap($scopes['methods'] ?? [], 'Mcp.auth.scopes.methods'),
            self::scopeMap($scopes['tools'] ?? [], 'Mcp.auth.scopes.tools'),
            self::scopeMap($scopes['implies'] ?? [], 'Mcp.auth.scopes.implies'),
            $identity === null ? null : Closure::fromCallable($identity),
            $mode === self::EXTERNAL ? ExternalSettings::fromArray($external, $resource) : null,
        );
    }

    /**
     * The SDK scope policy, or null when no request needs a scope.
     *
     * @return \Mcp\Server\Transport\Http\OAuth\ScopePolicy|null
     */
    public function scopePolicy(): ?ScopePolicy
    {
        if ($this->defaultScopes === [] && $this->methodScopes === [] && $this->toolScopes === []) {
            return null;
        }

        return new ScopePolicy($this->defaultScopes, $this->methodScopes, $this->toolScopes, $this->implies);
    }

    /**
     * Every scope the settings name, once.
     *
     * @return list<string>
     */
    public function configuredScopes(): array
    {
        $scopes = $this->defaultScopes;
        foreach ([$this->methodScopes, $this->toolScopes, $this->implies] as $map) {
            foreach ($map as $values) {
                array_push($scopes, ...$values);
            }
        }
        foreach (array_keys($this->implies) as $scope) {
            $scopes[] = (string)$scope;
        }

        return array_values(array_unique($scopes));
    }

    /**
     * $url with a lowercase scheme and host, without the default port or a trailing slash. Only https,
     * or plain http for a loopback host; no fragment and no credentials.
     *
     * @param string $url The URL.
     * @param string $key The setting it comes from, for the error message.
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function canonicalUrl(string $url, string $key): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(sprintf('%s must be an absolute URL, got "%s".', $key, $url));
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $loopback = in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            throw new InvalidArgumentException(sprintf(
                '%s must use https (plain http only for localhost), got "%s".',
                $key,
                $url,
            ));
        }
        if (isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(sprintf(
                '%s must not contain a fragment or credentials, got "%s".',
                $key,
                $url,
            ));
        }
        $port = $parts['port'] ?? null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }

        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port)
            . rtrim($parts['path'] ?? '', '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * The canonical resource URL: `Mcp.auth.resource`, or the base URL, base path and `Mcp.route`.
     *
     * @param mixed $configured `Mcp.auth.resource`.
     * @return string
     */
    private static function resource(mixed $configured): string
    {
        if ($configured !== null) {
            if (!is_string($configured)) {
                throw new InvalidArgumentException('"Mcp.auth.resource" must be a URL or null.');
            }

            return self::canonicalUrl($configured, '"Mcp.auth.resource"');
        }
        $base = Configure::read('App.base');
        $route = McpConfig::get('route');

        return self::canonicalUrl(
            rtrim((string)Configure::read('App.fullBaseUrl'), '/')
            . (is_string($base) ? rtrim($base, '/') : '')
            . (is_string($route) ? $route : ''),
            '"Mcp.auth.resource" (derived from App.fullBaseUrl, App.base and Mcp.route)',
        );
    }

    /**
     * @param array<mixed> $values The values.
     * @param array<mixed> $defaults Their defaults, whose keys are the known ones.
     * @param string $path The Configure path, for the error message.
     * @return void
     */
    private static function assertKeys(array $values, array $defaults, string $path): void
    {
        foreach (array_keys($values) as $key) {
            if (!array_key_exists($key, $defaults)) {
                throw new InvalidArgumentException(sprintf('Unknown key "%s.%s".', $path, $key));
            }
        }
    }

    /**
     * @param mixed $value The value.
     * @param string $key The Configure path.
     * @return string|null
     */
    private static function optionalString(mixed $value, string $key): ?string
    {
        if ($value === null || (is_string($value) && $value !== '')) {
            return $value;
        }

        throw new InvalidArgumentException(sprintf('"%s" must be a non-empty string or null.', $key));
    }

    /**
     * @param mixed $value The value.
     * @param string $key The Configure path.
     * @return list<string>
     */
    private static function scopeList(mixed $value, string $key): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('"%s" must be a list of scope names.', $key));
        }
        foreach ($value as $scope) {
            if (!is_string($scope) || preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/', $scope) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" must be a list of scope names, %s is not one.',
                    $key,
                    is_string($scope) ? '"' . $scope . '"' : get_debug_type($scope),
                ));
            }
        }

        return array_values(array_unique($value));
    }

    /**
     * @param mixed $value The value.
     * @param string $key The Configure path.
     * @return array<string, list<string>>
     */
    private static function scopeMap(mixed $value, string $key): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('"%s" must map names to lists of scopes.', $key));
        }
        $map = [];
        foreach ($value as $name => $scopes) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException(sprintf('"%s" must map names to lists of scopes.', $key));
            }
            $map[$name] = self::scopeList($scopes, $key . '.' . $name);
        }

        return $map;
    }
}
