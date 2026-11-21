<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\App;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\App\AppView;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Server\ServerFactory;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiResourcePermissions;
use Mcp\Server\Builder;
use stdClass;

/**
 * MCP Apps (TestApp\McpApps).
 */
class McpAppsTest extends TestCase
{
    private const UI = 'io.modelcontextprotocol/ui';

    /**
     * The capabilities of a client that renders apps.
     */
    private const APPS_CLIENT = ['extensions' => [self::UI => ['mimeTypes' => ['text/html;profile=mcp-app']]]];

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

    public function testEmptyUiMetaIsNeverSentAsAList(): void
    {
        $none = AppView::render('ui://test/x', 'McpApps/inbox', ['count' => 1], new UiResourceContentMeta());
        $this->assertArrayNotHasKey('_meta', (array)json_decode((string)json_encode($none), true));

        $empty = AppView::render('ui://test/x', 'McpApps/inbox', ['count' => 1], new UiResourceContentMeta(
            csp: new UiResourceCsp(),
            permissions: new UiResourcePermissions(),
            prefersBorder: true,
        ));
        $meta = json_encode($empty->jsonSerialize()['_meta'] ?? null);
        $this->assertSame('{"ui":{"prefersBorder":true}}', $meta);
    }

    public function testTheHookAndTheConfigMayBothEnableApps(): void
    {
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->enableExtension(new McpApps());
        });

        $capabilities = $this->serve([])->responseFor(1)['result']['capabilities'] ?? [];

        $this->assertArrayHasKey(self::UI, $capabilities['extensions'] ?? []);
    }

    public function testAppOnlyToolsAreListedForEveryClientWhenAppsAreOff(): void
    {
        // A 2.1 application that wrote `visibility: ["app"]` itself, without Mcp.apps.
        Configure::write('Mcp.apps.enabled', false);

        $this->assertArrayHasKey('inbox_rows', $this->toolsFor([]));
    }

    public function testATemplateErrorIsAReadError(): void
    {
        $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => [
            'uri' => 'ui://test/broken',
        ]]]);

        $this->assertArrayHasKey('error', $transport->responseFor(2) ?? []);
    }

    public function testMcpAppLinksTheToolToItsApp(): void
    {
        $tools = $this->toolsFor(self::APPS_CLIENT);

        $this->assertSame(
            ['resourceUri' => 'ui://test/inbox', 'visibility' => ['model', 'app']],
            $tools['show_inbox']['_meta']['ui'] ?? null,
        );
        $this->assertSame(['app'], $tools['inbox_rows']['_meta']['ui']['visibility'] ?? null);
    }

    public function testAppOnlyToolsAreHiddenFromClientsWithoutApps(): void
    {
        $tools = $this->toolsFor([]);

        $this->assertArrayHasKey('show_inbox', $tools);
        $this->assertArrayNotHasKey('inbox_rows', $tools);
    }

    public function testAppOnlyToolsAreListedForClientsWithApps(): void
    {
        $this->assertArrayHasKey('inbox_rows', $this->toolsFor(self::APPS_CLIENT));
    }

    public function testTheCatalogNeitherFindsNorRunsAppOnlyTools(): void
    {
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['*']]);

        $transport = $this->serve([
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'find_tools',
                'arguments' => ['query' => 'inbox'],
            ]],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name' => 'call_tools',
                'arguments' => ['calls' => [['name' => 'inbox_rows', 'arguments' => new stdClass()]]],
            ]],
        ], self::APPS_CLIENT);

        $found = array_column($transport->responseFor(2)['result']['structuredContent']['tools'] ?? [], 'name');
        // A tool with an app stays in tools/list: the host reads its _meta.ui there.
        $this->assertNotContains('show_inbox', $found);
        $this->assertNotContains('inbox_rows', $found);
        $result = $transport->responseFor(3)['result']['structuredContent']['results'][0] ?? [];
        $this->assertTrue($result['isError'] ?? false, (string)json_encode($transport->responseFor(3)));
    }

    public function testToolsWithAnAppStayListedWhenACatalogPatternMatchesThem(): void
    {
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['*']]);

        $forApps = $this->toolsFor(self::APPS_CLIENT);
        $this->assertArrayHasKey('find_tools', $forApps);
        $this->assertArrayHasKey('inbox_rows', $forApps);
        $this->assertSame('ui://test/inbox', $forApps['show_inbox']['_meta']['ui']['resourceUri'] ?? null);

        $forOthers = $this->toolsFor([]);
        $this->assertArrayHasKey('show_inbox', $forOthers);
        $this->assertArrayNotHasKey('inbox_rows', $forOthers);
    }

    public function testAppOnlyToolsAreStillGatedWhenCalledDirectly(): void
    {
        Configure::write('Mcp.authorization.authorizer', new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool !== 'inbox_rows';
            }
        });

        $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'inbox_rows',
            'arguments' => new stdClass(),
        ]]], self::APPS_CLIENT, trusted: false);

        $this->assertSame('Tool not found: "inbox_rows".', $transport->responseFor(2)['error']['message'] ?? null);
    }

    public function testAppToolCallsAreAuditedAsApp(): void
    {
        Log::setConfig('audit_apps', ['className' => ArrayLog::class, 'scopes' => ['mcp_audit']]);
        Configure::write('Mcp.audit.enabled', true);
        try {
            $this->serve([
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                    'name' => 'inbox_rows',
                    'arguments' => new stdClass(),
                ]],
                ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                    'name' => 'show_inbox',
                    'arguments' => new stdClass(),
                ]],
            ], self::APPS_CLIENT);

            $engine = Log::engine('audit_apps');
            $lines = array_map('strval', $engine instanceof ArrayLog ? $engine->read() : []);
            $this->assertCount(2, $lines);
            $this->assertStringContainsString('MCP tools/call inbox_rows ok', $lines[0]);
            $this->assertStringContainsString(' app=1', $lines[0]);
            $this->assertStringNotContainsString('app=', $lines[1]);
        } finally {
            Log::drop('audit_apps');
        }
    }

    /**
     * @param array<string, mixed> $capabilities The client's capabilities.
     * @return array<string, array<string, mixed>> The listed tools by name.
     */
    private function toolsFor(array $capabilities): array
    {
        $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']], $capabilities);

        return array_column($transport->responseFor(2)['result']['tools'] ?? [], null, 'name');
    }

    /**
     * @param list<array<string, mixed>> $requests
     * @param array<string, mixed> $capabilities The client's capabilities.
     */
    private function serve(array $requests, array $capabilities = [], bool $trusted = true): RecordingTransport
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
        (new ServerFactory($container, $identity))->create($trusted)->run($transport);

        return $transport;
    }
}
