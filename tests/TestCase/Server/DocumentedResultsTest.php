<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Server;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;

/**
 * What a client receives from a tool, as the Results section of docs/tools.md says
 * (TestApp\McpDocs\ResultTools mirrors its examples).
 */
class DocumentedResultsTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpDocs']);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testAnArrayIsSentAsTextAndStructuredContent(): void
    {
        $this->mcpCallTool('find_order', ['number' => 'A-1']);

        $expected = ['number' => 'A-1', 'status' => 'open', 'total' => 12.5];
        $this->assertMcpToolSucceeded();
        $this->assertSame(json_encode($expected, JSON_PRETTY_PRINT), $this->mcpResultText());
        $this->assertSame($expected, $this->mcpResult()['structuredContent']);
    }

    public function testAListIsSentAsTextOnly(): void
    {
        $this->mcpCallTool('order_numbers');

        $this->assertMcpToolSucceeded();
        $this->assertSame(json_encode(['A-1', 'A-2'], JSON_PRETTY_PRINT), $this->mcpResultText());
        $this->assertArrayNotHasKey('structuredContent', $this->mcpResult());
    }

    public function testAStringIsSentAsTextOnly(): void
    {
        $this->mcpCallTool('order_status', ['number' => 'A-1']);

        $this->assertMcpToolSucceeded();
        $this->assertSame('open', $this->mcpResultText());
        $this->assertArrayNotHasKey('structuredContent', $this->mcpResult());
    }

    public function testOutputSchemaIsListedAndStructuredContentSent(): void
    {
        $tool = $this->tool('order_summary');
        $this->assertSame(['total'], $tool['outputSchema']['required']);

        $this->mcpCallTool('order_summary');

        $this->assertMcpToolSucceeded();
        $this->assertSame('2 open orders, 25.50 in total.', $this->mcpResultText());
        $this->assertMcpStructuredContent(['total' => 25.5, 'count' => 2]);
    }

    public function testACompactResultIsOneLineOfJson(): void
    {
        $this->mcpCallTool('orders_compact');

        $this->assertMcpToolSucceeded();
        $this->assertStringNotContainsString("\n", $this->mcpResultText());
        $this->assertArrayNotHasKey('structuredContent', $this->mcpResult());
    }

    public function testStructuredContentThatBreaksTheOutputSchemaIsAToolError(): void
    {
        $this->mcpCallTool('bad_summary');

        $this->assertMcpToolError();
        $this->assertMcpResultContains('Invalid structured output');
    }

    /**
     * @return array<string, mixed>
     */
    private function tool(string $name): array
    {
        foreach ($this->mcpListTools() as $tool) {
            if (($tool['name'] ?? null) === $name) {
                return $tool;
            }
        }
        $this->fail(sprintf('The %s tool is not listed.', $name));
    }
}
