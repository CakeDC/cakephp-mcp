<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Catalog;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\TestSuite\McpTestTrait;
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

    public function testCatalogOffChangesNothing(): void
    {
        Configure::write('Mcp.catalog.enabled', false);

        $names = array_column($this->mcpListTools(), 'name');

        $this->assertContains('order_status', $names);
        $this->assertNotContains('find_tools', $names);
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
