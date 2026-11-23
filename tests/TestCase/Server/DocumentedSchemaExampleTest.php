<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Server;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;

/**
 * The Input schemas example of docs/tools.md (TestApp\McpDocs\OrderTools) must work for a model that follows it.
 */
class DocumentedSchemaExampleTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpDocs']);
        Configure::write('Mcp.schema.normalize', true);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testEveryAdvertisedDefaultIsAccepted(): void
    {
        $properties = $this->tool('list_orders')['inputSchema']['properties'];
        foreach ($properties as $name => $property) {
            if (!array_key_exists('default', $property)) {
                continue;
            }

            $this->mcpCallTool('list_orders', [$name => $property['default']]);

            $this->assertMcpToolSucceeded(sprintf('Sending the advertised default of "%s" fails.', $name));
        }
    }

    public function testLeavingEveryArgumentOutWorks(): void
    {
        $this->mcpCallTool('list_orders');

        $this->assertMcpToolSucceeded();
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
