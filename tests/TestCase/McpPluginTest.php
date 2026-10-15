<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase;

use Cake\TestSuite\TestCase;
use TestApp\Application;

class McpPluginTest extends TestCase
{
    public function testTheTestAppLoadsThePlugin(): void
    {
        $app = new Application(CONFIG);
        $app->bootstrap();
        $app->pluginBootstrap();

        $this->assertTrue($app->getPlugins()->has('CakeDC/Mcp'));
    }
}
