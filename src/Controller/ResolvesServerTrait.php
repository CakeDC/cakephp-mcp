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
        // Cake's route pattern ends in $, which also matches before a final line feed: /mcp%0A reaches this
        // controller, while McpConfig::isEndpoint() (a rate limit before routing) says it is not the endpoint.
        if (str_ends_with(McpConfig::requestPath($this->request), "\n")) {
            return null;
        }
        $server = $this->request->getParam('server');
        $named = is_string($server) && $server !== '';
        $server = $named ? $server : McpConfig::DEFAULT_SERVER;
        try {
            $route = McpConfig::get('route', $server);
        } catch (InvalidArgumentException) {
            return null;
        }
        // Without a server param this is the default server, at whatever path the route that reached the controller
        // has: the plugin's own, the plugin's routes loaded inside a scope, or a route the application connected
        // itself (2.1 served all of them).
        if (!$named) {
            return $server;
        }
        // A named server only at its own route (or its metadata route): never a stdio only server, nor another
        // server named by a route param.
        if (!is_string($route) || trim($route, '/') === '') {
            return null;
        }
        $path = McpConfig::requestPath($this->request);
        $metadata = McpConfig::METADATA_PREFIX . '/' . trim($route, '/');
        if (McpConfig::routeValues($route, $path) === null && McpConfig::routeValues($metadata, $path) === null) {
            return null;
        }
        foreach (McpConfig::hostRouteParams($server) as $name) {
            $value = $this->request->getParam($name);
            if (is_string($value) && $value !== strtolower($value)) {
                return null;
            }
        }

        return $server;
    }

    /**
     * The values of the parameters of $server's route in this request (`/mcp/{tenant}`).
     *
     * @param string $server The server.
     * @return array<string, string>
     * @throws \InvalidArgumentException When the default server's route has parameters, which only a named server
     *   may have: the request fails closed.
     */
    protected function mcpRouteValues(string $server): array
    {
        $route = McpConfig::get('route', $server);
        $params = McpConfig::routeParams($route);
        if ($params !== [] && $server === McpConfig::DEFAULT_SERVER) {
            throw new InvalidArgumentException(sprintf(
                '"Mcp.route" is "%s": only a named server (Mcp.servers) may have route parameters, so the default '
                . 'server is not served. Give "Mcp.route" a fixed path and serve "%s" from a named server.',
                (string)$route,
                (string)$route,
            ));
        }
        $values = [];
        foreach ($params as $name) {
            $value = $this->request->getParam($name);
            if (is_string($value) && $value !== '') {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
