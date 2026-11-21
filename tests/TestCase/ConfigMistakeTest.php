<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\Test\Support\McpHttpTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use TestApp\TestTokens;
use Throwable;

/**
 * A mistake in one entry of `Mcp.servers` or `Mcp.clients`, written before the plugin boots (as a host's
 * config/app.php does), stops that entry only: the rest of the application, the other servers and
 * `bin/cake mcp check` keep working, and the entry is logged and left unreachable.
 */
class ConfigMistakeTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use IntegrationTestTrait;
    use McpHttpTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Log::setConfig('mcp_mistakes', ['className' => ArrayLog::class, 'scopes' => ['mcp']]);
    }

    protected function tearDown(): void
    {
        Log::drop('mcp_mistakes');
        Router::reload();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: string}>
     */
    public static function mistakes(): array
    {
        return [
            // 'auth' => env('MCP_ADMIN_AUTH') with the variable not set on this machine.
            'null security section' => ['servers', ['admin' => ['route' => '/mcp/admin', 'auth' => null]], 'admin'],
            'server without a route' => ['servers', ['admin' => ['server' => ['name' => 'Admin']]], 'admin'],
            'server name that is not a slug' => ['servers', ['back office' => ['route' => '/mcp/bo']], 'back office'],
            // A 2.1 application that kept data of its own under `Mcp.servers` (2.1 ignored unknown keys).
            'a 2.1 key of the application' => ['servers', ['github' => ['url' => 'https://api.example.com/mcp']], 'github'],
            'server that is not an array' => ['servers', ['production' => 'https://mcp.example.com'], 'production'],
            'servers that is not an array' => ['servers', 'admin', 'Mcp.servers'],
            'client name that is not a slug' => ['clients', ['git.hub' => ['url' => 'https://api.example.com/mcp']], 'git.hub'],
            // 'github' => env('GITHUB_MCP') with the variable not set.
            'client that is not an array' => ['clients', ['github' => null], 'github'],
            'clients as a list' => ['clients', ['claude.ai', 'cursor'], 'Mcp.clients'],
            'servers as a list' => ['servers', [['route' => '/mcp/x']], 'Mcp.servers.0'],
            'server name that is a number' => ['servers', ['2024' => ['route' => '/mcp/y']], 'Mcp.servers.2024'],
            'client name that is a number' => ['clients', ['42' => ['url' => 'https://api.example.com/mcp']], '42'],
            // A setting mistake, not a shape one: still logged and reported for that client only.
            'client secret without its issuer' => ['clients', ['notion' => [
                'url' => 'https://mcp.notion.example/mcp',
                'auth' => 'user',
                'clientId' => 'id',
                'clientSecret' => 'secret',
            ]], 'notion'],
        ];
    }

    #[DataProvider('mistakes')]
    public function testAPageOfTheApplicationKeepsWorking(string $key, mixed $value, string $entry): void
    {
        Configure::write('Mcp.' . $key, $value);

        try {
            $this->get('/no-such-page');
        } catch (Throwable $e) {
            $this->fail('The application no longer answers: ' . $e->getMessage());
        }

        $this->assertResponseCode(404);
    }

    #[DataProvider('mistakes')]
    public function testTheDefaultServerKeepsWorking(string $key, mixed $value, string $entry): void
    {
        Configure::write('Mcp.' . $key, $value);

        $this->postMcp($this->mcpMessage(1, 'tools/list'), $this->mcpHeaders('tools/list', null, TestTokens::EDITOR));

        $this->assertResponseOk();
        $this->assertStringContainsString('"tools"', (string)$this->_response?->getBody());
    }

    #[DataProvider('mistakes')]
    public function testTheMistakeIsLoggedWithItsEntry(string $key, mixed $value, string $entry): void
    {
        Configure::write('Mcp.' . $key, $value);

        $this->get('/no-such-page');

        $engine = Log::engine('mcp_mistakes');
        $this->assertInstanceOf(ArrayLog::class, $engine);
        $logged = implode("\n", $engine->read());
        $this->assertStringContainsString($entry, $logged);
        $this->assertStringContainsString('error', $logged);
    }

    #[DataProvider('mistakes')]
    public function testMcpCheckRunsAndFailsOnTheEntry(string $key, mixed $value, string $entry): void
    {
        Configure::write('Mcp.' . $key, $value);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL]');
        $this->assertOutputContains($entry);
        // The valid default server is still checked.
        $this->assertOutputContains('Endpoint');
    }

    public function testABrokenNamedServerIsNotReachable(): void
    {
        Configure::write('Mcp.servers', ['admin' => ['route' => '/mcp/admin', 'auth' => null]]);

        $this->_request['headers'] = $this->mcpHeaders('tools/list', null, TestTokens::EDITOR) + [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ];
        $this->post('/mcp/admin', (string)json_encode($this->mcpMessage(1, 'tools/list')));

        // No MCP route: the request falls to the application (its CSRF check here), never to an MCP server.
        $this->assertResponseError();
        $this->assertResponseNotContains('jsonrpc');
        $this->assertSame(['default'], McpConfig::servers());
    }

    public function testAValidNamedServerNextToABrokenOneIsServed(): void
    {
        Configure::write('Mcp.servers', [
            'broken' => ['route' => '/mcp/broken', 'auth' => null],
            'admin' => ['route' => '/mcp/admin'],
        ]);

        $this->_request['headers'] = $this->mcpHeaders('tools/list', null, TestTokens::EDITOR) + [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ];
        $this->post('/mcp/admin', (string)json_encode($this->mcpMessage(1, 'tools/list')));

        $this->assertResponseOk();
        $this->assertSame(['default', 'admin'], McpConfig::servers());
    }
}
