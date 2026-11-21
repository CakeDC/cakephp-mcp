<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\OAuth;

use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\Http\HttpRequest;
use CakeDC\Mcp\Client\Http\OutboundHttp;

/**
 * Finds how to sign in to a user mode client's server (MCP authorization): an unauthenticated probe of the server,
 * its Protected Resource Metadata (RFC 9728), then its authorization server's metadata (RFC 8414, or OpenID Connect
 * Discovery). With `authorizationUrl`, `tokenUrl` and `clientId` in the config, nothing is fetched.
 *
 * Provenance is what this class is for. The issuer is the resource metadata's first `authorization_servers` entry,
 * and its document is used only when its `issuer` equals it exactly, so a server cannot name a real issuer while
 * pointing the endpoints at itself (mix-up). The resource metadata's `resource` must be the server's URL or, when
 * found at a well-known URL, a path prefix of it, so a server cannot borrow another one's metadata.
 *
 * Trust: the probe and the well-known URLs built from the configured `url` are trusted; the metadata URL a
 * challenge names, the issuer and every endpoint go through the guard, and the endpoints are checked as soon as
 * they are read.
 *
 * Adapted from ZuluBrain's Discovery.
 */
final class Discovery
{
    private const PROTECTED_RESOURCE = '/.well-known/oauth-protected-resource';

    private const OAUTH_SERVER = '/.well-known/oauth-authorization-server';

    private const OPENID = '/.well-known/openid-configuration';

    /**
     * An RFC 6749 scope token.
     */
    private const SCOPE_TOKEN = '/\A[\x21\x23-\x5B\x5D-\x7E]+\z/';

    /**
     * @param \CakeDC\Mcp\Client\Http\OutboundHttp $http Every request goes through it.
     * @param \CakeDC\Mcp\Client\ClientConfig $config The user mode client.
     */
    public function __construct(private OutboundHttp $http, private ClientConfig $config)
    {
    }

    /**
     * @return \CakeDC\Mcp\Client\OAuth\AuthServer
     * @throws \CakeDC\Mcp\Client\OAuth\OAuthException When the server's metadata is missing or not usable.
     * @throws \CakeDC\Mcp\Client\Http\BlockedUrlException When it names an address the client does not call.
     * @throws \CakeDC\Mcp\Client\Exception\ClientException For a network failure.
     */
    public function discover(): AuthServer
    {
        $config = $this->config;
        $server = (string)$config->url;
        if ($config->hasConfiguredEndpoints()) {
            $parts = (array)parse_url((string)$config->tokenUrl);

            return new AuthServer(
                $config->issuer ?? (string)($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
                    . (isset($parts['port']) ? ':' . $parts['port'] : ''),
                (string)$config->authorizationUrl,
                (string)$config->tokenUrl,
                null,
                $config->revocationUrl,
                $config->scopes,
                $server,
                false,
                [],
                true,
            );
        }

        $challenge = $this->probe($server);
        $metadataUrl = $challenge['resource_metadata'] ?? null;
        if ($metadataUrl !== null && self::sameOrigin($metadataUrl, $server)) {
            // On the origin of the configured url: trusted like it (an internal or localhost server), but named by
            // the server, so its resource must be this server exactly.
            $resourceMetadata = $this->firstDocument([$metadataUrl], true);
            $prefixAllowed = false;
        } elseif ($metadataUrl !== null) {
            // Named by the server: learned, so through the guard, and its resource must be this server exactly.
            $resourceMetadata = $this->firstDocument([$this->learnedUrl($metadataUrl)], false);
            $prefixAllowed = false;
        } else {
            $resourceMetadata = $this->firstDocument(self::protectedResourceUrls($server), true);
            $prefixAllowed = true;
        }
        if ($resourceMetadata === null) {
            throw new OAuthException(
                sprintf('The server of "%s" does not publish how to sign in to it.', $config->name),
            );
        }
        $resource = $resourceMetadata['resource'] ?? null;
        if (!is_string($resource) || $resource === '') {
            throw self::unusable($config->name);
        }
        if (!self::resourceMatches($resource, $server, $prefixAllowed)) {
            throw new OAuthException(sprintf(
                'The server of "%s" publishes sign-in details that belong to another server.',
                $config->name,
            ));
        }
        $servers = $resourceMetadata['authorization_servers'] ?? null;
        $servers = is_array($servers) && array_is_list($servers) ? $servers : [];
        $issuer = $servers[0] ?? null;
        if (!is_string($issuer) || $issuer === '') {
            throw new OAuthException(sprintf('The server of "%s" names no authorization server.', $config->name));
        }
        if ($config->issuer !== null) {
            // The configured issuer pins the authorization server: a client id and secret are bound to it.
            if (!in_array($config->issuer, $servers, true)) {
                throw new OAuthException(sprintf(
                    'The server of "%s" names the authorization server %s, but "Mcp.clients.%s.issuer" is %s: '
                    . 'the client is not signed in anywhere else.',
                    $config->name,
                    $issuer,
                    $config->name,
                    $config->issuer,
                ));
            }
            $issuer = $config->issuer;
        }

        $document = $this->firstDocument($this->authorizationServerUrls($issuer), false);
        if ($document === null) {
            throw new OAuthException(sprintf(
                'The authorization server of "%s" does not publish its details.',
                $config->name,
            ));
        }
        // Simple string comparison: no case folding, no trailing slash, no default port (RFC 8414 3.3).
        if (($document['issuer'] ?? null) !== $issuer) {
            throw new OAuthException(sprintf(
                'The authorization server of "%s" names a different issuer than the one the server gave.',
                $config->name,
            ));
        }
        $methods = $document['code_challenge_methods_supported'] ?? null;
        if (!is_array($methods) || !in_array('S256', $methods, true)) {
            throw new OAuthException(sprintf(
                'The authorization server of "%s" does not support PKCE with S256, which the MCP client requires.',
                $config->name,
            ));
        }
        $tokenUrl = $this->learnedEndpoint($document['token_endpoint'] ?? null);
        $registrationUrl = $this->optionalEndpoint($document['registration_endpoint'] ?? null);
        $revocationUrl = $this->optionalEndpoint($document['revocation_endpoint'] ?? null);
        $authMethods = $document['token_endpoint_auth_methods_supported'] ?? null;
        $challenged = $challenge['scope'] ?? null;
        $scopes = $config->scopes;
        if ($scopes === []) {
            $scopes = $challenged !== null && trim($challenged) !== ''
                ? self::scopes(explode(' ', $challenged))
                : self::scopes($resourceMetadata['scopes_supported'] ?? []);
            // Servers in the OpenID Connect style only issue a refresh token for offline_access: ask for it with
            // the other scopes when either side lists it. Configured scopes are sent as written.
            $offline = [
                ...(is_array($document['scopes_supported'] ?? null) ? $document['scopes_supported'] : []),
                ...(is_array($resourceMetadata['scopes_supported'] ?? null)
                    ? $resourceMetadata['scopes_supported']
                    : []),
            ];
            if ($scopes !== [] && in_array('offline_access', $offline, true)) {
                $scopes[] = 'offline_access';
            }
        }

        return new AuthServer(
            $issuer,
            $this->learnedUrl($document['authorization_endpoint'] ?? null),
            (string)$tokenUrl,
            $registrationUrl,
            $revocationUrl,
            $scopes,
            $resource,
            ($document['authorization_response_iss_parameter_supported'] ?? null) === true,
            is_array($authMethods) ? array_values(array_filter($authMethods, 'is_string')) : [],
            false,
            ($document['client_id_metadata_document_supported'] ?? null) === true,
        );
    }

    /**
     * An unauthenticated request to the configured server, which answers 401 with a challenge.
     *
     * @param string $server The MCP server.
     * @return array<string, string> The Bearer challenge's parameters; empty when there are none.
     * @throws \CakeDC\Mcp\Client\OAuth\OAuthException On a 200: the server asks for no sign-in.
     */
    private function probe(string $server): array
    {
        $body = (string)json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => (object)[],
                'clientInfo' => ['name' => (string)ClientConfig::setting('name'), 'version' => '1.0.0'],
            ],
        ]);
        $response = $this->http->send(new HttpRequest(
            'POST',
            $server,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'],
            $body,
            (float)$this->config->timeout,
        ), true);
        if ($response->status >= 200 && $response->status < 300) {
            throw new OAuthException(sprintf(
                'The server of "%s" does not ask for a sign-in; use it in app mode (without "auth" => "user").',
                $this->config->name,
            ));
        }
        if ($response->status !== 401) {
            return [];
        }

        return self::bearerParameters((string)$response->header('WWW-Authenticate'));
    }

    /**
     * @param list<string> $urls Candidate URLs, in order.
     * @param bool $trusted Whether they come from the config.
     * @return array<string, mixed>|null The first JSON object answered with a 200, or null.
     */
    private function firstDocument(array $urls, bool $trusted): ?array
    {
        foreach ($urls as $url) {
            $response = $this->http->get($url, $trusted, (float)$this->config->timeout);
            if ($response->status === 200) {
                $document = $response->json();
                if ($document !== null) {
                    return $document;
                }
            }
        }

        return null;
    }

    /**
     * @param mixed $value An endpoint from the metadata.
     * @return string It, checked by the guard now, so a private endpoint fails discovery rather than a later step.
     */
    private function learnedEndpoint(mixed $value): string
    {
        $url = $this->learnedUrl($value);
        $this->http->check($url);

        return $url;
    }

    /**
     * @param mixed $value An optional endpoint.
     * @return string|null
     */
    private function optionalEndpoint(mixed $value): ?string
    {
        return $value === null ? null : $this->learnedEndpoint($value);
    }

    /**
     * @param string $server The configured server URL.
     * @return list<string> Its well-known resource metadata URLs: at its path (when it has one), then at the root.
     */
    private static function protectedResourceUrls(string $server): array
    {
        $parts = (array)parse_url($server);
        $origin = self::origin($parts);
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        if ($path === '') {
            return [$origin . self::PROTECTED_RESOURCE . $query];
        }

        return [$origin . self::PROTECTED_RESOURCE . $path . $query, $origin . self::PROTECTED_RESOURCE];
    }

    /**
     * @param string $issuer The expected issuer.
     * @return list<string> Its metadata URLs in the order RFC 8414 and OpenID Connect Discovery give.
     */
    public static function metadataUrls(string $issuer): array
    {
        $parts = (array)parse_url($issuer);
        $origin = self::origin($parts);
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        if ($path === '') {
            return [$origin . self::OAUTH_SERVER, $origin . self::OPENID];
        }

        return [$origin . self::OAUTH_SERVER . $path, $origin . self::OPENID . $path, $origin . $path . self::OPENID];
    }

    /**
     * @param string $issuer The expected issuer.
     * @return list<string> Its metadata URLs in the order RFC 8414 and OpenID Connect Discovery give.
     */
    private function authorizationServerUrls(string $issuer): array
    {
        $parts = (array)parse_url($this->learnedUrl($issuer));
        if (isset($parts['query'])) {
            throw self::unusable('');
        }

        return self::metadataUrls($issuer);
    }

    /**
     * Whether a grant made for $resource may be sent to $server: the same resource, or a path prefix of it, as
     * discovery accepts.
     *
     * @param string $resource The `resource` the grant was made for.
     * @param string $server The configured server URL.
     * @return bool
     */
    public static function covers(string $resource, string $server): bool
    {
        return self::resourceMatches($resource, $server, true);
    }

    /**
     * Equal means the same scheme, host, port and path (without a trailing slash) and the same query. A prefix
     * must end on a path segment: `/mc` is not a prefix of `/mcp`.
     *
     * @param string $resource The document's `resource`.
     * @param string $server The configured server URL.
     * @param bool $prefixAllowed Whether a same-origin path prefix is accepted.
     * @return bool
     */
    private static function resourceMatches(string $resource, string $server, bool $prefixAllowed): bool
    {
        $theirs = self::comparison($resource);
        $ours = self::comparison($server);
        if ($theirs === null || $ours === null) {
            return false;
        }
        if ($theirs === $ours) {
            return true;
        }
        [$theirsBase, $theirsQuery] = $theirs;
        [$oursBase] = $ours;

        return $prefixAllowed && $theirsQuery === '' && str_starts_with($oursBase, $theirsBase . '/');
    }

    /**
     * @param string $url A URL.
     * @return array{0: string, 1: string}|null Its lowercase origin without a default port plus its path without a
     *   trailing slash, and its query; null when it is not a URL.
     */
    private static function comparison(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return [self::origin($parts) . rtrim((string)($parts['path'] ?? ''), '/'), (string)($parts['query'] ?? '')];
    }

    /**
     * @param array<mixed> $parts parse_url() parts.
     * @return string Scheme, host and port (left out when it is the scheme's default), lowercase.
     */
    private static function origin(array $parts): string
    {
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . ($port === null || $port === $default ? '' : ':' . $port);
    }

    /**
     * @param mixed $value A URL from a remote document.
     * @return string It, when it is an absolute https URL without user info or fragment; plain http too for a
     *   client with both `allowPrivateNetwork` and `allowInsecure`.
     * @throws \CakeDC\Mcp\Client\OAuth\OAuthException Otherwise.
     */
    private function learnedUrl(mixed $value): string
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $schemes = $this->config->allowPrivateNetwork && $this->config->allowInsecure ? ['https', 'http'] : ['https'];
        if (
            !is_string($value)
            || !is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), $schemes, true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || preg_match('/\s/', $value) === 1
        ) {
            throw self::unusable('');
        }

        return $value;
    }

    /**
     * @param string $client The client name, or empty.
     * @return \CakeDC\Mcp\Client\OAuth\OAuthException
     */
    private static function unusable(string $client): OAuthException
    {
        return new OAuthException($client === ''
            ? 'The server\'s sign-in details name an address the MCP client cannot use.'
            : sprintf('The server of "%s" publishes sign-in details the MCP client cannot use.', $client));
    }

    /**
     * @param mixed $values Scope names, as a list.
     * @return list<string> The valid scope tokens, once each, in order, without `offline_access`.
     */
    private static function scopes(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $scopes = [];
        foreach ($values as $value) {
            if (
                is_string($value)
                && $value !== 'offline_access'
                && preg_match(self::SCOPE_TOKEN, $value) === 1
                && !in_array($value, $scopes, true)
            ) {
                $scopes[] = $value;
            }
        }

        return $scopes;
    }

    /**
     * Reads the Bearer challenge's parameters from a WWW-Authenticate header that may hold several challenges.
     *
     * @param string $header The header's values.
     * @return array<string, string> Lowercase parameter name => value, the first of each name.
     */
    private static function bearerParameters(string $header): array
    {
        $parameters = [];
        $inBearer = false;
        $offset = 0;
        $length = strlen($header);
        while ($offset < $length) {
            $offset += strspn($header, " \t,", $offset);
            if (preg_match('/\G[!#$%&\'*+\-.^_`|~0-9A-Za-z]+/', $header, $match, 0, $offset) !== 1) {
                break;
            }
            $name = $match[0];
            $offset += strlen($name);
            $afterSpaces = $offset + strspn($header, " \t", $offset);
            if (($header[$afterSpaces] ?? '') !== '=') {
                // A new challenge's scheme.
                if ($inBearer) {
                    break;
                }
                $inBearer = strcasecmp($name, 'Bearer') === 0;
                continue;
            }
            $offset = $afterSpaces + 1;
            $offset += strspn($header, " \t", $offset);
            if (($header[$offset] ?? '') === '"') {
                if (preg_match('/\G"((?:[^"\\\\]|\\\\.)*)"/s', $header, $match, 0, $offset) !== 1) {
                    break;
                }
                $value = (string)preg_replace('/\\\\(.)/s', '$1', $match[1]);
            } elseif (preg_match('/\G[^\s,]*/', $header, $match, 0, $offset) === 1) {
                $value = $match[0];
            } else {
                break;
            }
            $offset += strlen($match[0]);
            $key = strtolower($name);
            if ($inBearer && !isset($parameters[$key])) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    /**
     * Whether $url has the scheme, host and port of $server, the configured url (default ports included), and no
     * credentials.
     *
     * @param string $url A URL the server named.
     * @param string $server The configured url.
     * @return bool
     */
    private static function sameOrigin(string $url, string $server): bool
    {
        $origin = function (string $value): ?string {
            $parts = parse_url($value);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user'])) {
                return null;
            }
            $scheme = strtolower($parts['scheme']);
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null));

            return $scheme . '://' . strtolower($parts['host']) . ':' . $port;
        };
        $mine = $origin($server);

        return $mine !== null && $origin($url) === $mine;
    }
}
