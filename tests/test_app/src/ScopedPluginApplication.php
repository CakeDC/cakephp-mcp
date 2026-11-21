<?php
declare(strict_types=1);

namespace TestApp;

use Cake\Routing\RouteBuilder;

/**
 * An application that loads the plugin's routes inside a scope of its own.
 */
class ScopedPluginApplication extends Application
{
    /**
     * @inheritDoc
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->scope('/api', function (RouteBuilder $scoped): void {
            $scoped->loadPlugin('CakeDC/Mcp');
        });
        parent::routes($routes);
    }
}
