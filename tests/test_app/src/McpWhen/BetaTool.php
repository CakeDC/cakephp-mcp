<?php
declare(strict_types=1);

namespace TestApp\McpWhen;

use CakeDC\Mcp\Attribute\McpWhen;
use Mcp\Capability\Attribute\McpTool;

/**
 * An invokable tool registered only on the `beta` server.
 */
#[McpWhen(callback: [Flags::class, 'onlyBeta'])]
#[McpTool(name: 'beta_tool')]
class BetaTool
{
    /**
     * A beta feature.
     */
    public function __invoke(): string
    {
        return 'beta';
    }
}
