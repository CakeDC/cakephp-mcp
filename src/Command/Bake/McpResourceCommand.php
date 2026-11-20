<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Command\Bake;

use CakeDC\Mcp\Bake\McpBakeCommand;

/**
 * `bin/cake bake mcp_resource <Name>`: an MCP resource as an invokable class in `src/Mcp/`, and its test.
 */
class McpResourceCommand extends McpBakeCommand
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Bake an MCP resource class and its test.';
    }

    /**
     * @inheritDoc
     */
    protected function kind(): string
    {
        return 'resource';
    }
}
