<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\OAuth;

use Cake\I18n\DateTime;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\Http\OutboundHttp;
use CakeDC\Mcp\Client\Store\ConnectionStoreInterface;
use CakeDC\Mcp\Client\Store\StoredConnection;

/**
 * The access token of a client with `'auth' => 'client_credentials'`: asked for with its client id and secret at
 * the token endpoint of the configured `issuer` (read from its metadata, RFC 8414) or at the configured
 * `tokenUrl`, so the secret never goes to an authorization server the MCP server names. Kept in the connection
 * store (encrypted, like user grants) until it expires within `Mcp.client.refreshLeeway` seconds.
 */
final class ClientCredentials
{
    /**
     * The start of the user id the application's own token is stored under, followed by `:` and a fingerprint of
     * what the token was asked for with (see storeUser()). Plain text, for every database; it cannot meet a real
     * user, since a client_credentials client has no users (for() refuses it) and rows are keyed by client.
     */
    public const STORE_USER = 'client_credentials';

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client.
     * @param \CakeDC\Mcp\Client\Http\OutboundHttp $http Its HTTP layers.
     * @param \CakeDC\Mcp\Client\Store\ConnectionStoreInterface $store Where the token is kept.
     * @param \CakeDC\Mcp\Client\OAuth\TokenClient $tokens The token endpoint client.
     */
    public function __construct(
        private ClientConfig $config,
        private OutboundHttp $http,
        private ConnectionStoreInterface $store,
        private TokenClient $tokens,
    ) {
    }

    /**
     * @param bool $renew Ask for a new token even when the stored one has not expired (the server refused it).
     * @return string The access token to send.
     * @throws \CakeDC\Mcp\Client\OAuth\OAuthException
     */
    public function token(bool $renew = false): string
    {
        $now = DateTime::now()->getTimestamp();
        $user = $this->storeUser();
        $stored = $this->store->get($this->config->name, $user);
        if (
            !$renew
            && $stored !== null
            && $stored->resource === $this->config->url
            && !$stored->expiresWithin((int)ClientConfig::setting('refreshLeeway'), $now)
        ) {
            return $stored->accessToken;
        }
        [$issuer, $tokenUrl, $trusted, $methods] = $this->endpoint();
        $set = $this->tokens->clientCredentials(
            $tokenUrl,
            $trusted,
            (string)$this->config->clientId,
            (string)$this->config->clientSecret,
            Registrar::automaticMethod(true, $methods),
            (string)$this->config->url,
            $this->config->scopes,
        );
        $this->store->save(new StoredConnection(
            $this->config->name,
            $user,
            $set->accessToken,
            null,
            $set->expiresIn === null ? null : $now + $set->expiresIn,
            $set->scope,
            $issuer,
            $tokenUrl,
            null,
            (string)$this->config->clientId,
            null,
            'none',
            (string)$this->config->url,
        ));

        return $set->accessToken;
    }

    /**
     * The user id of the token for the client as configured now: a token asked for at another issuer or token
     * URL, by another client id or for other scopes is never found, so it is not sent, and a new one is asked for.
     *
     * @return string
     */
    private function storeUser(): string
    {
        $scopes = $this->config->scopes;
        sort($scopes);
        $fingerprint = hash('sha256', (string)json_encode([
            $this->config->issuer,
            $this->config->tokenUrl,
            $this->config->clientId,
            $scopes,
        ]));

        return self::STORE_USER . ':' . substr($fingerprint, 0, 32);
    }

    /**
     * @return array{0: string, 1: string, 2: bool, 3: list<string>} The issuer, the token endpoint, whether it is
     *   from the config, and the client authentication methods it lists.
     * @throws \CakeDC\Mcp\Client\OAuth\OAuthException When the issuer's metadata is not usable.
     */
    private function endpoint(): array
    {
        $config = $this->config;
        if ($config->tokenUrl !== null) {
            return [$config->issuer ?? (string)$config->tokenUrl, $config->tokenUrl, true, []];
        }
        $issuer = (string)$config->issuer;
        $document = null;
        foreach (Discovery::metadataUrls($issuer) as $url) {
            // The issuer is configured: its metadata is read like the configured url.
            $response = $this->http->get($url, true, (float)$config->timeout);
            $document = $response->status === 200 ? $response->json() : null;
            if ($document !== null) {
                break;
            }
        }
        if ($document === null) {
            throw new OAuthException(sprintf(
                'The authorization server of "%s" does not publish its details.',
                $config->name,
            ));
        }
        // Simple string comparison, as RFC 8414 3.3 says.
        if (($document['issuer'] ?? null) !== $issuer) {
            throw new OAuthException(sprintf(
                'The metadata of "Mcp.clients.%s.issuer" names another issuer: the secret is not sent.',
                $config->name,
            ));
        }
        $tokenUrl = $document['token_endpoint'] ?? null;
        if (!is_string($tokenUrl) || !str_starts_with($tokenUrl, 'https://')) {
            throw new OAuthException(sprintf(
                'The authorization server of "%s" names no usable token endpoint.',
                $config->name,
            ));
        }
        $methods = $document['token_endpoint_auth_methods_supported'] ?? [];

        return [$issuer, $tokenUrl, false, is_array($methods) ? array_values(array_filter($methods, 'is_string')) : []];
    }
}
