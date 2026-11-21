<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\McpConfig;
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

    public function testANamedServerChecksItsOwnScopes(): void
    {
        Configure::write('Mcp.servers.admin.auth', [
            'mode' => 'external',
            'scopes' => ['tools' => ['find_order' => ['orders:read']]],
            'external' => ['issuer' => TestJwt::ISSUER, 'cache' => 'mcp_oauth_test', 'httpClient' => TestJwt::client()],
        ]);
        $token = TestJwt::token(['aud' => 'http://localhost/mcp/admin']);

        $this->modernCall('/mcp/admin', 'find_order', ['number' => 'A-1'], $token);
        $this->assertResponseCode(403);
        $header = $this->_response->getHeaderLine('WWW-Authenticate');
        $this->assertStringContainsString('error="insufficient_scope"', $header);
        $this->assertStringContainsString(
            'resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/admin"',
            $header,
        );

        $this->modernCall('/mcp/admin', 'find_order', ['number' => 'A-1'], TestJwt::token([
            'aud' => 'http://localhost/mcp/admin',
            'scope' => 'orders:read',
        ]));
        $this->assertResponseOk();

        // A token for the default server's resource is not one for the admin server.
        $this->modernCall('/mcp/admin', 'find_order', ['number' => 'A-1'], TestJwt::token(['scope' => 'orders:read']));
        $this->assertResponseCode(401);
    }

    public function testANamedServerAuditsWithItsName(): void
    {
        Log::setConfig('audit_servers', ['className' => ArrayLog::class, 'scopes' => ['mcp_audit']]);
        Configure::write('Mcp.servers.admin.audit', ['enabled' => true]);
        try {
            $headers = $this->session('/mcp/admin');
            $this->mcp('/mcp/admin', $this->call(3, 'find_order', ['number' => 'A-1']), $headers);

            $engine = Log::engine('audit_servers');
            $lines = $engine instanceof ArrayLog ? $engine->read() : [];
            $this->assertCount(1, $lines);
            $this->assertStringContainsString('MCP tools/call find_order ok', (string)$lines[0]);
            $this->assertStringContainsString(' server=admin', (string)$lines[0]);
        } finally {
            Log::drop('audit_servers');
        }
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
     * A stateless 2026-07-28 tools/call with a bearer token.
     *
     * @param array<string, mixed> $arguments
     */
    private function modernCall(string $path, string $tool, array $arguments, string $token): void
    {
        $this->configRequest(['headers' => [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => $tool,
        ]]);
        $this->post($path, (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => $tool,
            'arguments' => $arguments,
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            ],
        ]]));
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

    public function testANamedServerWithoutRouteIsStdioOnly(): void
    {
        Configure::write('Mcp.servers.local', ['route' => false]);
        Router::reload();

        $this->assertArrayNotHasKey('local', McpConfig::serverErrors());
        $this->assertContains('local', McpConfig::servers());
        $this->assertNull(McpConfig::serverFor(new ServerRequest(['url' => '/mcp/local'])));
        $this->get('/mcp/local');
        $this->assertResponseCode(404);
        // The other servers are still served.
        $this->assertContains('find_order', $this->listTools('/mcp/admin'));
    }
}
