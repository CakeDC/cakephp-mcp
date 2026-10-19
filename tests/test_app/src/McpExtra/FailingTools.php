<?php
declare(strict_types=1);

namespace TestApp\McpExtra;

use Cake\Routing\Router;
use LogicException;
use Mcp\Capability\Attribute\McpTool;

class FailingTools
{
    /**
     * Returns the URL of an article, which needs the application routes.
     */
    #[McpTool(name: 'article_link')]
    public function articleLink(int $id): string
    {
        return Router::url(['controller' => 'Articles', 'action' => 'view', $id]);
    }

    /**
     * Fails with an exception only the server log shows.
     */
    #[McpTool(name: 'broken')]
    public function broken(): string
    {
        throw new LogicException('The broken tool is broken on purpose.');
    }
}
