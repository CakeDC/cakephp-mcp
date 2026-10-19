<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use CakeDC\OAuthServer\TestSuite\OAuthTestTrait;
use stdClass;
use TestApp\OAuthTestConfig;

/**
 * The HTTP test example of docs/oauth.md (Testing), with the test app's resource, scope and tool in place
 * of `https://example.com/mcp`, `content:read` and `publish_post`. Keep the two in sync.
 */
class OAuthDocsExampleTest extends TestCase
{
    use IntegrationTestTrait;
    use OAuthTestTrait;

    protected array $fixtures = OAuthFixtures::ALL;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        OAuthServer::reset();
    }

    protected function tearDown(): void
    {
        OAuthServer::reset();
        parent::tearDown();
    }

    public function testReaderCanListTools(): void
    {
        $token = $this->oauthTokenFor(1, ['notes:read'], 'http://localhost/mcp');
        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json, text/event-stream',
                'Content-Type' => 'application/json',
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => 'tools/list',
            ],
        ]);

        $this->post('/mcp', (string)json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            ]],
        ]));

        $this->assertResponseOk();
        $tools = json_decode((string)$this->_response?->getBody(), true)['result']['tools'] ?? [];
        $this->assertContains('create_note', array_column($tools, 'name'));
    }
}
