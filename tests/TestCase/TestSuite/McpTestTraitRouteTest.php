<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\TestSuite;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;

/**
 * mcpUseServer() with the values of a route such as /mcp/{tenant}.
 */
class McpTestTraitRouteTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.servers.tenants', [
            'route' => '/mcp/t/{tenant}',
            'discovery' => ['scanDirs' => ['src/McpTenant']],
        ]);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testToolsSeeTheRouteValuesOfTheTest(): void
    {
        $this->mcpActingAs(['id' => 1]);
        $this->mcpUseServer('tenants', ['tenant' => 'acme']);

        $this->mcpCallTool('tenant_info');

        $this->assertMcpStructuredContentPath('service', 'acme');
        $this->assertMcpStructuredContentPath('all', ['tenant' => 'acme']);
    }
}
