<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ClientRegistry;
use CakeDC\Mcp\Client\Exception\ClientException;
use CakeDC\Mcp\Client\McpClients;
use CakeDC\Mcp\Test\Support\FixedHttpClientFactory;
use CakeDC\Mcp\Test\Support\SdkServerHttpClient;
use InvalidArgumentException;
use LogicException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Tool;

class McpClientHttpTest extends TestCase
{
    private SdkServerHttpClient $server;

    private FixedHttpClientFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new SdkServerHttpClient();
        $this->factory = new FixedHttpClientFactory($this->server);
        McpClients::setRegistry(new ClientRegistry($this->factory));
        Configure::write('Mcp.clients.remote', [
            'url' => 'https://mcp.example.com/mcp',
            'token' => 'app-token',
        ]);
    }

    protected function tearDown(): void
    {
        McpClients::reset();
        parent::tearDown();
    }

    /**
     * @param list<\Mcp\Schema\Tool> $tools
     * @return list<string>
     */
    private static function names(array $tools): array
    {
        return array_map(fn(Tool $tool): string => $tool->name, $tools);
    }

    public function testListsAndCallsToolsWithTheToken(): void
    {
        $client = McpClients::get('remote');

        $this->assertSame(
            ['echo', 'whoami', 'delete_all', 'get_issue', 'get_secret'],
            self::names($client->listTools()),
        );
        $result = $client->callTool('echo', ['text' => 'hi']);
        $this->assertFalse($result->isError);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('echo: hi', $result->content[0]->text);
        $this->assertSame('app-token', $client->callTool('whoami')->content[0]->text ?? null);

        foreach ($this->server->requests as $request) {
            $this->assertSame('https://mcp.example.com/mcp', $request['url']);
            $this->assertSame('Bearer app-token', $request['headers']['authorization'] ?? null);
        }
        $this->assertSame(['remote'], $this->factory->created);
    }

    public function testATokenCallableIsCalledForEachRequest(): void
    {
        $calls = 0;
        Configure::write('Mcp.clients.rotating', [
            'url' => 'https://mcp.example.com/mcp',
            'token' => function () use (&$calls): string {
                $calls++;

                return 'vault-token-' . $calls;
            },
        ]);
        $client = McpClients::get('rotating');

        $first = $client->callTool('whoami')->content[0]->text ?? null;
        $second = $client->callTool('whoami')->content[0]->text ?? null;

        $this->assertIsString($first);
        $this->assertStringStartsWith('vault-token-', $first);
        $this->assertNotSame($first, $second);
        $this->assertSame($calls, count($this->server->requests));
        $this->assertSame('Bearer vault-token-' . $calls, end($this->server->requests)['headers']['authorization'] ?? null);
    }

    public function testATokenCallableThatReturnsNoStringFailsNamingTheClient(): void
    {
        Configure::write('Mcp.clients.rotating', [
            'url' => 'https://mcp.example.com/mcp',
            'token' => fn(): ?string => null,
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('"Mcp.clients.rotating.token"');
        McpClients::get('rotating')->listTools();
    }

    public function testAStringTokenThatIsAFunctionNameIsStillAString(): void
    {
        Configure::write('Mcp.clients.remote.token', 'strlen');

        $this->assertSame('strlen', McpClients::get('remote')->callTool('whoami')->content[0]->text ?? null);
    }

    public function testIntrospection(): void
    {
        $client = McpClients::get('remote');

        $client->ping();
        $this->assertSame('fake', $client->serverInfo()?->name);
        $this->assertSame('1.0.0', $client->serverInfo()?->version);
        $this->assertNotNull($client->capabilities()?->tools);
        $this->assertNull($client->instructions());
        $this->assertContains('server/discover', $this->server->methods());
    }

    public function testHeadersAreSent(): void
    {
        Configure::write('Mcp.clients.keyed', [
            'url' => 'https://mcp.example.com/mcp',
            'headers' => ['X-Api-Key' => 'k-123'],
        ]);

        McpClients::get('keyed')->listTools();

        $this->assertSame('k-123', $this->server->requests[0]['headers']['x-api-key'] ?? null);
        $this->assertArrayNotHasKey('authorization', $this->server->requests[0]['headers']);
    }

    public function testToolFiltersHideAndRefuseTools(): void
    {
        Configure::write('Mcp.clients.filtered', [
            'url' => 'https://mcp.example.com/mcp',
            'tools' => ['only' => ['get_*', 'echo'], 'except' => ['get_secret']],
        ]);
        $client = McpClients::get('filtered');

        $this->assertSame(['echo', 'get_issue'], self::names($client->listTools()));

        foreach (['delete_all', 'get_secret'] as $tool) {
            try {
                $client->callTool($tool);
                $this->fail($tool . ' was called');
            } catch (ClientException $e) {
                $this->assertStringContainsString(
                    sprintf('"%s" is not allowed by "Mcp.clients.filtered.tools"', $tool),
                    $e->getMessage(),
                );
            }
        }
        $this->assertNotContains('tools/call', $this->server->methods());
        $this->assertSame('echo: x', $client->callTool('echo', ['text' => 'x'])->content[0]->text ?? null);
    }

    public function testListsEveryPage(): void
    {
        $this->server = new SdkServerHttpClient(2);
        McpClients::setRegistry(new ClientRegistry(new FixedHttpClientFactory($this->server)));

        $this->assertCount(5, McpClients::get('remote')->listTools());
        $this->assertSame(3, count(array_keys($this->server->methods(), 'tools/list', true)));
    }

    public function testResourcesAndPrompts(): void
    {
        $client = McpClients::get('remote');

        $resources = $client->listResources();
        $this->assertSame('docs://readme', $resources[0]->uri);
        $contents = $client->readResource('docs://readme')->contents[0];
        $this->assertInstanceOf(TextResourceContents::class, $contents);
        $this->assertSame('Read me.', $contents->text);

        $this->assertSame('greet', $client->listPrompts()[0]->name);
        $message = $client->getPrompt('greet', ['name' => 'Ana'])->messages[0];
        $this->assertInstanceOf(TextContent::class, $message->content);
        $this->assertSame('Hello Ana', $message->content->text);
    }

    public function testProtocolVersionIsSent(): void
    {
        Configure::write('Mcp.clients.pinned', [
            'url' => 'https://mcp.example.com/mcp',
            'protocolVersion' => '2025-06-18',
        ]);

        McpClients::get('pinned')->listTools();

        $initialize = json_decode($this->server->requests[0]['body'], true);
        $this->assertSame('initialize', $initialize['method']);
        $this->assertSame('2025-06-18', $initialize['params']['protocolVersion']);
        $last = $this->server->requests[array_key_last($this->server->requests)];
        $this->assertSame('2025-06-18', $last['headers']['mcp-protocol-version'] ?? null);
    }

    public function testOneSessionForSeveralCalls(): void
    {
        $client = McpClients::get('remote');
        $client->listTools();
        $client->callTool('echo', ['text' => 'a']);
        $client->callTool('echo', ['text' => 'b']);

        $handshakes = array_intersect($this->server->methods(), ['initialize', 'server/discover']);
        $this->assertCount(1, $handshakes);
        $this->assertSame($client, McpClients::get('remote'));
    }

    public function testUserModeClientRefusesAppModeCalls(): void
    {
        Configure::write('Mcp.clients.personal', ['url' => 'https://mcp.example.com/mcp', 'auth' => 'user']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('McpClients::get(\'personal\')->for($user)');

        McpClients::get('personal')->listTools();
    }

    public function testPlainHttpToARemoteHostFailsAtConfigTime(): void
    {
        Configure::write('Mcp.clients.plain', ['url' => 'http://mcp.example.com/mcp']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allowInsecure');

        McpClients::get('plain');
    }

    public function testCloseEndsTheSession(): void
    {
        $client = McpClients::get('remote');
        $client->listTools();
        $client->close();
        $client->listTools();

        $handshakes = array_intersect($this->server->methods(), ['initialize', 'server/discover']);
        $this->assertCount(2, $handshakes);
    }
}
