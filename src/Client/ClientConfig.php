<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Cake\Core\Configure;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use InvalidArgumentException;
use Mcp\Schema\Enum\ProtocolVersion;

/**
 * One named client of `Mcp.clients`, read and checked as a whole: a mistake fails with a message that names the key,
 * never silently (no token, no tools).
 *
 * - App mode: `url` with `token` or `headers`, or `command` with `args`, `env` and `cwd` for a local stdio server.
 * - User mode (`auth` `user`): `url`, and each user signs in with OAuth. `clientId` and `clientSecret` when the
 *   authorization server's owner gave you a client; `authorizationUrl`, `tokenUrl` and `revocationUrl` to skip
 *   discovery (they are then trusted like `url`); `scopes` to ask for.
 * - Both: `timeout` (seconds), `tools.only` and `tools.except` (patterns), `protocolVersion`, `allowInsecure`.
 */
final class ClientConfig
{
    public const APP = 'app';

    public const USER = 'user';

    /**
     * Settings shared by every client, `Mcp.client`.
     *
     * @var array<string, mixed>
     */
    public const SETTINGS = [
        'route' => '/mcp-client',
        'store' => TableConnectionStore::class,
        'encryptionKey' => null,
        'name' => 'CakePHP MCP Client',
        'version' => '1.0.0',
        'refreshLeeway' => 60,
        'maxResponseBytes' => 1024 * 1024,
        'identityAttribute' => 'identity',
    ];

    /**
     * Keys a client config may have.
     *
     * @var list<string>
     */
    public const KEYS = [
        'url', 'token', 'headers', 'command', 'args', 'env', 'cwd', 'auth', 'timeout', 'tools', 'protocolVersion',
        'allowInsecure', 'clientId', 'clientSecret', 'scopes', 'authorizationUrl', 'tokenUrl', 'revocationUrl',
    ];

    /**
     * @param string $name The client's name in `Mcp.clients`.
     * @param string|null $url The MCP server's URL, or null for a stdio client.
     * @param string|null $command The stdio server's command, or null for an HTTP client.
     * @param list<string> $args The command's arguments.
     * @param array<string, string> $env Environment variables of the stdio server, over a minimal base.
     * @param string|null $cwd The stdio server's working directory.
     * @param array<string, string> $headers Headers sent with every HTTP request, `Authorization` from `token` included.
     * @param string $auth `app` or `user`.
     * @param int $timeout Seconds for one request.
     * @param list<string> $toolsOnly Patterns of the tools allowed; empty for all.
     * @param list<string> $toolsExcept Patterns of the tools refused.
     * @param \Mcp\Schema\Enum\ProtocolVersion|null $protocolVersion The protocol version to ask for, or null for the SDK's.
     * @param bool $allowInsecure Whether configured URLs may use plain http to a host other than this machine.
     * @param string|null $clientId The OAuth client id, or null to register one.
     * @param string|null $clientSecret Its secret, if it has one.
     * @param list<string> $scopes Scopes to ask for; empty for the ones the server names.
     * @param string|null $authorizationUrl A configured authorization endpoint.
     * @param string|null $tokenUrl A configured token endpoint.
     * @param string|null $revocationUrl A configured revocation endpoint.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $url,
        public readonly ?string $command = null,
        public readonly array $args = [],
        public readonly array $env = [],
        public readonly ?string $cwd = null,
        public readonly array $headers = [],
        public readonly string $auth = self::APP,
        public readonly int $timeout = 30,
        public readonly array $toolsOnly = [],
        public readonly array $toolsExcept = [],
        public readonly ?ProtocolVersion $protocolVersion = null,
        public readonly bool $allowInsecure = false,
        public readonly ?string $clientId = null,
        public readonly ?string $clientSecret = null,
        public readonly array $scopes = [],
        public readonly ?string $authorizationUrl = null,
        public readonly ?string $tokenUrl = null,
        public readonly ?string $revocationUrl = null,
    ) {
    }

    /**
     * @return bool Whether each user signs in (`auth` `user`).
     */
    public function isUser(): bool
    {
        return $this->auth === self::USER;
    }

    /**
     * @return bool Whether the server is a local process on stdio.
     */
    public function isStdio(): bool
    {
        return $this->command !== null;
    }

    /**
     * @return bool Whether the OAuth endpoints come from the config rather than from discovery.
     */
    public function hasConfiguredEndpoints(): bool
    {
        return $this->authorizationUrl !== null;
    }

    /**
     * @return \CakeDC\Mcp\Client\ToolFilter The client's `tools.only` and `tools.except`.
     */
    public function toolFilter(): ToolFilter
    {
        return new ToolFilter($this->toolsOnly, $this->toolsExcept);
    }

    /**
     * A setting shared by every client (`Mcp.client`), with its default.
     *
     * @param string $key A key of SETTINGS.
     * @return mixed
     */
    public static function setting(string $key): mixed
    {
        $value = Configure::read('Mcp.client.' . $key);

        return $value ?? self::SETTINGS[$key] ?? null;
    }

    /**
     * @return list<string> The configured client names, in order.
     * @throws \InvalidArgumentException When `Mcp.clients` is not an array of name => config.
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return bool Whether a configured client uses user mode, so the connect and callback routes are needed.
     * @throws \InvalidArgumentException When `Mcp.clients` is not an array of name => config.
     */
    public static function anyUserMode(): bool
    {
        foreach (self::all() as $config) {
            if (($config['auth'] ?? null) === self::USER) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $name A key of `Mcp.clients`.
     * @return self
     * @throws \InvalidArgumentException When it is not configured or its config is invalid.
     */
    public static function fromConfig(string $name): self
    {
        $config = self::all()[$name] ?? null;
        if ($config === null) {
            throw new InvalidArgumentException(sprintf('"Mcp.clients.%s" is not configured.', $name));
        }
        $key = fn(string $child): string => sprintf('Mcp.clients.%s.%s', $name, $child);
        $unknown = array_diff(array_keys($config), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a client setting; the settings are %s.',
                $key((string)reset($unknown)),
                implode(', ', self::KEYS),
            ));
        }

        $auth = $config['auth'] ?? self::APP;
        if ($auth !== self::APP && $auth !== self::USER) {
            throw new InvalidArgumentException(sprintf('"%s" must be "app" or "user".', $key('auth')));
        }
        $allowInsecure = self::bool($config, 'allowInsecure', $key);
        $url = self::optionalString($config, 'url', $key);
        $command = self::optionalString($config, 'command', $key);
        if (($url === null) === ($command === null)) {
            throw new InvalidArgumentException(sprintf(
                $url === null
                    ? '"Mcp.clients.%s" needs a "url" (an HTTP server) or a "command" (a local stdio server).'
                    : '"Mcp.clients.%s" has a "url" and a "command"; set one, not both.',
                $name,
            ));
        }
        if ($url !== null) {
            ServerUrlPolicy::check($url, $allowInsecure, $key('url'));
        }

        $headers = $config['headers'] ?? [];
        if (!is_array($headers)) {
            throw new InvalidArgumentException(sprintf('"%s" must be an array of name => value.', $key('headers')));
        }
        foreach ($headers as $header => $value) {
            if (!is_string($header) || !is_string($value)) {
                throw new InvalidArgumentException(sprintf('"%s" must be an array of name => value.', $key('headers')));
            }
        }
        if (array_key_exists('token', $config)) {
            $token = $config['token'];
            if (!is_string($token) || trim($token) === '') {
                // Often an env() that is not set: sending no credentials would fail later and less clearly.
                throw new InvalidArgumentException(sprintf(
                    '"%s" is empty; set it, or remove it if the server needs no token.',
                    $key('token'),
                ));
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $hasAuthorization = false;
        foreach (array_keys($headers) as $header) {
            $hasAuthorization = $hasAuthorization || strcasecmp($header, 'Authorization') === 0;
        }

        if ($auth === self::USER) {
            if ($url === null) {
                throw new InvalidArgumentException(sprintf('"Mcp.clients.%s": user mode needs a "url".', $name));
            }
            if ($hasAuthorization) {
                throw new InvalidArgumentException(sprintf(
                    '"Mcp.clients.%s": user mode signs each user in, so it takes no "token" or Authorization header.',
                    $name,
                ));
            }
        }

        if ($auth === self::APP) {
            $oauthKeys = ['clientId', 'clientSecret', 'scopes', 'authorizationUrl', 'tokenUrl', 'revocationUrl'];
            foreach ($oauthKeys as $oauth) {
                if (array_key_exists($oauth, $config)) {
                    throw new InvalidArgumentException(sprintf(
                        '"%s" is only for user mode; add "auth" => "user" if each user should sign in.',
                        $key($oauth),
                    ));
                }
            }
        }

        $endpoints = [];
        foreach (['authorizationUrl', 'tokenUrl', 'revocationUrl'] as $endpoint) {
            $endpoints[$endpoint] = self::optionalString($config, $endpoint, $key);
            if ($endpoints[$endpoint] !== null) {
                ServerUrlPolicy::check($endpoints[$endpoint], $allowInsecure, $key($endpoint));
            }
        }
        $clientId = self::optionalString($config, 'clientId', $key);
        if ($clientId === null && self::optionalString($config, 'clientSecret', $key) !== null) {
            throw new InvalidArgumentException(sprintf('"%s" needs "clientId".', $key('clientSecret')));
        }
        $configured = array_filter($endpoints, fn(?string $value): bool => $value !== null) !== [];
        if (
            $configured
            && ($endpoints['authorizationUrl'] === null || $endpoints['tokenUrl'] === null || $clientId === null)
        ) {
            throw new InvalidArgumentException(sprintf(
                '"Mcp.clients.%s" needs "authorizationUrl", "tokenUrl" and "clientId" together to skip discovery.',
                $name,
            ));
        }

        $timeout = $config['timeout'] ?? 30;
        if (!is_int($timeout) || $timeout < 1) {
            throw new InvalidArgumentException(sprintf('"%s" must be a positive number of seconds.', $key('timeout')));
        }
        $version = $config['protocolVersion'] ?? null;
        $protocolVersion = null;
        if ($version !== null) {
            $protocolVersion = is_string($version) ? ProtocolVersion::tryFrom($version) : null;
            if ($protocolVersion === null) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" must be one of %s.',
                    $key('protocolVersion'),
                    implode(', ', array_map(fn(ProtocolVersion $v): string => $v->value, ProtocolVersion::cases())),
                ));
            }
        }
        $tools = $config['tools'] ?? [];
        if (!is_array($tools)) {
            throw new InvalidArgumentException(
                sprintf('"%s" must be an array with "only" and "except".', $key('tools')),
            );
        }

        $env = $config['env'] ?? [];
        if (!is_array($env)) {
            throw new InvalidArgumentException(sprintf('"%s" must be an array of name => value.', $key('env')));
        }
        foreach ($env as $variable => $value) {
            if (!is_string($variable) || !is_string($value)) {
                throw new InvalidArgumentException(sprintf('"%s" must be an array of name => value.', $key('env')));
            }
        }

        /** @var array<string, string> $headers */
        /** @var array<string, string> $env */
        return new self(
            $name,
            $url,
            $command,
            self::strings($config['args'] ?? [], $key('args')),
            $env,
            self::optionalString($config, 'cwd', $key),
            $headers,
            $auth,
            $timeout,
            self::strings($tools['only'] ?? [], $key('tools.only')),
            self::strings($tools['except'] ?? [], $key('tools.except')),
            $protocolVersion,
            $allowInsecure,
            $clientId,
            self::optionalString($config, 'clientSecret', $key),
            self::strings($config['scopes'] ?? [], $key('scopes')),
            $endpoints['authorizationUrl'],
            $endpoints['tokenUrl'],
            $endpoints['revocationUrl'],
        );
    }

    /**
     * @return array<string, array<string, mixed>> `Mcp.clients`, checked for its shape.
     * @throws \InvalidArgumentException
     */
    private static function all(): array
    {
        $clients = Configure::read('Mcp.clients') ?? [];
        if (!is_array($clients)) {
            throw new InvalidArgumentException('"Mcp.clients" must be an array of client name => config.');
        }
        foreach ($clients as $name => $config) {
            if (!is_string($name) || !is_array($config)) {
                throw new InvalidArgumentException('"Mcp.clients" must be an array of client name => config.');
            }
            if (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'The client name "%s" in "Mcp.clients" may only have letters, digits, "-" and "_".',
                    $name,
                ));
            }
        }

        /** @var array<string, array<string, mixed>> $clients */
        return $clients;
    }

    /**
     * @param array<string, mixed> $config The client config.
     * @param string $child The key.
     * @param callable(string): string $key Builds the full key for a message.
     * @return string|null The value, or null when it is not set.
     */
    private static function optionalString(array $config, string $child, callable $key): ?string
    {
        $value = $config[$child] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('"%s" must be a non-empty string.', $key($child)));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config The client config.
     * @param string $child The key.
     * @param callable(string): string $key Builds the full key for a message.
     * @return bool
     */
    private static function bool(array $config, string $child, callable $key): bool
    {
        $value = $config[$child] ?? false;
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('"%s" must be true or false.', $key($child)));
        }

        return $value;
    }

    /**
     * @param mixed $value A configured list.
     * @param string $key The full key, for the message.
     * @return list<string>
     */
    private static function strings(mixed $value, string $key): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('"%s" must be a list of strings.', $key));
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidArgumentException(sprintf('"%s" must be a list of strings.', $key));
            }
        }

        return $value;
    }
}
