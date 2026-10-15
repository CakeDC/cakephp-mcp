<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Cache\Engine\FileEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
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
}
