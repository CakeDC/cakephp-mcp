<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use stdClass;
use TestApp\OAuthTestConfig;

/**
 * In oauth mode on a route with parameters, a value whose resource is not declared is not served: 404, with
 * nothing logged, since anyone can ask for any value.
 */
class OAuthRouteParamsTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = OAuthFixtures::ALL;

    protected function setUp(): void
    {
        parent::setUp();
        $resources = (array)Configure::read('OAuthServer.resources');
        $resources['http://localhost/mcp/t/acme'] = $resources[OAuthTestConfig::MCP];
        Configure::write('OAuthServer.resources', $resources);
        OAuthServer::reset();
        Configure::write('Mcp.servers.tenants', [
            'route' => '/mcp/t/{tenant}',
            'discovery' => ['scanDirs' => ['src/McpTenant']],
            'auth' => ['mode' => 'oauth'],
        ]);
        Log::setConfig('route_errors', ['className' => ArrayLog::class, 'levels' => ['error']]);
    }

    protected function tearDown(): void
    {
        Log::drop('route_errors');
        OAuthServer::reset();
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function errors(): array
    {
        $engine = Log::engine('route_errors');

        return $engine instanceof ArrayLog ? $engine->read() : [];
    }

    private function listTools(string $path): void
    {
        $this->configRequest(['headers' => [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/list',
        ]]);
        $this->post($path, (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            ],
        ]]));
    }

    public function testADeclaredValueAsksForAToken(): void
    {
        $this->listTools('/mcp/t/acme');

        $this->assertResponseCode(401);
        $this->assertSame([], $this->errors());
    }

    public function testAValueInTheResourcesHostIsServedOnlyInLowercase(): void
    {
        // The host of the resource is lowercased, so ACME would share acme's resource and audience while the tool
        // got "ACME": one spelling per value.
        $resources = (array)Configure::read('OAuthServer.resources');
        $resources['https://acme.example.com/mcp'] = $resources[OAuthTestConfig::MCP];
        Configure::write('OAuthServer.resources', $resources);
        OAuthServer::reset();
        Configure::write('Mcp.servers.hosts', [
            'route' => '/mcp/h/{tenant}',
            'discovery' => ['scanDirs' => ['src/McpTenant']],
            'auth' => ['mode' => 'oauth', 'resource' => 'https://{tenant}.example.com/mcp'],
        ]);

        $this->listTools('/mcp/h/acme');
        $this->assertResponseCode(401);

        foreach (['/mcp/h/ACME', '/mcp/h/Acme', '/.well-known/oauth-protected-resource/mcp/h/ACME'] as $path) {
            str_starts_with($path, '/.well-known') ? $this->get($path) : $this->listTools($path);
            $this->assertResponseCode(404, $path);
        }
        $this->assertSame([], $this->errors());
    }

    public function testAValueThatIsNotDeclaredIsNotFoundAndNotLogged(): void
    {
        $this->listTools('/mcp/t/unknown');

        $this->assertResponseCode(404);
        $this->assertSame([], $this->errors());
    }

    public function testTheMetadataOfAValueThatIsNotDeclaredIsNotFoundAndNotLogged(): void
    {
        $this->get('/.well-known/oauth-protected-resource/mcp/t/unknown');

        $this->assertResponseCode(404);
        $this->assertSame([], $this->errors());
    }
}
