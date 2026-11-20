<?php
declare(strict_types=1);

namespace CakeDC\Mcp\App;

use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Stateless\RequestMeta;

/**
 * Whether the client renders MCP Apps (it declared the `io.modelcontextprotocol/ui` extension).
 */
final class AppSupport
{
    /**
     * @param \Mcp\Server\Session\SessionInterface $session The session: its `initialize` capabilities in the
     *   handshake era, the request's metadata in the 2026-07-28 era.
     * @return bool
     */
    public static function client(SessionInterface $session): bool
    {
        $meta = $session->get(RequestMeta::class);
        if ($meta instanceof RequestMeta) {
            return isset($meta->clientCapabilities->extensions[McpApps::EXTENSION_ID]);
        }
        $capabilities = $session->get('client_capabilities', []);

        return is_array($capabilities) && isset($capabilities['extensions'][McpApps::EXTENSION_ID]);
    }
}
