<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Controller;

use CakeDC\Mcp\McpConfig;
use InvalidArgumentException;

/**
 * The MCP server a request is for: the `server` route param, `default` when the route has none (the 2.1 routes,
 * or a route the application connected itself).
 */
trait ResolvesServerTrait
{
    /**
     * @return string|null The server name, or null when it is not configured.
     */
    protected function mcpServer(): ?string
    {
        $server = $this->request->getParam('server');
        $server = is_string($server) && $server !== '' ? $server : McpConfig::DEFAULT_SERVER;
        try {
            McpConfig::get('route', $server);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $server;
    }
}
