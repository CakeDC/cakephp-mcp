<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\AllowAllToolAuthorizer;
use CakeDC\Mcp\McpConfig;

class McpConfigTest extends TestCase
{
    public function testDefaultsApplyWhenTheHostConfiguresNothing(): void
    {
        Configure::delete('Mcp');

        $this->assertSame('/mcp', McpConfig::get('route'));
        $this->assertSame(['src/Mcp'], McpConfig::get('discovery.scanDirs'));
        $this->assertTrue(McpConfig::get('identity.required'));
        $this->assertSame(AllowAllToolAuthorizer::class, McpConfig::get('authorization.authorizer'));
        $this->assertTrue(McpConfig::get('stdio.trusted'));
    }

    public function testHostValuesOverrideAndKeepSiblingDefaults(): void
    {
        Configure::write('Mcp', ['server' => ['name' => 'Host Server']]);

        $this->assertSame('Host Server', McpConfig::get('server.name'));
        $this->assertSame('1.0.0', McpConfig::get('server.version'));
    }

    public function testListsAreReplacedNotAppended(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/Tools']);

        $this->assertSame(['src/Tools'], McpConfig::get('discovery.scanDirs'));
    }

    public function testRouteCanBeDisabledWithFalse(): void
    {
        Configure::write('Mcp.route', false);

        $this->assertFalse(McpConfig::get('route'));
    }

    public function testDiscoveryCacheIsOffInDebugAndNamedOtherwise(): void
    {
        Configure::write('debug', true);
        $this->assertNull(McpConfig::discoveryCache());

        Configure::write('debug', false);
        $this->assertSame('_cake_mcp_', McpConfig::discoveryCache());

        Configure::write('Mcp.discovery.cache', false);
        $this->assertNull(McpConfig::discoveryCache());
    }

    public function testAllowedHostsDefaultToFullBaseUrlHostPlusLocalhost(): void
    {
        Configure::write('App.fullBaseUrl', 'https://MCP.Example.com:8443');

        $this->assertSame(['mcp.example.com', 'localhost', '127.0.0.1', '[::1]'], McpConfig::allowedHosts());
    }

    public function testExplicitAllowedHostsAreUsedAsGivenLowercased(): void
    {
        Configure::write('Mcp.http.allowedHosts', ['API.example.com']);

        $this->assertSame(['api.example.com'], McpConfig::allowedHosts());
    }
}
