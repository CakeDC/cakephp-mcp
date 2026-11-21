<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\OAuth;

/**
 * A token endpoint's successful answer.
 */
final class TokenSet
{
    /**
     * @param string $accessToken The access token.
     * @param string|null $refreshToken The refresh token, if one came.
     * @param int|null $expiresIn Seconds the access token lasts, if the server said.
     * @param string|null $scope The scopes granted, if the server said.
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?int $expiresIn,
        public readonly ?string $scope,
    ) {
    }
}
