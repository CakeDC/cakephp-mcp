<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\App;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Server\ServerFactory;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use stdClass;

/**
 * MCP Apps (TestApp\McpApps).
 */
class McpAppsTest extends TestCase
{
    private const UI = 'io.modelcontextprotocol/ui';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpApps']);
        Configure::write('Mcp.apps.enabled', true);
        // The layout lives in the plugin, loaded by the application; these tests build the server without it.
        $this->loadPlugins(['CakeDC/Mcp']);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testAppsOffAdvertisesNothing(): void
    {
        Configure::write('Mcp.apps.enabled', false);

        $capabilities = $this->serve([])->responseFor(1)['result']['capabilities'] ?? [];

        $this->assertArrayNotHasKey(self::UI, $capabilities['extensions'] ?? []);
    }

    public function testAppsOnAdvertisesTheExtension(): void
    {
        $capabilities = $this->serve([])->responseFor(1)['result']['capabilities'] ?? [];

        $this->assertSame(['text/html;profile=mcp-app'], $capabilities['extensions'][self::UI]['mimeTypes'] ?? null);
    }

    public function testAnAppResourceIsSelfContainedHtml(): void
    {
        $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => [
            'uri' => 'ui://test/inbox',
        ]]]);

        $contents = $transport->responseFor(2)['result']['contents'][0] ?? [];
        $this->assertSame('text/html;profile=mcp-app', $contents['mimeType'] ?? null);
        $html = (string)($contents['text'] ?? '');
        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('globalThis.McpApps={', $html);
        $this->assertStringContainsString('<h1>Inbox: 3 orders to read</h1>', $html);
        $this->assertLessThan(strpos($html, 'new McpApps.App('), strpos($html, 'globalThis.McpApps={'));
        $this->assertSame(['https://api.example.com'], $contents['_meta']['ui']['csp']['connectDomains'] ?? null);
    }

    public function testATemplateErrorIsAReadError(): void
    {
        $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => [
            'uri' => 'ui://test/broken',
        ]]]);

        $this->assertArrayHasKey('error', $transport->responseFor(2) ?? []);
    }

    /**
     * @param list<array<string, mixed>> $requests
     * @param array<string, mixed> $capabilities The client's capabilities.
     */
    private function serve(array $requests, array $capabilities = []): RecordingTransport
    {
        $container = new Container();
        $identity = new CurrentIdentity();
        $container->addShared(CurrentIdentity::class, $identity);
        $transport = new RecordingTransport(array_map(fn(array $message): string => (string)json_encode($message), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => $capabilities === [] ? new stdClass() : $capabilities,
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ...$requests,
        ]));
        (new ServerFactory($container, $identity))->create(true)->run($transport);

        return $transport;
    }
}
