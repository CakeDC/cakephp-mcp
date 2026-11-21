<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Command\Bake;

use CakeDC\Mcp\Bake\McpBakeCommand;

/**
 * `bin/cake bake mcp_tool <Name>`: an MCP tool as an invokable class in `src/Mcp/`, and its test.
 */
class McpToolCommand extends McpBakeCommand
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Bake an MCP tool class and its test.';
    }

    /**
     * @inheritDoc
     */
    protected function kind(): string
    {
        return 'tool';
    }
}
