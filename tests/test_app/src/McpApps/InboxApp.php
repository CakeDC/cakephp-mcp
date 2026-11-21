<?php
declare(strict_types=1);

namespace TestApp\McpApps;

use CakeDC\Mcp\App\AppView;
use CakeDC\Mcp\Attribute\McpApp;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;

/**
 * An MCP App: the inbox page, rendered from templates/McpApps/inbox.php.
 */
class InboxApp
{
    /**
     * The inbox.
     */
    #[McpResource(uri: 'ui://test/inbox', name: 'inbox', mimeType: McpApps::MIME_TYPE)]
    public function inbox(): TextResourceContents
    {
        return AppView::render(
            'ui://test/inbox',
            'McpApps/inbox',
            ['count' => 3],
            new UiResourceContentMeta(csp: new UiResourceCsp(connectDomains: ['https://api.example.com'])),
        );
    }

    /**
     * Shows the inbox: the model calls it, the host opens the app.
     */
    #[McpApp(resource: 'ui://test/inbox')]
    #[McpTool(name: 'show_inbox')]
    public function show(): string
    {
        return '3 orders to read.';
    }

    /**
     * The rows of the inbox, for the app only.
     *
     * @return array<string, mixed>
     */
    #[McpApp(resource: 'ui://test/inbox', visibility: ['app'])]
    #[McpTool(name: 'inbox_rows')]
    public function rows(): array
    {
        return ['rows' => [['patient' => 'J. Doe', 'study' => 'CT chest']]];
    }

    /**
     * A page whose template fails.
     */
    #[McpResource(uri: 'ui://test/broken', name: 'broken', mimeType: McpApps::MIME_TYPE)]
    public function broken(): TextResourceContents
    {
        return AppView::render('ui://test/broken', 'McpApps/broken');
    }
}
