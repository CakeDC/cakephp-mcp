<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Catalog;

/**
 * Which tools belong to the catalog (`Mcp.catalog.tools`).
 */
final class CatalogPatterns
{
    /**
     * @param list<string> $patterns Tool names or `fnmatch()` patterns (`order_*`).
     * @param string $tool A tool name.
     * @return bool Whether the tool is in the catalog. The catalog's own tools never are.
     */
    public static function matches(array $patterns, string $tool): bool
    {
        if ($tool === ToolCatalog::FIND || $tool === ToolCatalog::CALL) {
            return false;
        }
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $tool)) {
                return true;
            }
        }

        return false;
    }
}
