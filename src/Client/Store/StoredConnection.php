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
     * The most scopes a step-up mark keeps.
     */
    public const MAX_STEP_UP_SCOPES = 50;

    /**
     * The longest step-up mark, in bytes.
     */
    public const MAX_STEP_UP_LENGTH = 2048;

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
     * @param string|null $stepUpScope The scopes to ask for at the next connect, space separated, after the server
     *   answered `403 insufficient_scope`; null when it did not.
     * @param string|null $requestedScope The scopes the sign-in that made this grant asked for, space separated;
     *   null when it asked for none or the grant is not a user's sign-in.
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
        public readonly ?string $stepUpScope = null,
        public readonly ?string $requestedScope = null,
    ) {
    }

    /**
     * @return list<string> The scopes the sign-in that made this grant asked for.
     */
    public function requestedScopes(): array
    {
        return $this->requestedScope === null
            ? []
            : array_values(array_filter(explode(' ', $this->requestedScope), fn(string $s): bool => $s !== ''));
    }

    /**
     * @return list<string> The scopes to ask for at the next connect, empty when the server asked for none more.
     */
    public function stepUpScopes(): array
    {
        return $this->stepUpScope === null
            ? []
            : array_values(array_filter(explode(' ', $this->stepUpScope), fn(string $s): bool => $s !== ''));
    }

    /**
     * This grant, marked as needing $scopes too: the granted scopes, those marked before and $scopes.
     *
     * @param list<string> $scopes The scopes the server asked for.
     * @return self
     */
    public function withStepUp(array $scopes): self
    {
        $granted = $this->scope === null ? [] : explode(' ', $this->scope);
        $all = array_values(array_unique(array_filter(
            [...$granted, ...$this->stepUpScopes(), ...$scopes],
            fn(string $s): bool => $s !== '',
        )));
        // Bounded, in the order above: the scopes come from the MCP server, and the next authorization URL and
        // the database column carry them.
        $union = [];
        $length = 0;
        foreach ($all as $scope) {
            $length += strlen($scope) + ($union === [] ? 0 : 1);
            if (count($union) >= self::MAX_STEP_UP_SCOPES || $length > self::MAX_STEP_UP_LENGTH) {
                break;
            }
            $union[] = $scope;
        }

        return new self(
            $this->client,
            $this->userId,
            $this->accessToken,
            $this->refreshToken,
            $this->expiresAt,
            $this->scope,
            $this->issuer,
            $this->tokenUrl,
            $this->revocationUrl,
            $this->clientId,
            $this->clientSecret,
            $this->authMethod,
            $this->resource,
            implode(' ', $union),
            $this->requestedScope,
        );
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
            $this->stepUpScope,
            $this->requestedScope,
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
