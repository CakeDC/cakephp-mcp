<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use CakeDC\Mcp\Client\Exception\ClientException;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use Closure;
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
 * - Both: `timeout` (seconds), `tools.only` and `tools.except` (patterns), `protocolVersion`, `allowInsecure`,
 *   `allowPrivateNetwork`.
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
        'proxy' => null,
    ];

    /**
     * Keys a client config may have.
     *
     * @var list<string>
     */
    public const KEYS = [
        'url', 'token', 'headers', 'command', 'args', 'env', 'cwd', 'auth', 'timeout', 'tools', 'protocolVersion',
        'allowInsecure', 'clientId', 'clientSecret', 'scopes', 'authorizationUrl', 'tokenUrl', 'revocationUrl',
        'issuer', 'allowPrivateNetwork', 'registration', 'cache',
    ];

    /**
     * Values of `registration`: how a user mode client without a configured `clientId` gets one.
     *
     * @var list<string>
     */
    public const REGISTRATIONS = ['auto', 'metadata', 'dynamic'];

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
     * @param string|null $issuer With configured endpoints, the issuer the authorization server names itself by,
     *   compared with the `iss` of its sign-in responses; null to skip that comparison.
     * @param \Closure|null $tokenProvider A `token` given as a callable, called for each request.
     * @param bool $allowPrivateNetwork Whether the OAuth endpoints learned for this client may resolve to private,
     *   loopback or link-local addresses (plain http also needs `allowInsecure`).
     * @param string $registration `auto`, `metadata` or `dynamic`: see `REGISTRATIONS`.
     * @param string|null $cache The Cake cache config list results with cache hints are kept in; null for none.
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
        public readonly ?string $issuer = null,
        public readonly ?Closure $tokenProvider = null,
        public readonly bool $allowPrivateNetwork = false,
        public readonly string $registration = 'auto',
        public readonly ?string $cache = null,
    ) {
    }

    /**
     * The token of a `token` callable, for one request.
     *
     * @return string|null Null when `token` is not a callable.
     * @throws \CakeDC\Mcp\Client\Exception\ClientException When the callable returns no token.
     */
    public function callableToken(): ?string
    {
        if ($this->tokenProvider === null) {
            return null;
        }
        $token = ($this->tokenProvider)();
        if (!is_string($token) || trim($token) === '') {
            throw new ClientException(sprintf(
                'The callable of "Mcp.clients.%s.token" returned %s, not a token string.',
                $this->name,
                get_debug_type($token),
            ));
        }

        return $token;
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
     * `Mcp.client.proxy`: the proxy every client HTTP request goes through, or null to connect directly.
     *
     * @return string|null An `http`, `https`, `socks5` or `socks5h` URL with a host, maybe a user and password and
     *   a port, and no path.
     * @throws \InvalidArgumentException When it is set to anything else.
     */
    public static function proxy(): ?string
    {
        $proxy = Configure::read('Mcp.client.proxy');
        if ($proxy === null) {
            return null;
        }
        $parts = is_string($proxy) ? parse_url($proxy) : false;
        if (
            !is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https', 'socks5', 'socks5h'], true)
            || (string)($parts['host'] ?? '') === ''
            || !in_array($parts['path'] ?? '/', ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException(
                '"Mcp.client.proxy" must be null or a proxy URL such as "http://proxy.example.com:3128" (http, '
                . 'https, socks5 or socks5h, with no path).',
            );
        }

        return (string)$proxy;
    }

    /**
     * `Mcp.client.proxy` without its user name and password, for messages.
     *
     * @return string|null
     */
    public static function proxyForDisplay(): ?string
    {
        $proxy = self::proxy();
        if ($proxy === null) {
            return null;
        }
        $parts = parse_url($proxy);
        $port = is_array($parts) && isset($parts['port']) ? ':' . $parts['port'] : '';

        return is_array($parts) ? ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . $port : null;
    }

    /**
     * @return list<string> The configured client names whose entry has a valid shape, in order (see `errors()`).
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return bool Whether a configured client uses user mode, so the connect and callback routes are needed.
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
        [$valid, $errors] = self::scan();
        if (isset($errors[$name])) {
            throw new InvalidArgumentException($errors[$name]);
        }
        $config = $valid[$name] ?? null;
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
        $cache = self::optionalString($config, 'cache', $key);
        if ($cache !== null && Cache::getConfig($cache) === null) {
            throw new InvalidArgumentException(sprintf(
                '"%s" names the cache config "%s", which does not exist.',
                $key('cache'),
                $cache,
            ));
        }
        $registration = $config['registration'] ?? 'auto';
        if (!in_array($registration, self::REGISTRATIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" must be "auto", "metadata" or "dynamic".',
                $key('registration'),
            ));
        }
        $tokenProvider = null;
        if (array_key_exists('token', $config) && !is_string($config['token']) && is_callable($config['token'])) {
            // Called for each request, for tokens that rotate; `bin/cake mcp check` never calls it.
            $tokenProvider = Closure::fromCallable($config['token']);
        } elseif (array_key_exists('token', $config)) {
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
        $hasAuthorization = $tokenProvider !== null;
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
            $oauthKeys = [
                'clientId', 'clientSecret', 'scopes', 'authorizationUrl', 'tokenUrl', 'revocationUrl', 'issuer',
                'registration',
            ];
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
        $issuer = self::optionalString($config, 'issuer', $key);
        $configured = array_filter($endpoints, fn(?string $value): bool => $value !== null) !== [];
        $secret = self::optionalString($config, 'clientSecret', $key);
        if ($auth === self::USER && $secret !== null && !$configured && $issuer === null) {
            // The MCP server names its authorization server on every sign-in: a secret must be bound to one.
            throw new InvalidArgumentException(sprintf(
                '"%s" is required with "clientSecret" and discovery: without it, the secret would go to whatever '
                . 'authorization server the MCP server names. Set it to the issuer that gave you the client.',
                $key('issuer'),
            ));
        }
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
        foreach (array_keys($tools) as $toolKey) {
            if ($toolKey !== 'only' && $toolKey !== 'except') {
                // A misspelled deny list would otherwise allow every tool.
                throw new InvalidArgumentException(sprintf(
                    '"%s" is not a setting; "tools" takes "only" and "except".',
                    $key('tools.' . $toolKey),
                ));
            }
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
            $issuer,
            $tokenProvider,
            self::bool($config, 'allowPrivateNetwork', $key),
            $registration,
            $cache,
        );
    }

    /**
     * The entries of `Mcp.clients` that cannot be used (their shape or a setting), as entry name => what is wrong.
     * The name is `Mcp.clients` when the whole setting is not an array of client name => config. The other
     * clients keep working; `bin/cake mcp check` fails on these.
     *
     * @return array<string, string>
     */
    public static function errors(): array
    {
        [$valid, $errors] = self::scan();
        foreach (array_keys($valid) as $name) {
            try {
                self::fromConfig($name);
            } catch (InvalidArgumentException $e) {
                $errors[$name] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * @return array<string, array<string, mixed>> The entries of `Mcp.clients` whose shape is valid.
     */
    private static function all(): array
    {
        return self::scan()[0];
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>} The entries of `Mcp.clients`
     *   with a valid shape, and the others with what is wrong with them.
     */
    private static function scan(): array
    {
        $clients = Configure::read('Mcp.clients') ?? [];
        if (!is_array($clients)) {
            return [[], ['Mcp.clients' => '"Mcp.clients" must be an array of client name => config.']];
        }
        if ($clients !== [] && array_is_list($clients)) {
            return [[], ['Mcp.clients' => '"Mcp.clients" must be an array of client name => config, not a list.']];
        }
        $valid = [];
        $errors = [];
        foreach ($clients as $name => $config) {
            if (is_int($name)) {
                // PHP keeps a numeric key an int: a name like "42".
                $errors['Mcp.clients.' . $name] = sprintf(
                    '"Mcp.clients.%d": a client name must be a word, not a number.',
                    $name,
                );
                continue;
            }
            if (!is_array($config)) {
                $errors[$name] = sprintf(
                    '"Mcp.clients.%s" must be the config array of the client; "Mcp.clients" is an array of client '
                    . 'name => config.',
                    $name,
                );
            } elseif (preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
                $errors[$name] = sprintf(
                    'The client name "%s" in "Mcp.clients" may only have letters, digits, "-" and "_".',
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
