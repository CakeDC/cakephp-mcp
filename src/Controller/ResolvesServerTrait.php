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
            $route = McpConfig::get('route', $server);
        } catch (InvalidArgumentException) {
            return null;
        }
        // Only the server whose own route (or its metadata route) is this path: never a stdio only server, nor
        // another server named by a route param.
        if (!is_string($route) || trim($route, '/') === '') {
            return null;
        }
        $path = '/' . trim($this->request->getPath(), '/');
        $metadata = McpConfig::METADATA_PREFIX . '/' . trim($route, '/');
        if (McpConfig::routeValues($route, $path) === null && McpConfig::routeValues($metadata, $path) === null) {
            return null;
        }

        return $server;
    }

    /**
     * The values of the parameters of $server's route in this request (`/mcp/{tenant}`).
     *
     * @param string $server The server.
     * @return array<string, string>
     */
    protected function mcpRouteValues(string $server): array
    {
        $values = [];
        foreach (McpConfig::routeParams(McpConfig::get('route', $server)) as $name) {
            $value = $this->request->getParam($name);
            if (is_string($value) && $value !== '') {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
