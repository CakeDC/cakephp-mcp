<?php
declare(strict_types=1);

namespace TestApp\McpCatalogClash;

use Mcp\Capability\Attribute\McpTool;

/**
 * A tool of the application with the name of the catalog's own search tool.
 */
class ClashTool
{
    /**
     * Finds tools in the shop.
     *
     * @param string $query What to look for.
     */
    #[McpTool(name: 'find_tools')]
    public function find(string $query): string
    {
        return 'shop tools: ' . $query;
    }
}
