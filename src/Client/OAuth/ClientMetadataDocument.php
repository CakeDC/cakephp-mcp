<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\OAuth;

use Cake\Routing\Exception\MissingRouteException;
use Cake\Routing\Router;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\Http\EndpointGuard;
use CakeDC\Mcp\Client\Http\HostResolverInterface;

/**
 * The OAuth Client ID Metadata Document of a user mode client (draft-ietf-oauth-client-id-metadata-document-03, as
 * the MCP authorization specification uses it): served at `<Mcp.client.route>/<name>/client.json`, whose absolute
 * URL is the `client_id` the client sends to an authorization server that supports such documents. It holds no
 * secret: the client authenticates with `none` and PKCE.
 */
final class ClientMetadataDocument
{
    /**
     * The local and reserved names (RFC 6761 and RFC 6762) that make a base URL unreachable from an authorization
     * server on the internet.
     *
     * @var list<string>
     */
    private const LOCAL_SUFFIXES = [
        'localhost',
        'local',
        'localdomain',
        'internal',
        'home.arpa',
        'arpa',
        'test',
        'example',
        'invalid',
    ];

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config A user mode client.
     * @return string The document's absolute URL, which is also its `client_id`.
     */
    public static function url(ClientConfig $config): string
    {
        return Router::url(
            [
                'plugin' => 'CakeDC/Mcp',
                'controller' => 'McpClient',
                'action' => 'metadata',
                'prefix' => false,
                $config->name,
            ],
            true,
        );
    }

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config A user mode client.
     * @return string The absolute URL of its sign-in callback, its only redirect URI.
     */
    public static function callbackUrl(ClientConfig $config): string
    {
        return Router::url(
            [
                'plugin' => 'CakeDC/Mcp',
                'controller' => 'McpClient',
                'action' => 'callback',
                'prefix' => false,
                $config->name,
            ],
            true,
        );
    }

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config A user mode client.
     * @return array<string, mixed> The document.
     */
    public static function document(ClientConfig $config): array
    {
        return [
            'client_id' => self::url($config),
            'client_name' => (string)ClientConfig::setting('name'),
            'redirect_uris' => [self::callbackUrl($config)],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            // The draft recommends the list; the MCP specification's example has the single value. Both say none.
            'token_endpoint_auth_method' => 'none',
            'token_endpoint_auth_methods_supported' => ['none'],
            'application_type' => 'web',
        ];
    }

    /**
     * Whether an authorization server on the internet can fetch the document: its URL (from `App.fullBaseUrl`) is
     * https, on a public IP address or a host name with a dot that is not a local or reserved name. With a
     * resolver, the name must also resolve, to public addresses only: `*.ddev.site` resolves to this machine.
     * A site behind a VPN with public addresses still passes, and needs `'registration' => 'dynamic'`.
     *
     * @param \CakeDC\Mcp\Client\ClientConfig $config A user mode client.
     * @param \CakeDC\Mcp\Client\Http\HostResolverInterface|null $resolver Resolves the host name; null to decide on
     *   the name alone.
     * @return bool
     */
    public static function servable(ClientConfig $config, ?HostResolverInterface $resolver = null): bool
    {
        try {
            $parts = parse_url(self::url($config));
        } catch (MissingRouteException) {
            // The plugin routes are not loaded: nothing serves the document.
            return false;
        }
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
        }
        if (!str_contains($host, '.')) {
            return false;
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return false;
            }
        }
        if ($resolver === null) {
            return true;
        }
        $addresses = $resolver->resolve($host);

        return $addresses !== [] && array_filter(
            $addresses,
            fn(string $address): bool => !EndpointGuard::isPublic($address),
        ) === [];
    }
}
