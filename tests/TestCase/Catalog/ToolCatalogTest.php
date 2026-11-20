<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Catalog;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Server\ServerFactory;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use Mcp\Server\Authorization\AccessToken;
use stdClass;

/**
 * The tool catalog (`Mcp.catalog`) over the tools of TestApp\McpDocs.
 */
class ToolCatalogTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpDocs']);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['order_*', 'find_order', 'list_orders']]);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        Cache::drop('mcp_oauth_test');
        parent::tearDown();
    }

    public function testCataloguedToolsLeaveTheListButStayCallable(): void
    {
        $names = array_column($this->mcpListTools(), 'name');

        $this->assertContains('find_tools', $names);
        $this->assertContains('call_tools', $names);
        $this->assertContains('orders_compact', $names);
        $this->assertNotContains('order_status', $names);
        $this->assertNotContains('find_order', $names);

        $this->mcpCallTool('order_status', ['number' => 'A-1']);
        $this->assertMcpToolSucceeded();
    }

    public function testFindToolsRanksByNameTitleAndDescription(): void
    {
        $this->mcpCallTool('find_tools', ['query' => 'status']);

        $this->assertMcpToolSucceeded();
        $tools = $this->mcpResult()['structuredContent']['tools'];
        $this->assertSame('order_status', $tools[0]['name']);
        $this->assertSame(['number'], $tools[0]['inputSchema']['required']);
        $this->assertNotContains('orders_compact', array_column($tools, 'name'));
    }

    public function testFindToolsWithAnEmptyQuery(): void
    {
        $this->mcpCallTool('find_tools', ['limit' => 3]);

        $this->assertMcpToolSucceeded();
        $this->assertCount(3, $this->mcpResult()['structuredContent']['tools']);
    }

    public function testFindToolsHidesToolsTheCallerCannotCall(): void
    {
        Configure::write('Mcp.authorization.authorizer', $this->denying('order_status'));

        $this->mcpCallTool('find_tools', ['query' => 'status']);

        $this->assertMcpToolSucceeded();
        $this->assertNotContains('order_status', array_column($this->mcpResult()['structuredContent']['tools'], 'name'));
    }

    public function testCallToolsRunsInOrderAndStopsAtTheFirstError(): void
    {
        $this->mcpCallTool('call_tools', ['calls' => [
            ['name' => 'order_status', 'arguments' => ['number' => 'A-1']],
            ['name' => 'find_order', 'arguments' => ['number' => 7]],
            ['name' => 'order_numbers', 'arguments' => new stdClass()],
        ]]);

        $this->assertMcpToolError();
        $results = $this->mcpResult()['structuredContent']['results'];
        $this->assertCount(2, $results);
        $this->assertSame(['order_status', 'find_order'], array_column($results, 'name'));
        $this->assertFalse($results[0]['isError']);
        $this->assertSame('open', $results[0]['content'][0]['text']);
        $this->assertTrue($results[1]['isError']);
        $this->assertStringContainsString("Invalid parameters for tool 'find_order'", $results[1]['content'][0]['text']);
    }

    public function testCallToolsDeniesLikeAnUnknownTool(): void
    {
        Configure::write('Mcp.authorization.authorizer', $this->denying('order_status'));

        $this->mcpCallTool('call_tools', ['calls' => [['name' => 'order_status', 'arguments' => ['number' => 'A-1']]]]);

        $this->assertMcpToolError();
        $result = $this->mcpResult()['structuredContent']['results'][0];
        $this->assertSame('Tool not found: "order_status".', $result['content'][0]['text']);
    }

    public function testCallToolsRefusesToolsOutsideTheCatalogAndItself(): void
    {
        foreach (['orders_compact', 'call_tools', 'find_tools', 'no_such_tool'] as $name) {
            $this->mcpCallTool('call_tools', ['calls' => [['name' => $name, 'arguments' => new stdClass()]]]);

            $this->assertMcpToolError();
            $result = $this->mcpResult()['structuredContent']['results'][0];
            $this->assertSame(sprintf('Tool not found: "%s".', $name), $result['content'][0]['text'], $name);
        }
    }

    public function testArgumentsThatAreNotAnObjectAreAnError(): void
    {
        $this->mcpCallTool('call_tools', ['calls' => [['name' => 'order_numbers', 'arguments' => 'all']]]);

        $this->assertMcpToolError();
        $result = $this->mcpResult()['structuredContent']['results'][0];
        $this->assertSame('The arguments of order_numbers must be an object.', $result['content'][0]['text']);
    }

    public function testCallToolsRespectsMaxCalls(): void
    {
        $call = ['name' => 'order_numbers', 'arguments' => new stdClass()];

        $this->mcpCallTool('call_tools', ['calls' => array_fill(0, 6, $call)]);

        $this->assertMcpJsonRpcError(-32602);
    }

    public function testScopeSimulationCoversInnerCalls(): void
    {
        $this->scoped();
        $this->mcpActingAs(['id' => 1], ['notes:read']);

        $this->mcpCallTool('call_tools', ['calls' => [['name' => 'order_status', 'arguments' => ['number' => 'A-1']]]]);

        $this->assertMcpInsufficientScope(['orders:read']);
    }

    public function testFindToolsHidesToolsTheTokenCannotCall(): void
    {
        $this->scoped();
        $this->mcpActingAs(['id' => 1], ['notes:read']);

        $this->mcpCallTool('find_tools', ['query' => 'order']);

        $this->assertMcpToolSucceeded();
        $names = array_column($this->mcpResult()['structuredContent']['tools'], 'name');
        $this->assertNotContains('order_status', $names);
        $this->assertContains('order_numbers', $names);
    }

    public function testEachInnerCallChecksItsScopesInTheServer(): void
    {
        // Straight to the server, past the HTTP step and the trait's simulation: only the server's own check stands.
        $this->scoped();
        $container = new Container();
        $identity = new CurrentIdentity();
        $container->addShared(CurrentIdentity::class, $identity);
        $identity->set(['id' => 1]);
        $transport = new RecordingTransport(array_map(fn(array $message): string => (string)json_encode($message), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'call_tools',
                'arguments' => ['calls' => [['name' => 'order_status', 'arguments' => ['number' => 'A-1']]]],
            ]],
        ]), new AccessToken(['notes:read'], ['sub' => '1']));

        (new ServerFactory($container, $identity))->create()->run($transport);

        $result = $transport->responseFor(2)['result']['structuredContent']['results'][0] ?? [];
        $this->assertTrue($result['isError'] ?? false);
        $this->assertSame('Tool not found: "order_status".', $result['content'][0]['text'] ?? null);
    }

    public function testCatalogOffChangesNothing(): void
    {
        Configure::write('Mcp.catalog.enabled', false);

        $names = array_column($this->mcpListTools(), 'name');

        $this->assertContains('order_status', $names);
        $this->assertNotContains('find_tools', $names);
    }

    private function scoped(): void
    {
        Cache::setConfig('mcp_oauth_test', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            'scopes' => ['tools' => ['order_status' => ['orders:read']]],
            'external' => ['issuer' => 'https://issuer.example', 'cache' => 'mcp_oauth_test'],
        ]);
    }

    private function denying(string $denied): ToolAuthorizerInterface
    {
        return new class ($denied) implements ToolAuthorizerInterface {
            public function __construct(private readonly string $denied)
            {
            }

            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool !== $this->denied;
            }
        };
    }
}
