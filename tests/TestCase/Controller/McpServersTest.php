<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Test\Support\TestJwt;
use stdClass;
use TestApp\TestTokens;

/**
 * Several MCP servers in one application (`Mcp.servers`), over HTTP.
 */
class McpServersTest extends TestCase
{
    use IntegrationTestTrait;

    private const HANDSHAKE = '2025-06-18';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('mcp_oauth_test', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.servers.admin', [
            'route' => '/mcp/admin',
            'discovery' => ['scanDirs' => ['src/McpDocs']],
            'server' => ['name' => 'Admin MCP'],
        ]);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_oauth_test');
        Router::reload();
        parent::tearDown();
    }

    public function testEachServerListsItsOwnTools(): void
    {
        $default = $this->listTools('/mcp');
        $admin = $this->listTools('/mcp/admin');

        $this->assertContains('echo', $default);
        $this->assertNotContains('find_order', $default);
        $this->assertContains('find_order', $admin);
        $this->assertNotContains('echo', $admin);
    }

    public function testANamedServerWithoutAuthorizationInheritsTheDefaultAuthorizer(): void
    {
        Configure::write('Mcp.authorization.authorizer', new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool !== 'find_order';
            }
        });

        $this->assertNotContains('find_order', $this->listTools('/mcp/admin'));
        $headers = $this->session('/mcp/admin');
        $this->mcp('/mcp/admin', $this->call(3, 'find_order', ['number' => 'A-1']), $headers);
        $this->assertSame('Tool not found: "find_order".', $this->json()['error']['message'] ?? null);
    }

    public function testSessionsDoNotCrossServers(): void
    {
        $headers = $this->session('/mcp');

        $this->mcp('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers);

        $this->assertResponseCode(404);
    }

    public function testEachServerHasItsMetadataRoute(): void
    {
        Configure::write('Mcp.servers.admin.auth', [
            'mode' => 'external',
            'external' => ['issuer' => TestJwt::ISSUER, 'cache' => 'mcp_oauth_test', 'httpClient' => TestJwt::client()],
        ]);

        $this->get('/.well-known/oauth-protected-resource/mcp/admin');

        $this->assertResponseOk();
        $document = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('http://localhost/mcp/admin', $document['resource'] ?? null);
        $this->assertSame([TestJwt::ISSUER], $document['authorization_servers'] ?? null);

        $this->get('/.well-known/oauth-protected-resource/mcp');
        $this->assertResponseCode(404);
    }

    public function testA21ConfigStillServesMcp(): void
    {
        Configure::delete('Mcp.servers');

        $this->assertContains('echo', $this->listTools('/mcp'));
        // No route: the test application answers unknown paths with its error page, never an MCP answer.
        $this->mcp('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $this->assertResponseError();
        $this->assertArrayNotHasKey('jsonrpc', $this->json());
    }

    /**
     * @return list<string>
     */
    private function listTools(string $path): array
    {
        $headers = $this->session($path);
        $this->mcp($path, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers);
        $this->assertResponseOk();

        return array_column($this->json()['result']['tools'] ?? [], 'name');
    }

    /**
     * @return array<string, string> The headers of an initialized session on $path.
     */
    private function session(string $path): array
    {
        $this->mcp($path, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => self::HANDSHAKE,
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ]]);
        $this->assertResponseOk();
        $headers = [
            'Mcp-Session-Id' => $this->_response->getHeaderLine('Mcp-Session-Id'),
            'MCP-Protocol-Version' => self::HANDSHAKE,
        ];
        $this->mcp($path, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers);

        return $headers;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(int $id, string $tool, array $arguments): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name' => $tool,
            'arguments' => $arguments,
        ]];
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, string> $headers
     */
    private function mcp(string $path, array $message, array $headers = []): void
    {
        $headers += [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . TestTokens::EDITOR,
        ];
        $this->configRequest(['headers' => $headers]);
        $this->post($path, (string)json_encode($message));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string)$this->_response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
