<?php
declare(strict_types=1);

namespace CakeDC\Mcp\App;

use Cake\View\ViewBuilder;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;

/**
 * Renders an MCP App page from a Cake template, for a `ui://` resource to return.
 */
final class AppView
{
    /**
     * @param string $uri The resource URI (`ui://...`).
     * @param string $template The template, as for a controller (`Mcp/inbox` for `templates/Mcp/inbox.php`).
     * @param array<string, mixed> $viewVars The view variables.
     * @param \Mcp\Schema\Extension\Apps\UiResourceContentMeta|null $meta CSP domains, permissions, border: the
     *   host applies them to the frame it loads the app in.
     * @param string $layout The layout. The default, `CakeDC/Mcp.app`, inlines the official MCP Apps client as
     *   `McpApps` for the page's own scripts.
     * @return \Mcp\Schema\Content\TextResourceContents
     */
    public static function render(
        string $uri,
        string $template,
        array $viewVars = [],
        ?UiResourceContentMeta $meta = null,
        string $layout = 'CakeDC/Mcp.app',
    ): TextResourceContents {
        $html = (new ViewBuilder())
            ->setTemplate($template)
            ->setLayout($layout)
            ->setVars($viewVars)
            ->build()
            ->render();

        return new TextResourceContents($uri, McpApps::MIME_TYPE, $html, $meta === null ? null : [
            'ui' => $meta->jsonSerialize(),
        ]);
    }
}
