<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\OAuth;

/**
 * How to sign in to one MCP server: its authorization server's endpoints, from discovery or from the config.
 */
final class AuthServer
{
    /**
     * @param string $issuer The authorization server (exactly as its metadata names itself).
     * @param string $authorizationUrl Where the user's browser goes.
     * @param string $tokenUrl The token endpoint.
     * @param string|null $registrationUrl The dynamic registration endpoint (RFC 7591), if any.
     * @param string|null $revocationUrl The revocation endpoint (RFC 7009), if any.
     * @param list<string> $scopes The scopes to ask for.
     * @param string $resource The MCP server, as the `resource` parameter (RFC 8707).
     * @param bool $issParameter Whether the authorization response carries `iss` (RFC 9207).
     * @param list<string> $authMethods `token_endpoint_auth_methods_supported`.
     * @param bool $trusted Whether the endpoints come from the config (then not through the guard).
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $authorizationUrl,
        public readonly string $tokenUrl,
        public readonly ?string $registrationUrl,
        public readonly ?string $revocationUrl,
        public readonly array $scopes,
        public readonly string $resource,
        public readonly bool $issParameter,
        public readonly array $authMethods,
        public readonly bool $trusted,
    ) {
    }
}
