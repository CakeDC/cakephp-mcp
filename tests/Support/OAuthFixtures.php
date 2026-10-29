<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

/**
 * The fixtures of every test that uses the oauth plugin's tables.
 */
final class OAuthFixtures
{
    public const ALL = [
        'plugin.CakeDC/Mcp.Users',
        'plugin.CakeDC/Mcp.OauthClients',
        'plugin.CakeDC/Mcp.OauthAuthorizationCodes',
        'plugin.CakeDC/Mcp.OauthTokens',
        'plugin.CakeDC/Mcp.OauthConsents',
    ];
}
