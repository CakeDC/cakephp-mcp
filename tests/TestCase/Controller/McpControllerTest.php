<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\McpConfig;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Server\Builder;
use Mcp\Server\Wire\CachePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use TestApp\AuthorizationSpy;
use TestApp\TestTokens;

class McpControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private const HANDSHAKE = '2025-06-18';
    private const MODERN = '2026-07-28';

    protected function setUp(): void
    {
        parent::setUp();
        AuthorizationSpy::$skipped = false;
    }

    public function testHandshakeInitializeReturnsServerInfoAndASession(): void
    {
        $this->mcp($this->initialize(1));

        $this->assertResponseOk();
        $this->assertSame('CakePHP MCP Server', $this->json()['result']['serverInfo']['name'] ?? null);
        $this->assertNotSame('', $this->_response->getHeaderLine('Mcp-Session-Id'));
    }

    public function testHandshakeSessionPersistsAcrossRequests(): void
    {
        $this->mcp($this->initialize(1));
        $headers = [
            'Mcp-Session-Id' => $this->_response->getHeaderLine('Mcp-Session-Id'),
            'MCP-Protocol-Version' => self::HANDSHAKE,
        ];

        $this->mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers);
        $this->assertResponseCode(202);

        $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers);
        $this->assertResponseOk();
        $this->assertContains('echo', $this->toolNames());

        $this->mcp($this->handshakeCall(3, 'echo', ['text' => 'over the session']), $headers);
        $this->assertSame('over the session', $this->json()['result']['content'][0]['text'] ?? null);
    }

    public function testModernEraListsAndCallsWithoutASession(): void
    {
        $this->mcp($this->modern(1, 'tools/list'), $this->modernHeaders('tools/list'));
        $this->assertResponseOk();
        $this->assertContains('echo', $this->toolNames());

        $this->mcp(
            $this->modern(2, 'tools/call', ['name' => 'echo', 'arguments' => ['text' => 'stateless']]),
            $this->modernHeaders('tools/call', 'echo'),
        );
        $this->assertResponseOk();
        $this->assertSame('stateless', $this->json()['result']['content'][0]['text'] ?? null);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidUtf8Headers(): array
    {
        return [
            'Mcp-Name' => ['Mcp-Name', "ech\xffo"],
            'Mcp-Method' => ['Mcp-Method', "tools/\xc3call"],
            'Mcp-Param' => ['Mcp-Param-Text', "\xe2\x82"],
        ];
    }

    #[DataProvider('invalidUtf8Headers')]
    public function testAnMcpHeaderThatIsNotUtf8IsABadRequest(string $header, string $value): void
    {
        $headers = $this->modernHeaders('tools/call', 'echo');
        $headers[$header] = $value;

        $this->mcp($this->modern(1, 'tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hi']]), $headers);

        $this->assertResponseCode(400);
        $this->assertSame(-32600, $this->json()['error']['code'] ?? null, (string)$this->_response?->getBody());
    }

    public function testAPathEndingInALineFeedIsNotTheEndpoint(): void
    {
        // Cake's route pattern ends in $, which also matches before a final line feed: /mcp%0A would reach the
        // controller while McpConfig::isEndpoint() says it is not the endpoint (a rate limit before routing).
        $this->mcp($this->modern(1, 'tools/list'), $this->modernHeaders('tools/list'), '/mcp%0A');

        $this->assertResponseCode(404);
        $this->assertFalse(McpConfig::isEndpoint(new ServerRequest(['url' => '/mcp%0A'])));
    }

    public function testModernListCarriesPrivateNoCacheHintsByDefault(): void
    {
        $this->mcp($this->modern(1, 'tools/list'), $this->modernHeaders('tools/list'));

        $this->assertResponseOk();
        $this->assertSame(0, $this->json()['result']['ttlMs'] ?? null);
        $this->assertSame('private', $this->json()['result']['cacheScope'] ?? null);
    }

    public function testServerCacheSetsTheHints(): void
    {
        Configure::write('Mcp.server.cache', [
            'ttl' => 60000,
            'scope' => 'public',
            'methods' => ['tools/list' => ['ttl' => 300000]],
        ]);

        $this->mcp($this->modern(1, 'tools/list'), $this->modernHeaders('tools/list'));

        $this->assertResponseOk();
        $this->assertSame(300000, $this->json()['result']['ttlMs'] ?? null);
        $this->assertSame('public', $this->json()['result']['cacheScope'] ?? null);
    }

    public function testAToolListFilteredByAnAuthorizerIsNeverPublic(): void
    {
        Configure::write('Mcp.authorization.authorizer', new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool !== 'admin_only';
            }
        });
        Configure::write('Mcp.server.cache', ['ttl' => 60000, 'scope' => 'public']);

        $this->mcp($this->modern(1, 'tools/list'), $this->modernHeaders('tools/list'));
        $this->assertSame(60000, $this->json()['result']['ttlMs'] ?? null);
        $this->assertSame('private', $this->json()['result']['cacheScope'] ?? null);

        // A policy the hook sets is no exception.
        Configure::write('Mcp.server.cache', null);
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->setCachePolicy(CachePolicy::default(30000, CacheScope::Public));
        });
        $this->mcp($this->modern(2, 'tools/list'), $this->modernHeaders('tools/list'));
        $this->assertSame(30000, $this->json()['result']['ttlMs'] ?? null);
        $this->assertSame('private', $this->json()['result']['cacheScope'] ?? null);
    }

    public function testAToolListThatDependsOnTheClientsAppsSupportIsNeverPublic(): void
    {
        // With MCP Apps on, a client that renders apps gets app-only tools listed and one that does not, not:
        // a shared cache keyed by method and cursor would serve one client's list to the other.
        Configure::write('Mcp.apps.enabled', true);
        Configure::write('Mcp.server.cache', ['ttl' => 60000, 'scope' => 'public']);

        $this->mcp($this->modern(1, 'tools/list'), $this->modernHeaders('tools/list'));

        $this->assertResponseOk();
        $this->assertSame(60000, $this->json()['result']['ttlMs'] ?? null);
        $this->assertSame('private', $this->json()['result']['cacheScope'] ?? null);
    }

    public function testANamedServerTurnsInheritedHintsOffWithAnEmptyArrayOrNull(): void
    {
        Configure::write('Mcp.server.cache', ['ttl' => 60000, 'scope' => 'public']);
        foreach ([[], null] as $off) {
            Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'server' => ['cache' => $off]]);

            $this->assertNull(McpConfig::get('server.cache', 'admin'));
        }
    }

    public function testHandshakeEraHasNoCacheHints(): void
    {
        Configure::write('Mcp.server.cache', ['ttl' => 60000, 'scope' => 'public']);
        $this->mcp($this->initialize(1));
        $headers = [
            'Mcp-Session-Id' => $this->_response->getHeaderLine('Mcp-Session-Id'),
            'MCP-Protocol-Version' => self::HANDSHAKE,
        ];
        $this->mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers);

        $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers);

        $this->assertResponseOk();
        $this->assertArrayNotHasKey('ttlMs', $this->json()['result'] ?? []);
    }

    public function testModernEraSendsAListAsStructuredContentToo(): void
    {
        // docs/tools.md, Results: under 2026-07-28 a list also travels as structuredContent.
        Configure::write('Mcp.discovery.scanDirs', ['src/McpDocs']);

        $this->mcp(
            $this->modern(1, 'tools/call', ['name' => 'order_numbers', 'arguments' => new stdClass()]),
            $this->modernHeaders('tools/call', 'order_numbers'),
        );

        $this->assertResponseOk();
        $this->assertSame(['A-1', 'A-2'], $this->json()['result']['structuredContent'] ?? null);
    }

    public function testJsonBodyIsReadAfterBodyParserMiddleware(): void
    {
        $this->mcp(
            $this->modern(1, 'tools/call', ['name' => 'echo', 'arguments' => ['text' => 'parsed twice']]),
            $this->modernHeaders('tools/call', 'echo'),
        );

        $this->assertSame('parsed twice', $this->json()['result']['content'][0]['text'] ?? null);
    }

    public function testToolsSeeTheAuthenticatedIdentity(): void
    {
        $this->mcp(
            $this->modern(1, 'tools/call', ['name' => 'whoami', 'arguments' => new stdClass()]),
            $this->modernHeaders('tools/call', 'whoami'),
        );

        $whoami = json_decode((string)($this->json()['result']['content'][0]['text'] ?? ''), true);
        $this->assertSame(['identifier' => 1, 'subject' => '1'], $whoami);
    }

    public function testMissingTokenIsChallenged(): void
    {
        $this->mcp($this->initialize(1), ['Authorization' => '']);

        $this->assertResponseCode(401);
        $this->assertHeader('WWW-Authenticate', 'Bearer');
    }

    public function testNullRequiredStillChallenges(): void
    {
        Configure::write('Mcp.identity.required', null);

        $this->mcp($this->initialize(1), ['Authorization' => '']);

        $this->assertResponseCode(401);
    }

    public function testInvalidTokenIsChallenged(): void
    {
        $this->mcp($this->initialize(1), ['Authorization' => 'Bearer nope']);

        $this->assertResponseCode(401);
    }

    public function testSessionDeleteWithoutTokenIsChallenged(): void
    {
        $this->configRequest(['headers' => ['Host' => 'localhost', 'Mcp-Session-Id' => 'anything']]);
        $this->delete('/mcp');

        $this->assertResponseCode(401);
    }

    public function testCorsPreflightIsNotChallenged(): void
    {
        $this->configRequest(['headers' => ['Host' => 'localhost']]);
        $this->options('/mcp');

        $this->assertResponseCode(204);
    }

    public function testForeignHostIsRejected(): void
    {
        $this->mcp($this->initialize(1), ['Host' => 'evil.example']);

        $this->assertResponseCode(403);
    }

    public function testFullBaseUrlHostIsAccepted(): void
    {
        Configure::write('App.fullBaseUrl', 'https://mcp.example.com');

        $this->mcp($this->initialize(1), ['Host' => 'mcp.example.com']);

        $this->assertResponseOk();
    }

    public function testRbacHidesAndRejectsToolsPerRoleInBothEras(): void
    {
        Configure::write('Mcp.authorization', [
            'authorizer' => RbacToolAuthorizer::class,
            'rbac' => ['log' => false, 'permissions' => [
                ['role' => 'editor', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => ['echo', 'whoami']],
                ['role' => 'admin', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => '*'],
            ]],
        ]);

        $this->mcp($this->modern(1, 'tools/list'), $this->modernHeaders('tools/list'));
        $this->assertNotContains('admin_only', $this->toolNames());

        $this->mcp(
            $this->modern(2, 'tools/call', ['name' => 'admin_only', 'arguments' => new stdClass()]),
            $this->modernHeaders('tools/call', 'admin_only'),
        );
        $this->assertSame('Tool not found: "admin_only".', $this->json()['error']['message'] ?? null);

        $this->mcp($this->initialize(3));
        $session = [
            'Mcp-Session-Id' => $this->_response->getHeaderLine('Mcp-Session-Id'),
            'MCP-Protocol-Version' => self::HANDSHAKE,
        ];
        $this->mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session);
        $this->mcp($this->handshakeCall(4, 'admin_only'), $session);
        $this->assertSame('Tool not found: "admin_only".', $this->json()['error']['message'] ?? null);

        $this->mcp($this->modern(5, 'tools/list'), $this->modernHeaders('tools/list') + [
            'Authorization' => 'Bearer ' . TestTokens::ADMIN,
        ]);
        $this->assertContains('admin_only', $this->toolNames());
    }

    public function testToolFailureDoesNotLeakDetails(): void
    {
        $this->mcp(
            $this->modern(1, 'tools/call', ['name' => 'explode', 'arguments' => new stdClass()]),
            $this->modernHeaders('tools/call', 'explode'),
        );

        // The SDK answers an unhandled tool exception with HTTP 400 and this JSON-RPC error, which proves
        // the call was not rejected earlier (that would be 401, 403 or 404, or "Tool not found").
        $this->assertResponseCode(400);
        $this->assertSame(
            ['code' => -32603, 'message' => 'Error while executing tool'],
            $this->json()['error'] ?? null,
        );
        $body = (string)$this->_response->getBody();
        $this->assertStringNotContainsString('secret details', $body);
        $this->assertStringNotContainsString('#0 ', $body);
    }

    public function testServerBuildFailureIsAJsonRpcInternalError(): void
    {
        Configure::write('Mcp.authorization.authorizer', 'NotAnAuthorizer');

        $this->mcp($this->initialize(1));

        $this->assertResponseCode(500);
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Internal error']],
            $this->json(),
        );
        $this->assertStringNotContainsString('NotAnAuthorizer', (string)$this->_response->getBody());
    }

    public function testHostAuthorizationCheckIsSkipped(): void
    {
        $this->mcp($this->initialize(1));

        $this->assertTrue(AuthorizationSpy::$skipped);
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, string> $headers Overrides; an empty Authorization removes it.
     */
    private function mcp(array $message, array $headers = [], string $path = '/mcp'): void
    {
        $headers += [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . TestTokens::EDITOR,
        ];
        $this->configRequest(['headers' => array_filter($headers, fn(string $value): bool => $value !== '')]);
        $this->post($path, (string)json_encode($message));
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(int $id): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'initialize', 'params' => [
            'protocolVersion' => self::HANDSHAKE,
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ]];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function handshakeCall(int $id, string $tool, array $arguments = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name' => $tool,
            'arguments' => $arguments === [] ? new stdClass() : $arguments,
        ]];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function modern(int $id, string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => self::MODERN,
            'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
        ];

        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params];
    }

    /**
     * @return array<string, string>
     */
    private function modernHeaders(string $method, ?string $name = null): array
    {
        $headers = ['MCP-Protocol-Version' => self::MODERN, 'Mcp-Method' => $method];
        if ($name !== null) {
            $headers['Mcp-Name'] = $name;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string)$this->_response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function toolNames(): array
    {
        return array_column($this->json()['result']['tools'] ?? [], 'name');
    }
}
