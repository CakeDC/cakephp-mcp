<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Controller\McpController;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\Test\Support\TestJwt;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use TestApp\TestTokens;

/**
 * A named server on a route with parameters (`/mcp/{tenant}`): one config and one set of tools for every value,
 * the value reaching tools, its own OAuth resource per value, and audit records that carry it.
 */
class McpRouteParamsTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('mcp_oauth_test', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.servers.tenants', [
            'route' => '/mcp/t/{tenant}',
            'discovery' => ['scanDirs' => ['src/McpTenant']],
        ]);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_oauth_test');
        Router::reload();
        parent::tearDown();
    }

    private function external(): void
    {
        Configure::write('Mcp.servers.tenants.auth', [
            'mode' => 'external',
            'external' => ['issuer' => TestJwt::ISSUER, 'cache' => 'mcp_oauth_test', 'httpClient' => TestJwt::client()],
        ]);
    }

    private function call(string $path, string $token = TestTokens::EDITOR): void
    {
        $this->configRequest(['headers' => [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'tenant_info',
        ]]);
        $this->post($path, (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'tenant_info',
            'arguments' => new stdClass(),
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            ],
        ]]));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string)$this->_response?->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function testEachValueIsServedWithTheSameToolsAndToolsSeeIt(): void
    {
        $this->call('/mcp/t/acme');
        $this->assertResponseOk();
        $this->assertSame(
            ['service' => 'acme', 'all' => ['tenant' => 'acme'], 'param' => 'acme'],
            $this->json()['result']['structuredContent'] ?? null,
        );

        $this->call('/mcp/t/globex');
        $this->assertSame('globex', $this->json()['result']['structuredContent']['service'] ?? null);
    }

    public function testTheRouteFindsTheServer(): void
    {
        $this->assertSame('tenants', McpConfig::serverFor(new ServerRequest(['url' => '/mcp/t/acme'])));
        $this->assertSame('tenants', McpConfig::serverFor(new ServerRequest(['url' => '/.well-known/oauth-protected-resource/mcp/t/acme'])));
        $this->assertNull(McpConfig::serverFor(new ServerRequest(['url' => '/mcp/t/acme/more'])));
        $this->assertNull(McpConfig::serverFor(new ServerRequest(['url' => '/mcp/t'])));
    }

    public function testAValueThatIsNotASlugIsNotRouted(): void
    {
        $this->call('/mcp/t/a.b');

        // Not an MCP answer: the application's own (CSRF, then not found).
        $this->assertResponseError();
        $this->assertArrayNotHasKey('jsonrpc', $this->json());
    }

    public function testEachValueHasItsOwnResourceMetadata(): void
    {
        $this->external();

        $this->get('/.well-known/oauth-protected-resource/mcp/t/acme');
        $this->assertResponseOk();
        $this->assertSame('http://localhost/mcp/t/acme', $this->json()['resource'] ?? null);

        $this->get('/.well-known/oauth-protected-resource/mcp/t/globex');
        $this->assertSame('http://localhost/mcp/t/globex', $this->json()['resource'] ?? null);
    }

    public function testATokenForOneValueIsRefusedForAnother(): void
    {
        $this->external();
        $token = TestJwt::token(['aud' => 'http://localhost/mcp/t/acme']);

        $this->call('/mcp/t/acme', $token);
        $this->assertResponseOk();

        $this->call('/mcp/t/globex', $token);
        $this->assertResponseCode(401);
        $this->assertStringContainsString(
            'oauth-protected-resource/mcp/t/globex',
            $this->_response?->getHeaderLine('WWW-Authenticate') ?? '',
        );
    }

    public function testAuditRecordsCarryTheValue(): void
    {
        Log::setConfig('audit_route', ['className' => ArrayLog::class, 'scopes' => ['mcp_audit']]);
        Configure::write('Mcp.servers.tenants.audit', ['enabled' => true]);
        try {
            $this->call('/mcp/t/acme');

            $engine = Log::engine('audit_route');
            $lines = $engine instanceof ArrayLog ? $engine->read() : [];
            $this->assertCount(1, $lines);
            $this->assertStringContainsString(' server=tenants route=tenant:acme', (string)$lines[0]);
        } finally {
            Log::drop('audit_route');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badPatterns(): array
    {
        return [
            'a parameter inside a segment' => ['/mcp/t-{tenant}'],
            'an unclosed brace' => ['/mcp/{tenant'],
            'a name that is not a word' => ['/mcp/{ten-ant}'],
            'the same name twice' => ['/mcp/{a}/{a}'],
            // Cake would let it pick another server, a stdio only one included.
            'a reserved name' => ['/mcp/s/{server}'],
            'a routing key' => ['/mcp/{controller}'],
            'an underscore name' => ['/mcp/{_ext}'],
        ];
    }

    #[DataProvider('badPatterns')]
    public function testABadPatternStopsThatServerAndMcpCheckSaysWhy(string $route): void
    {
        Configure::write('Mcp.servers.tenants.route', $route);

        $this->assertArrayHasKey('tenants', McpConfig::serverErrors());
        $this->exec('mcp check');
        $this->assertOutputContains('[FAIL] Server tenants');
        $this->assertOutputContains('route');
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function requestsNamingAServer(): array
    {
        return [
            'a stdio only server named by a param' => ['/sneaky/local', null],
            'another server named by a param' => ['/sneaky/tenants', null],
            'the path of the server' => ['/mcp/t/acme', 'tenants'],
            'its metadata path' => ['/.well-known/oauth-protected-resource/mcp/t/acme', 'tenants'],
        ];
    }

    #[DataProvider('requestsNamingAServer')]
    public function testOnlyTheServerOfThePathIsServed(string $path, ?string $expected): void
    {
        // As if a route of the application passed a name as the server param.
        Configure::write('Mcp.servers.local', ['route' => false, 'identity' => ['required' => false]]);
        $request = (new ServerRequest(['url' => $path]))->withParam('server', $expected ?? basename($path));
        $controller = new McpController($request);

        $server = Closure::bind(fn(): ?string => $this->mcpServer(), $controller, McpController::class)();

        $this->assertSame($expected, $server);
    }

    public function testMcpCheckShowsThePattern(): void
    {
        $this->exec('mcp check --server tenants');

        $this->assertOutputContains('[PASS] Endpoint: http://localhost/mcp/t/{tenant} (one server per value of tenant)');
    }
}
