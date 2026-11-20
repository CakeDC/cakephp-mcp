<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Store;

/**
 * One user's grant for one client: the tokens, and what refreshing and revoking them needs, so neither reads the
 * server's metadata again.
 */
final class StoredConnection
{
    /**
     * @param string $client The client's name in `Mcp.clients`.
     * @param string $userId The user.
     * @param string $accessToken The access token.
     * @param string|null $refreshToken The refresh token, if the server gave one.
     * @param int|null $expiresAt When the access token expires (Unix time), null when the server did not say.
     * @param string|null $scope The scopes granted, space separated, if the server said.
     * @param string $issuer The authorization server.
     * @param string $tokenUrl Its token endpoint.
     * @param string|null $revocationUrl Its revocation endpoint, if it has one.
     * @param string $clientId The OAuth client id the tokens were issued to.
     * @param string|null $clientSecret Its secret, if it has one.
     * @param string $authMethod How the client authenticates: `none`, `client_secret_basic` or `client_secret_post`.
     * @param string $resource The MCP server the tokens are for (RFC 8707).
     */
    public function __construct(
        public readonly string $client,
        public readonly string $userId,
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?int $expiresAt,
        public readonly ?string $scope,
        public readonly string $issuer,
        public readonly string $tokenUrl,
        public readonly ?string $revocationUrl,
        public readonly string $clientId,
        public readonly ?string $clientSecret,
        public readonly string $authMethod,
        public readonly string $resource,
    ) {
    }

    /**
     * @param string $accessToken The new access token.
     * @param string|null $refreshToken The refresh token to keep.
     * @param int|null $expiresAt When the access token expires.
     * @param string|null $scope The scopes granted.
     * @return self The same grant with new tokens.
     */
    public function withTokens(string $accessToken, ?string $refreshToken, ?int $expiresAt, ?string $scope): self
    {
        return new self(
            $this->client,
            $this->userId,
            $accessToken,
            $refreshToken,
            $expiresAt,
            $scope,
            $this->issuer,
            $this->tokenUrl,
            $this->revocationUrl,
            $this->clientId,
            $this->clientSecret,
            $this->authMethod,
            $this->resource,
        );
    }

    /**
     * @param int $seconds A margin.
     * @param int $now The time now (Unix time).
     * @return bool Whether the access token expires within $seconds of $now; false when its expiry is unknown.
     */
    public function expiresWithin(int $seconds, int $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt - $now <= $seconds;
    }
}
