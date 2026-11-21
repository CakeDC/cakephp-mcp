<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\OAuth;

use Cake\I18n\DateTime;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\Store\ConnectionStoreInterface;
use CakeDC\Mcp\Client\Store\StoredConnection;

/**
 * The authorization code flow of a user mode client (OAuth 2.1 with PKCE S256, the `resource` parameter of RFC 8707
 * and the `iss` check of RFC 9207). start() discovers the server, gets a client and builds the authorization URL;
 * complete() checks the response, exchanges the code and stores the user's grant.
 *
 * The caller keeps the PendingAuthorization in the user's session and checks that the same user completes it.
 */
final class OAuthFlow
{
    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The user mode client.
     * @param \CakeDC\Mcp\Client\OAuth\Discovery $discovery Finds the authorization server.
     * @param \CakeDC\Mcp\Client\OAuth\Registrar $registrar Gets the OAuth client.
     * @param \CakeDC\Mcp\Client\OAuth\TokenClient $tokens Exchanges the code.
     * @param \CakeDC\Mcp\Client\Store\ConnectionStoreInterface $store Keeps the grant.
     */
    public function __construct(
        private ClientConfig $config,
        private Discovery $discovery,
        private Registrar $registrar,
        private TokenClient $tokens,
        private ConnectionStoreInterface $store,
    ) {
    }

    /**
     * @param string $userId The user signing in.
     * @param string $redirectUri The callback URL.
     * @param string $returnTo The local path to send the user back to.
     * @return array{0: \CakeDC\Mcp\Client\OAuth\PendingAuthorization, 1: string} The pending sign-in and the
     *   authorization URL to send the browser to.
     * @throws \CakeDC\Mcp\Client\Exception\ClientException
     */
    public function start(string $userId, string $redirectUri, string $returnTo): array
    {
        $server = $this->discovery->discover();
        $registration = $this->registrar->clientFor($server, $redirectUri);
        // Scope step-up: what the user already had, and what the server asked for with insufficient_scope.
        $scopes = array_values(array_unique([
            ...$server->scopes,
            ...($this->store->get($this->config->name, $userId)?->stepUpScopes() ?? []),
        ]));
        $verifier = self::base64Url(random_bytes(32));
        $pending = new PendingAuthorization(
            $this->config->name,
            $userId,
            self::base64Url(random_bytes(32)),
            $verifier,
            $redirectUri,
            $returnTo,
            DateTime::now()->getTimestamp(),
            $server->issuer,
            $server->tokenUrl,
            $server->revocationUrl,
            $server->trusted,
            $server->issParameter,
            $server->resource,
            $registration->clientId,
            $registration->authMethod,
            $scopes === [] ? null : implode(' ', $scopes),
        );
        $query = [
            'response_type' => 'code',
            'client_id' => $registration->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $pending->state,
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource' => $server->resource,
        ];
        if ($scopes !== []) {
            $query['scope'] = implode(' ', $scopes);
        }
        $url = $server->authorizationUrl;
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return [$pending, $url];
    }

    /**
     * @param \CakeDC\Mcp\Client\OAuth\PendingAuthorization $pending The sign-in started.
     * @param array<string, mixed> $query The callback's query.
     * @return \CakeDC\Mcp\Client\Store\StoredConnection The stored grant.
     * @throws \CakeDC\Mcp\Client\OAuth\OAuthException When the response does not match the sign-in started.
     * @throws \CakeDC\Mcp\Client\Exception\ClientException
     */
    public function complete(PendingAuthorization $pending, array $query): StoredConnection
    {
        $state = $query['state'] ?? null;
        if (!is_string($state) || !hash_equals($pending->state, $state) || $pending->client !== $this->config->name) {
            throw new OAuthException('The sign-in response does not match the sign-in that was started.');
        }
        $now = DateTime::now()->getTimestamp();
        if ($now - $pending->createdAt > PendingAuthorization::LIFETIME) {
            throw new OAuthException('The sign-in took too long; connect again.');
        }
        $iss = $query['iss'] ?? null;
        // With configured endpoints and no configured issuer, the issuer is not known: nothing to compare `iss` with.
        $issuerKnown = !$pending->trusted || $this->config->issuer !== null;
        if (($issuerKnown && $iss !== null && $iss !== $pending->issuer) || ($iss === null && $pending->issParameter)) {
            // RFC 9207: the response must come from the server the flow started with (mix-up), an error response
            // too: its error is not acted on (section 2.4).
            throw new OAuthException('The sign-in response came from another authorization server.');
        }
        if (isset($query['error'])) {
            $error = OAuthException::code($query['error']) ?? 'error';

            throw new OAuthException(sprintf('The authorization server answered %s.', $error), $error);
        }
        $code = $query['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new OAuthException('The sign-in response has no code.');
        }

        $secret = $this->secret($pending);
        try {
            $tokens = $this->tokens->exchange(
                $pending->tokenUrl,
                $pending->trusted,
                $pending->clientId,
                $secret,
                $pending->authMethod,
                [
                    'code' => $code,
                    'code_verifier' => $pending->verifier,
                    'redirect_uri' => $pending->redirectUri,
                    'resource' => $pending->resource,
                ],
            );
        } catch (OAuthException $e) {
            if ($e->error === 'invalid_client' && $this->config->clientId === null) {
                // A registration the server dropped: the next connect registers again.
                $this->store->deleteRegistration($this->config->name, $pending->issuer);
            }

            throw $e;
        }

        $connection = new StoredConnection(
            $this->config->name,
            $pending->userId,
            $tokens->accessToken,
            $tokens->refreshToken,
            $tokens->expiresIn === null ? null : $now + $tokens->expiresIn,
            // RFC 6749 5.1: without `scope` in the answer, the scope granted is the one asked for.
            $tokens->scope ?? $pending->scope,
            $pending->issuer,
            $pending->tokenUrl,
            $pending->revocationUrl,
            $pending->clientId,
            $secret,
            $pending->authMethod,
            $pending->resource,
        );
        $this->store->save($connection);

        return $connection;
    }

    /**
     * @param \CakeDC\Mcp\Client\OAuth\PendingAuthorization $pending The sign-in.
     * @return string|null The client secret of its client id: configured, or from the stored registration.
     */
    private function secret(PendingAuthorization $pending): ?string
    {
        if ($this->config->clientId !== null) {
            return $this->config->clientId === $pending->clientId ? $this->config->clientSecret : null;
        }
        $registration = $this->store->registration($this->config->name, $pending->issuer);

        return $registration !== null && $registration->clientId === $pending->clientId
            ? $registration->clientSecret
            : null;
    }

    /**
     * @param string $bytes Binary data.
     * @return string Base64url without padding.
     */
    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
