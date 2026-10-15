<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Server;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Event\EventDispatcher;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Server\ServerFactory;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use InvalidArgumentException;
use Mcp\Server\Builder;
use stdClass;

class ServerFactoryTest extends TestCase
{
    private CurrentIdentity $identity;

    private ServerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        $container = new Container();
        $this->identity = new CurrentIdentity();
        $container->addShared(CurrentIdentity::class, $this->identity);
        $this->factory = new ServerFactory($container, $this->identity);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testListsDiscoveredTools(): void
    {
        $this->assertSame(
            ['admin_only', 'create_note', 'echo', 'explode', 'whoami'],
            $this->toolNames($this->runServer()),
        );
    }

    public function testValidationErrorsReachTheModelAsAToolError(): void
    {
        $transport = $this->runServer([$this->call(3, 'create_note', ['title' => 'ab'])]);

        $result = $transport->responseFor(3)['result'] ?? [];
        $this->assertTrue($result['isError'] ?? false);
        $this->assertSame(
            "The note was not created.\n- title: Use at least 3 characters.",
            $result['content'][0]['text'] ?? null,
        );
    }

    public function testToolAnnotationsReachTheToolList(): void
    {
        $tools = array_column($this->runServer()->responseFor(2)['result']['tools'] ?? [], null, 'name');

        $this->assertSame(['readOnlyHint' => true, 'idempotentHint' => true], $tools['echo']['annotations'] ?? null);
        $this->assertArrayNotHasKey('annotations', $tools['explode'] ?? []);
    }

    public function testCallsATool(): void
    {
        $transport = $this->runServer([$this->call(3, 'echo', ['text' => 'hello'])]);

        $this->assertSame('hello', $transport->responseFor(3)['result']['content'][0]['text'] ?? null);
    }

    public function testToolsSeeTheCurrentIdentity(): void
    {
        $this->identity->set(['id' => 5]);

        $transport = $this->runServer([$this->call(3, 'whoami')]);

        $text = (string)($transport->responseFor(3)['result']['content'][0]['text'] ?? '');
        $this->assertSame(5, json_decode($text, true)['identifier'] ?? null);
    }

    public function testToolExceptionsDoNotLeakTheirMessage(): void
    {
        $transport = $this->runServer([$this->call(3, 'explode')]);

        $response = (string)json_encode($transport->responseFor(3));
        $this->assertStringContainsString('-32603', $response);
        $this->assertStringNotContainsString('secret details', $response);
    }

    public function testDeniedToolsAreHiddenAndRejected(): void
    {
        Configure::write('Mcp.authorization.authorizer', $this->onlyEcho());

        $transport = $this->runServer([$this->call(3, 'admin_only')]);

        $this->assertSame(['echo'], $this->toolNames($transport));
        $this->assertSame('Tool not found: "admin_only".', $transport->responseFor(3)['error']['message'] ?? null);
    }

    public function testTrustedServersSkipTheAuthorizer(): void
    {
        Configure::write('Mcp.authorization.authorizer', $this->onlyEcho());

        $this->assertContains('admin_only', $this->toolNames($this->runServer([], true)));
    }

    public function testRbacAuthorizerFromConfig(): void
    {
        Configure::write('Mcp.authorization', [
            'authorizer' => RbacToolAuthorizer::class,
            'rbac' => ['log' => false, 'permissions' => [
                ['role' => 'editor', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => ['echo']],
            ]],
        ]);
        $this->identity->set(['id' => 1, 'role' => 'editor']);

        $this->assertSame(['echo'], $this->toolNames($this->runServer()));
    }

    public function testMissingScanDirYieldsEmptyToolList(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/DoesNotExist']);

        $this->assertSame([], $this->toolNames($this->runServer()));
    }

    public function testInstructionsAreAnnouncedOnInitialize(): void
    {
        Configure::write('Mcp.server', ['name' => 'Test Server', 'instructions' => 'Use echo to test.']);

        $result = $this->runServer()->responseFor(1)['result'] ?? [];

        $this->assertSame('Test Server', $result['serverInfo']['name'] ?? null);
        $this->assertSame('Use echo to test.', $result['instructions'] ?? null);
    }

    public function testConfigureHookReceivesTheBuilder(): void
    {
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->addTool(fn(): string => 'pong', name: 'ping', description: 'Answers pong.');
        });

        $transport = $this->runServer([$this->call(3, 'ping')]);

        $this->assertContains('ping', $this->toolNames($transport));
        $this->assertSame('pong', $transport->responseFor(3)['result']['content'][0]['text'] ?? null);
    }

    public function testConfigureHookCannotReplaceThePluginSettings(): void
    {
        Configure::write('Mcp.authorization.authorizer', $this->onlyEcho());
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->setServerInfo('Replaced', '9.9.9')->setEventDispatcher(new EventDispatcher());
        });

        $transport = $this->runServer([$this->call(3, 'admin_only')]);

        $this->assertSame('CakePHP MCP Server', $transport->responseFor(1)['result']['serverInfo']['name'] ?? null);
        $this->assertSame(['echo'], $this->toolNames($transport));
        $this->assertSame('Tool not found: "admin_only".', $transport->responseFor(3)['error']['message'] ?? null);
    }

    public function testAuthorizerAppliesToToolsAddedByTheHook(): void
    {
        Configure::write('Mcp.authorization.authorizer', $this->onlyEcho());
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->addTool(fn(): string => 'pong', name: 'ping', description: 'Answers pong.');
        });

        $transport = $this->runServer([$this->call(3, 'ping')]);

        $this->assertSame(['echo'], $this->toolNames($transport));
        $this->assertSame('Tool not found: "ping".', $transport->responseFor(3)['error']['message'] ?? null);
    }

    public function testRejectsAConfigureHookThatIsNotCallable(): void
    {
        Configure::write('Mcp.server.configure', 'not_a_function');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mcp.server.configure');
        $this->factory->create();
    }

    public function testRejectsAnInvalidAuthorizer(): void
    {
        Configure::write('Mcp.authorization.authorizer', 'NotAnAuthorizer');

        $this->expectException(InvalidArgumentException::class);
        $this->factory->create();
    }

    /**
     * Runs initialize, notifications/initialized, tools/list (id 2), then $extra.
     *
     * @param list<string> $extra More JSON-RPC lines.
     */
    private function runServer(array $extra = [], bool $trusted = false): RecordingTransport
    {
        $transport = new RecordingTransport(array_merge([
            (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]]),
            (string)json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
            (string)json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']),
        ], $extra));
        $this->factory->create($trusted)->run($transport);

        return $transport;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function call(int $id, string $tool, array $arguments = []): string
    {
        return (string)json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name' => $tool,
            'arguments' => $arguments === [] ? new stdClass() : $arguments,
        ]]);
    }

    /**
     * @return list<string>
     */
    private function toolNames(RecordingTransport $transport): array
    {
        $names = array_column($transport->responseFor(2)['result']['tools'] ?? [], 'name');
        sort($names);

        return $names;
    }

    private function onlyEcho(): ToolAuthorizerInterface
    {
        return new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool === 'echo';
            }
        };
    }
}
