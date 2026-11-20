<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\TestJwt;
use stdClass;

/**
 * The scopes of the tools `call_tools` runs, over HTTP (external mode, TestApp\McpDocs).
 */
class McpCatalogScopeTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('mcp_oauth_test', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpDocs']);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['order_*', 'find_order']]);
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            'scopes' => [
                'tools' => ['order_status' => ['orders:read'], 'find_order' => ['orders:admin']],
                'implies' => ['orders:admin' => ['orders:read']],
            ],
            'external' => ['issuer' => TestJwt::ISSUER, 'cache' => 'mcp_oauth_test', 'httpClient' => TestJwt::client()],
        ]);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_oauth_test');
        parent::tearDown();
    }

    public function testAnInnerToolScopeIsRequiredOverHttp(): void
    {
        $this->callTools([['name' => 'order_status', 'arguments' => ['number' => 'A-1']]], 'notes:read');

        $this->assertResponseCode(403);
        $header = $this->_response->getHeaderLine('WWW-Authenticate');
        $this->assertStringContainsString('resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"', $header);
        $this->assertStringContainsString('scope="orders:read"', $header);
        $this->assertStringContainsString('error="insufficient_scope"', $header);

        $this->callTools([['name' => 'order_status', 'arguments' => ['number' => 'A-1']]], 'orders:read');
        $this->assertResponseOk();
    }

    public function testImpliedScopesSatisfyInnerCalls(): void
    {
        $this->callTools([
            ['name' => 'order_status', 'arguments' => ['number' => 'A-1']],
            ['name' => 'find_order', 'arguments' => ['number' => 'A-1']],
        ], 'orders:admin');

        $this->assertResponseOk();
    }

    public function testBatchAddsUpInnerScopes(): void
    {
        // A token that covers only the first call.
        $this->configRequest(['headers' => $this->headers('orders:read')]);
        $this->post('/mcp', (string)json_encode([
            $this->message(1, [['name' => 'order_status', 'arguments' => ['number' => 'A-1']]]),
            $this->message(2, [['name' => 'find_order', 'arguments' => ['number' => 'A-1']]]),
        ]));

        $this->assertResponseCode(403);
        $this->assertStringContainsString('scope="orders:admin orders:read"', $this->_response->getHeaderLine('WWW-Authenticate'));
    }

    /**
     * @param list<array<string, mixed>> $calls
     */
    private function callTools(array $calls, string $scope): void
    {
        $this->configRequest(['headers' => $this->headers($scope) + [
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'call_tools',
        ]]);
        $this->post('/mcp', (string)json_encode($this->message(1, $calls)));
    }

    /**
     * @param list<array<string, mixed>> $calls
     * @return array<string, mixed>
     */
    private function message(int $id, array $calls): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name' => 'call_tools',
            'arguments' => ['calls' => $calls],
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            ],
        ]];
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $scope): array
    {
        return [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . TestJwt::token(['scope' => $scope]),
        ];
    }
}
