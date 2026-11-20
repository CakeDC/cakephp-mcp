<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Command\Bake;

use CakeDC\Mcp\Bake\McpBakeCommand;

/**
 * `bin/cake bake mcp_prompt <Name>`: an MCP prompt as an invokable class in `src/Mcp/`, and its test.
 */
class McpPromptCommand extends McpBakeCommand
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Bake an MCP prompt class and its test.';
    }

    /**
     * @inheritDoc
     */
    protected function kind(): string
    {
        return 'prompt';
    }
}
