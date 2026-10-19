<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Authorization;

use CakeDC\Mcp\Identity\CurrentIdentity;

/**
 * One place that answers "may the current caller use this tool?", shared by
 * the call guard and the list filter.
 */
final class ToolGate
{
    /**
     * @param \CakeDC\Mcp\Authorization\ToolAuthorizerInterface $authorizer The configured authorizer.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity The current caller.
     * @param bool $trusted Skip the authorizer (stdio started by the developer).
     */
    public function __construct(
        private readonly ToolAuthorizerInterface $authorizer,
        private readonly CurrentIdentity $identity,
        private readonly bool $trusted = false,
    ) {
    }

    /**
     * @param string $tool The MCP tool name.
     * @return bool
     */
    public function allows(string $tool): bool
    {
        return $this->trusted || $this->authorizer->canCall($this->identity->get(), $tool);
    }
}
