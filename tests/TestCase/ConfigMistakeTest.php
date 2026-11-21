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
use CakeDC\Mcp\Log\ConfigMistakes;
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
        ConfigMistakes::reset();
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
            // `scanDir`: the server would scan the default server's src/Mcp.
            'typo in discovery' => ['servers', ['docs' => ['route' => '/mcp/docs', 'discovery' => ['scanDir' => ['src/McpDocs']]]], 'docs'],
            'typo in identity' => ['servers', ['docs' => ['route' => '/mcp/docs', 'identity' => ['requried' => false]]], 'docs'],
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

    /**
     * The application skeleton's `debug` and `error` loggers have `'scopes' => null`, which takes only
     * messages without a scope: a mistake must still reach its error log, once, next to a scoped `mcp` log.
     */
    public function testTheMistakeReachesTheSkeletonErrorLogOncePerProcess(): void
    {
        Log::setConfig('skeleton_debug', [
            'className' => ArrayLog::class,
            'levels' => ['notice', 'info', 'debug'],
            'scopes' => null,
        ]);
        Log::setConfig('skeleton_error', [
            'className' => ArrayLog::class,
            'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
            'scopes' => null,
        ]);
        Log::setConfig('catch_all', ['className' => ArrayLog::class, 'scopes' => []]);
        try {
            Configure::write('Mcp.servers', ['admin' => ['server' => ['name' => 'Admin']]]);

            $this->get('/no-such-page');
            $this->get('/no-such-page');

            foreach (['skeleton_error', 'mcp_mistakes', 'catch_all'] as $config) {
                $engine = Log::engine($config);
                $this->assertInstanceOf(ArrayLog::class, $engine);
                $lines = array_values(array_filter($engine->read(), fn(string $line): bool => str_contains($line, '"admin"')));
                $this->assertCount(1, $lines, $config . ': ' . implode("\n", $engine->read()));
                $this->assertStringContainsString('error', $lines[0]);
            }
            $debug = Log::engine('skeleton_debug');
            $this->assertInstanceOf(ArrayLog::class, $debug);
            $this->assertSame([], $debug->read());
        } finally {
            Log::drop('skeleton_debug');
            Log::drop('skeleton_error');
            Log::drop('catch_all');
        }
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

    public function testAnEntryNamedDefaultIsIgnoredAndTheDefaultServerStillChecked(): void
    {
        Configure::write('Mcp.servers.default', ['route' => '/mcp', 'identity' => ['required' => true]]);

        $this->get('/no-such-page');
        $engine = Log::engine('mcp_mistakes');
        $this->assertInstanceOf(ArrayLog::class, $engine);
        $logged = implode("\n", $engine->read());
        $this->assertStringContainsString('"Mcp.servers.default" is ignored', $logged);
        $this->assertStringNotContainsString('MCP server "default" is not served', $logged);

        $this->exec('mcp check --server default');
        $this->assertOutputContains('[FAIL] Server default');
        $this->assertOutputContains('Endpoint: http://localhost/mcp');
        $this->assertExitError();
    }

    public function testAnEmptyIdentityDoesNotPromiseTheDefaultServers(): void
    {
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'identity' => null]);

        $message = McpConfig::serverErrors()['admin'] ?? '';

        $this->assertStringContainsString('"Mcp.servers.admin.identity"', $message);
        $this->assertStringNotContainsString("the default server's", $message);
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
