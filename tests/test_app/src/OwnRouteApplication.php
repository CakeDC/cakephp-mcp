<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Routing\RouteBuilder;

/**
 * An application that connects a route of its own to the plugin's MCP controller, as some 2.1 applications do.
 */
class OwnRouteApplication extends Application
{
    /**
     * @inheritDoc
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->connect('/internal/mcp', ['plugin' => 'CakeDC/Mcp', 'controller' => 'Mcp', 'action' => 'handle'])
            ->setMethods(['POST']);
        parent::routes($routes);
    }
}
