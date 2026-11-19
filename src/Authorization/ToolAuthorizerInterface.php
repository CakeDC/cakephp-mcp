<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Authorization;

/**
 * Decides which MCP tools an identity may list and call.
 */
interface ToolAuthorizerInterface
{
    /**
     * @param mixed $identity The host identity of the current call (as its authentication set it), or null.
     * @param string $tool The MCP tool name.
     * @return bool
     */
    public function canCall(mixed $identity, string $tool): bool;
}
