<?php
declare(strict_types=1);

namespace TestApp\McpNested;

use Mcp\Capability\Attribute\McpTool;

/**
 * A server directory that holds another server's directory (`Admin`), for the nested discovery tests.
 */
class HoursTools
{
    /**
     * The opening hours.
     */
    #[McpTool(name: 'opening_hours')]
    public function hours(): string
    {
        return '9 to 5';
    }
}
