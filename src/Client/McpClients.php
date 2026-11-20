<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

/**
 * The entry point to the clients of `Mcp.clients`:
 *
 * ```
 * McpClients::get('github')->callTool('search_issues', ['query' => 'bug']);
 * McpClients::get('linear')->for($user)->listTools();
 * ```
 */
final class McpClients
{
    private static ?ClientRegistry $registry = null;

    /**
     * @param string $name A key of `Mcp.clients`.
     * @return \CakeDC\Mcp\Client\McpClient
     * @throws \InvalidArgumentException When it is not configured or its config is invalid.
     */
    public static function get(string $name): McpClient
    {
        return self::registry()->get($name);
    }

    /**
     * @return \CakeDC\Mcp\Client\ClientRegistry The registry in use.
     */
    public static function registry(): ClientRegistry
    {
        return self::$registry ??= new ClientRegistry();
    }

    /**
     * Replaces the registry, for example with one built on test doubles. The previous one is closed.
     *
     * @param \CakeDC\Mcp\Client\ClientRegistry|null $registry The registry, or null for the default.
     * @return void
     */
    public static function setRegistry(?ClientRegistry $registry): void
    {
        self::$registry?->close();
        self::$registry = $registry;
    }

    /**
     * Closes every connection and goes back to the default registry.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::setRegistry(null);
    }
}
