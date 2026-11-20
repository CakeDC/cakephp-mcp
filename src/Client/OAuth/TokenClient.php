<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\OAuth;

use Cake\Log\Log;
use CakeDC\Mcp\Client\Exception\ClientException;
use CakeDC\Mcp\Client\Http\HttpRequest;
use CakeDC\Mcp\Client\Http\HttpResponse;
use CakeDC\Mcp\Client\Http\OutboundHttp;
use CakeDC\Mcp\Client\Store\StoredConnection;
use CakeDC\Mcp\Log\McpLogger;

/**
 * Talks to an authorization server's token and revocation endpoints: the code exchange, the refresh and the
 * revocation (RFC 7009), authenticating as the client does (`none`, `client_secret_basic` or `client_secret_post`).
 * Never keeps or repeats what the server said about an error, and never logs a token or a secret.
 *
 * Adapted from ZuluBrain's TokenAnswer and TokenRevoker.
 */
final class TokenClient
{
    /**
     * The longest token kept.
     */
    public const MAX_TOKEN_LENGTH = 16384;

    /**
     * The longest `expires_in` believed, in seconds (a year).
     */
    public const MAX_EXPIRES_IN = 31536000;

    /**
     * @param \CakeDC\Mcp\Client\Http\OutboundHttp $http Sends the requests.
     * @param int $timeout Seconds for each request.
     */
    public function __construct(private OutboundHttp $http, private int $timeout = 30)
    {
    }

    /**
     * Exchanges an authorization code (with its PKCE verifier) for tokens.
     *
     * @param string $tokenUrl The token endpoint.
     * @param bool $trusted Whether it comes from the config.
     * @param string $clientId The client id.
     * @param string|null $clientSecret The client secret, if any.
     * @param string $authMethod How the client authenticates.
     * @param array<string, string> $grant `code`, `code_verifier`, `redirect_uri` and `resource`.
     * @return \CakeDC\Mcp\Client\OAuth\TokenSet
     * @throws \CakeDC\Mcp\Client\OAuth\OAuthException
     */
    public function exchange(
        string $tokenUrl,
        bool $trusted,
        string $clientId,
        ?string $clientSecret,
        string $authMethod,
        array $grant,
    ): TokenSet {
        $form = ['grant_type' => 'authorization_code'] + $grant;

        return self::read($this->http->send(
            $this->post($tokenUrl, $form, $clientId, $clientSecret, $authMethod),
            $trusted,
        ));
    }

    /**
     * @param \CakeDC\Mcp\Client\Store\StoredConnection $connection A grant with a refresh token.
     * @param bool $trusted Whether its token endpoint comes from the config.
     * @return \CakeDC\Mcp\Client\OAuth\TokenSet
     * @throws \CakeDC\Mcp\Client\OAuth\OAuthException `invalid_grant` when the refresh token is no longer accepted.
     */
    public function refresh(StoredConnection $connection, bool $trusted): TokenSet
    {
        return self::read($this->http->send($this->post(
            $connection->tokenUrl,
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string)$connection->refreshToken,
                'resource' => $connection->resource,
            ],
            $connection->clientId,
            $connection->clientSecret,
            $connection->authMethod,
        ), $trusted));
    }

    /**
     * Revokes the refresh token, or the access token when there is no refresh token or its revocation failed.
     * Best effort: a failure is logged without the token and never thrown.
     *
     * @param \CakeDC\Mcp\Client\Store\StoredConnection $connection The grant.
     * @param bool $trusted Whether its revocation endpoint comes from the config.
     * @return void
     */
    public function revoke(StoredConnection $connection, bool $trusted): void
    {
        if ($connection->revocationUrl === null) {
            return;
        }
        $tokens = [];
        if ($connection->refreshToken !== null) {
            $tokens['refresh_token'] = $connection->refreshToken;
        }
        $tokens['access_token'] = $connection->accessToken;
        foreach ($tokens as $hint => $token) {
            try {
                $response = $this->http->send($this->post(
                    $connection->revocationUrl,
                    ['token' => $token, 'token_type_hint' => $hint],
                    $connection->clientId,
                    $connection->clientSecret,
                    $connection->authMethod,
                ), $trusted);
                if ($response->status >= 200 && $response->status < 300) {
                    // Revoking a refresh token revokes the access tokens of its grant (RFC 7009 2.1).
                    return;
                }
                Log::warning(sprintf(
                    'MCP client "%s": token revocation answered with status %d.',
                    $connection->client,
                    $response->status,
                ), ['scope' => [McpLogger::SCOPE]]);
            } catch (ClientException $e) {
                Log::warning(
                    sprintf('MCP client "%s": token revocation failed: %s', $connection->client, $e->getMessage()),
                    ['scope' => [McpLogger::SCOPE]],
                );
            }
        }
    }

    /**
     * @param string $url The endpoint.
     * @param array<string, string> $form The form fields.
     * @param string $clientId The client id.
     * @param string|null $clientSecret The client secret, if any.
     * @param string $authMethod How the client authenticates.
     * @return \CakeDC\Mcp\Client\Http\HttpRequest
     */
    private function post(
        string $url,
        array $form,
        string $clientId,
        ?string $clientSecret,
        string $authMethod,
    ): HttpRequest {
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
        if ($authMethod === 'client_secret_basic' && $clientSecret !== null) {
            // RFC 6749 2.3.1: both form-encoded before they are joined.
            $headers['Authorization'] = 'Basic ' . base64_encode(urlencode($clientId) . ':' . urlencode($clientSecret));
        } else {
            $form['client_id'] = $clientId;
            if ($authMethod === 'client_secret_post' && $clientSecret !== null) {
                $form['client_secret'] = $clientSecret;
            }
        }

        return new HttpRequest('POST', $url, $headers, http_build_query($form), (float)$this->timeout);
    }

    /**
     * @param \CakeDC\Mcp\Client\Http\HttpResponse $response A token endpoint's answer.
     * @return \CakeDC\Mcp\Client\OAuth\TokenSet
     * @throws \CakeDC\Mcp\Client\OAuth\OAuthException
     */
    private static function read(HttpResponse $response): TokenSet
    {
        $body = $response->json() ?? [];
        if ($response->status === 400 || $response->status === 401) {
            $error = OAuthException::code($body['error'] ?? null);
            throw new OAuthException(match ($error) {
                'invalid_grant' => 'The sign-in expired or was revoked; connect again.',
                'invalid_client', 'unauthorized_client' => 'The authorization server no longer accepts this client.',
                default => 'The authorization server refused the request.',
            }, $error);
        }
        $access = $body['access_token'] ?? null;
        $refresh = $body['refresh_token'] ?? null;
        $type = $body['token_type'] ?? null;
        if (
            $response->status !== 200
            || !self::isToken($access)
            || ($refresh !== null && !self::isToken($refresh))
            || !is_string($type)
            || strcasecmp($type, 'bearer') !== 0
        ) {
            throw new OAuthException('The authorization server gave an answer the MCP client cannot use.');
        }
        $expiresIn = $body['expires_in'] ?? null;
        if (is_string($expiresIn) && ctype_digit($expiresIn)) {
            $expiresIn = (int)$expiresIn;
        }
        $scope = $body['scope'] ?? null;

        /** @var string|null $refresh */
        return new TokenSet(
            $access,
            $refresh,
            is_int($expiresIn) && $expiresIn > 0 ? min($expiresIn, self::MAX_EXPIRES_IN) : null,
            is_string($scope) && $scope !== '' && strlen($scope) <= 2000 ? $scope : null,
        );
    }

    /**
     * Visible ASCII only: a control character or a space would make an Authorization header the HTTP client refuses.
     *
     * @param mixed $value A field of the answer.
     * @return bool Whether it can be a token to keep.
     * @phpstan-assert-if-true string $value
     */
    private static function isToken(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= self::MAX_TOKEN_LENGTH
            && preg_match('/\A[\x21-\x7E]+\z/', $value) === 1;
    }
}
