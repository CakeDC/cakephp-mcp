<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Store;

/**
 * The OAuth client the application is at one authorization server for one named client: configured, or registered
 * dynamically (RFC 7591) and kept so every user signs in with the same one.
 */
final class Registration
{
    /**
     * @param string $client The client's name in `Mcp.clients`.
     * @param string $issuer The authorization server.
     * @param string $redirectUri The callback it was registered with.
     * @param string $clientId The client id.
     * @param string|null $clientSecret The client secret, if it has one.
     * @param string $authMethod `none`, `client_secret_basic` or `client_secret_post`.
     */
    public function __construct(
        public readonly string $client,
        public readonly string $issuer,
        public readonly string $redirectUri,
        public readonly string $clientId,
        public readonly ?string $clientSecret,
        public readonly string $authMethod,
    ) {
    }
}
