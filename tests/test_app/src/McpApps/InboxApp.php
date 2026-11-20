<?php
declare(strict_types=1);

namespace TestApp\McpApps;

use CakeDC\Mcp\App\AppView;
use Mcp\Capability\Attribute\McpResource;
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
     * A page whose template fails.
     */
    #[McpResource(uri: 'ui://test/broken', name: 'broken', mimeType: McpApps::MIME_TYPE)]
    public function broken(): TextResourceContents
    {
        return AppView::render('ui://test/broken', 'McpApps/broken');
    }
}
