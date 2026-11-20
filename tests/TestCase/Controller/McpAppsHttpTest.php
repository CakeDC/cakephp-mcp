<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use stdClass;
use TestApp\TestTokens;

/**
 * App-only tools in the 2026-07-28 era, where the client's capabilities come with every request.
 */
class McpAppsHttpTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Mcp.discovery.scanDirs', ['src/McpApps']);
        Configure::write('Mcp.apps.enabled', true);
    }

    public function testAClientWithAppsGetsAppOnlyTools(): void
    {
        $names = $this->listTools(['extensions' => ['io.modelcontextprotocol/ui' => ['mimeTypes' => ['text/html;profile=mcp-app']]]]);

        $this->assertContains('inbox_rows', $names);
        $this->assertContains('show_inbox', $names);
    }

    public function testAClientWithoutAppsDoesNot(): void
    {
        $names = $this->listTools([]);

        $this->assertNotContains('inbox_rows', $names);
        $this->assertContains('show_inbox', $names);
    }

    /**
     * @param array<string, mixed> $capabilities
     * @return list<string>
     */
    private function listTools(array $capabilities): array
    {
        $this->configRequest(['headers' => [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . TestTokens::EDITOR,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/list',
        ]]);
        $this->post('/mcp', (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => $capabilities === [] ? new stdClass() : $capabilities,
            ],
        ]]));
        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);

        return array_column($body['result']['tools'] ?? [], 'name');
    }
}
