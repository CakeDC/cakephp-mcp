<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Command;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Console\CommandCollection;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Command\McpServerCommand;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\McpPlugin;
use CakeDC\Mcp\Server\ServerFactory;
use stdClass;
use TestApp\Command\RecordingMcpServerCommand;

class McpServerCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testIsRegisteredAsMcpServer(): void
    {
        $commands = (new McpPlugin())->console(new CommandCollection());

        $this->assertTrue($commands->has('mcp server'));
        $this->assertSame(McpServerCommand::class, $commands->get('mcp server'));
    }

    public function testTheContainerBuildsTheCommand(): void
    {
        $container = new Container();
        (new McpPlugin())->services($container);

        $this->assertInstanceOf(McpServerCommand::class, $container->get(McpServerCommand::class));
    }

    public function testServesToolsOverTheTransport(): void
    {
        $command = $this->command();

        $this->assertSame(McpServerCommand::CODE_SUCCESS, $this->runCommand($command));
        $this->assertContains('echo', array_column($command->transport?->responseFor(2)['result']['tools'] ?? [], 'name'));
    }

    public function testIdentityComesFromTheStdioResolver(): void
    {
        Configure::write('Mcp.stdio.identity', fn(): array => ['id' => 42]);
        $command = $this->command([(string)json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
            'name' => 'whoami',
            'arguments' => new stdClass(),
        ]])]);

        $this->runCommand($command);

        $text = (string)($command->transport?->responseFor(3)['result']['content'][0]['text'] ?? '');
        $this->assertSame(42, json_decode($text, true)['identifier'] ?? null);
    }

    public function testTrustedStdioSkipsRbacAndUntrustedDoesNot(): void
    {
        Configure::write('Mcp.authorization', [
            'authorizer' => RbacToolAuthorizer::class,
            'rbac' => ['log' => false, 'permissions' => []],
        ]);

        $trusted = $this->command();
        $this->runCommand($trusted);
        $this->assertContains('admin_only', array_column($trusted->transport?->responseFor(2)['result']['tools'] ?? [], 'name'));

        Configure::write('Mcp.stdio.trusted', false);
        $untrusted = $this->command();
        $this->runCommand($untrusted);
        $this->assertSame([], $untrusted->transport?->responseFor(2)['result']['tools'] ?? null);
    }

    /**
     * @param list<string> $extra More JSON-RPC lines after initialize/initialized/tools list (id 2).
     */
    private function command(array $extra = []): RecordingMcpServerCommand
    {
        $container = new Container();
        $identity = new CurrentIdentity();
        $container->addShared(CurrentIdentity::class, $identity);

        return new RecordingMcpServerCommand(new ServerFactory($container, $identity), $identity, array_merge([
            (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]]),
            (string)json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
            (string)json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']),
        ], $extra));
    }

    private function runCommand(McpServerCommand $command): ?int
    {
        return $command->run([], new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput(), new StubConsoleInput([])));
    }
}
