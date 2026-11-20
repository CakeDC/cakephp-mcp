<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Server;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;

class SchemaNormalizeTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpSchema']);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testDefaultConfigKeepsTheUnion(): void
    {
        $tool = $this->reportTool();

        $this->assertSame(['null', 'string'], $tool['inputSchema']['properties']['date_from']['type']);
    }

    public function testNormalizeRemovesTheUnionFromTheList(): void
    {
        Configure::write('Mcp.schema.normalize', true);

        $tool = $this->reportTool();

        $this->assertSame('string', $tool['inputSchema']['properties']['date_from']['type']);
        $this->assertArrayNotHasKey('default', $tool['inputSchema']['properties']['date_from']);
    }

    public function testANullArgumentIsStillAccepted(): void
    {
        Configure::write('Mcp.schema.normalize', true);

        $this->mcpCallTool('report', ['date_from' => null]);

        $this->assertMcpToolSucceeded();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportTool(): array
    {
        foreach ($this->mcpListTools() as $tool) {
            if (($tool['name'] ?? null) === 'report') {
                return $tool;
            }
        }
        $this->fail('The report tool is not listed.');
    }
}
