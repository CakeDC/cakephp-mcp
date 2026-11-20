<?php
declare(strict_types=1);

namespace CakeDC\Mcp\App;

use CakeDC\Mcp\Attribute\McpApp;
use CakeDC\Mcp\Server\HandlerAttributes;
use Mcp\Capability\Registry;
use Mcp\Schema\Tool;

/**
 * Tools linked to MCP Apps.
 */
final class AppTools
{
    /**
     * Writes the `_meta.ui` of every tool with `#[McpApp]` into a loaded registry.
     *
     * @param \Mcp\Capability\Registry $registry The registry.
     * @return void
     */
    public static function link(Registry $registry): void
    {
        foreach ($registry->getTools()->references as $name => $tool) {
            $reference = $registry->getTool((string)$name);
            $app = HandlerAttributes::first($reference, McpApp::class);
            if ($app === null) {
                continue;
            }
            $meta = $tool->meta ?? [];
            $meta['ui'] = ['resourceUri' => $app->resource, 'visibility' => $app->visibility]
                + (is_array($meta['ui'] ?? null) ? $meta['ui'] : []);
            $registry->unregisterTool($tool->name);
            $registry->registerTool(new Tool(
                $tool->name,
                $tool->title,
                $tool->inputSchema,
                $tool->description,
                $tool->annotations,
                $tool->icons,
                $meta,
                $tool->outputSchema,
                $tool->execution,
            ), $reference->handler);
        }
    }

    /**
     * Whether a tool is for apps only: its `_meta.ui.visibility` is exactly `["app"]`.
     *
     * @param \Mcp\Schema\Tool $tool The tool.
     * @return bool
     */
    public static function appOnly(Tool $tool): bool
    {
        return ($tool->meta['ui']['visibility'] ?? null) === ['app'];
    }
}
