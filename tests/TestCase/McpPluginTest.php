<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Cache\Engine\FileEngine;
use Cake\Core\Configure;
use Cake\Routing\RouteBuilder;
use Cake\Routing\RouteCollection;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\McpPlugin;
use TestApp\Application;

class McpPluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->tearDownCaches();
    }

    protected function tearDown(): void
    {
        $this->tearDownCaches();
        parent::tearDown();
    }

    private function tearDownCaches(): void
    {
        Cache::drop('_cake_mcp_');
        Cache::drop('mcp_sessions');
        Cache::drop('host_sessions');
    }

    public function testTheTestAppLoadsThePlugin(): void
    {
        $app = new Application(CONFIG);
        $app->bootstrap();
        $app->pluginBootstrap();

        $this->assertTrue($app->getPlugins()->has('CakeDC/Mcp'));
    }

    public function testBootstrapDefinesMissingCacheConfigs(): void
    {
        $app = new Application(CONFIG);
        $app->bootstrap();
        $app->pluginBootstrap();

        $this->assertInstanceOf(FileEngine::class, Cache::pool('_cake_mcp_'));
        $this->assertInstanceOf(FileEngine::class, Cache::pool('mcp_sessions'));
    }

    public function testBootstrapKeepsCacheConfigsTheHostDefined(): void
    {
        Configure::write('Mcp.session.cache', 'host_sessions');
        Cache::setConfig('host_sessions', ['className' => ArrayEngine::class]);

        $app = new Application(CONFIG);
        $app->bootstrap();
        $app->pluginBootstrap();

        $this->assertInstanceOf(ArrayEngine::class, Cache::pool('host_sessions'));
    }

    public function testRoutesConnectTheEndpointAtTheConfiguredPath(): void
    {
        Configure::write('Mcp.route', '/ai/mcp');
        $collection = new RouteCollection();

        (new McpPlugin())->routes(new RouteBuilder($collection, '/'));

        $this->assertCount(2, $collection->routes());
        $this->assertSame('/ai/mcp', $collection->routes()[0]->template);
        $this->assertSame('/.well-known/oauth-protected-resource/ai/mcp', $collection->routes()[1]->template);
    }

    public function testRoutesAreSkippedWhenDisabled(): void
    {
        Configure::write('Mcp.route', false);
        $collection = new RouteCollection();

        (new McpPlugin())->routes(new RouteBuilder($collection, '/'));

        $this->assertCount(0, $collection->routes());
    }
}
