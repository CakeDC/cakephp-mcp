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
        // fnmatch() raises a ValueError for a NUL byte, which no tool name has.
        if ($tool === ToolCatalog::FIND || $tool === ToolCatalog::CALL || str_contains($tool, "\0")) {
            return false;
        }
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $tool)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one rule for "this tool is behind the catalog", used by `tools/list`, `find_tools`, `call_tools`,
     * `bin/cake mcp check` and `McpTestTrait`: it matches a pattern and has no app. An app-only tool is for the
     * app's page, and a tool that links to an app must stay in `tools/list`, where the host reads its `_meta.ui`.
     *
     * @param list<string> $patterns `Mcp.catalog.tools`.
     * @param string $tool A tool name.
     * @param array<mixed>|null $meta The tool's `_meta`.
     * @return bool
     */
    public static function catalogues(array $patterns, string $tool, ?array $meta): bool
    {
        return self::matches($patterns, $tool) && self::keptOut($meta) === null;
    }

    /**
     * Why a tool that matches a pattern stays out of the catalog, or null when it does not.
     *
     * @param array<mixed>|null $meta The tool's `_meta`.
     * @return string|null `app-only` or `links to an app`.
     */
    public static function keptOut(?array $meta): ?string
    {
        $ui = is_array($meta['ui'] ?? null) ? $meta['ui'] : [];
        if (($ui['visibility'] ?? null) === ['app']) {
            return 'app-only';
        }
        if (is_string($ui['resourceUri'] ?? null) && $ui['resourceUri'] !== '') {
            return 'links to an app';
        }

        return null;
    }
}
