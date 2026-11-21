<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Server;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Attribute\McpWhen;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Server\ServerFactory;
use CakeDC\Mcp\Test\Support\DelegatingRegistry;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use InvalidArgumentException;
use Mcp\Capability\Registry;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * `#[McpWhen]` (TestApp\McpWhen).
 */
class ConditionalRegistrationTest extends TestCase
{
    private ServerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpWhen']);
        $container = new Container();
        $identity = new CurrentIdentity();
        $container->addShared(CurrentIdentity::class, $identity);
        $this->factory = new ServerFactory($container, $identity);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        Configure::delete('TestApp');
        parent::tearDown();
    }

    public function testAToolWhoseConfigIsOffIsNotRegistered(): void
    {
        $transport = $this->serve([
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name' => 'monthly_report',
                'arguments' => new stdClass(),
            ]],
        ]);

        $names = array_column($transport->responseFor(2)['result']['tools'] ?? [], 'name');
        $this->assertContains('always', $names);
        $this->assertNotContains('monthly_report', $names);
        $this->assertStringContainsString('monthly_report', $transport->responseFor(3)['error']['message'] ?? '');
    }

    public function testConfigOnRegistersIt(): void
    {
        Configure::write('TestApp.reports', true);

        $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);

        $this->assertContains('monthly_report', array_column($transport->responseFor(2)['result']['tools'] ?? [], 'name'));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function configValues(): array
    {
        return [
            'string false' => ['false', false],
            'string FALSE' => ['FALSE', false],
            'string 0' => ['0', false],
            'string off' => ['off', false],
            'string No' => [' No ', false],
            'empty string' => ['', false],
            'false' => [false, false],
            'null' => [null, false],
            'string true' => ['true', true],
            'string 1' => ['1', true],
            'string yes' => ['yes', true],
            'true' => [true, true],
            'int 1' => [1, true],
        ];
    }

    #[DataProvider('configValues')]
    public function testConfigStringsThatMeanOffAreOff(mixed $value, bool $registered): void
    {
        // env('FEATURE_X', false) gives the string "false" when .env has FEATURE_X=false.
        Configure::write('TestApp.reports', $value);

        $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);

        $names = array_column($transport->responseFor(2)['result']['tools'] ?? [], 'name');
        $this->assertSame($registered, in_array('monthly_report', $names, true));
    }

    public function testAMethodConditionAddsToItsClassCondition(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpWhenClass']);
        $listed = function (): array {
            $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);
            $names = array_column($transport->responseFor(2)['result']['tools'] ?? [], 'name');
            sort($names);

            return $names;
        };

        Configure::write('TestApp', ['archive' => false, 'export' => true]);
        $this->assertSame([], $listed(), 'The class is off: so is every method, conditions of its own or not.');
        Configure::write('TestApp', ['archive' => true, 'export' => false]);
        $this->assertSame(['archive_list'], $listed());
        Configure::write('TestApp', ['archive' => true, 'export' => true]);
        $this->assertSame(['archive_export', 'archive_list'], $listed());
    }

    public function testCallbackGetsTheServer(): void
    {
        $list = [['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']];
        $this->assertNotContains('beta_tool', array_column($this->serve($list)->responseFor(2)['result']['tools'] ?? [], 'name'));

        Configure::write('Mcp.servers.beta', ['route' => '/mcp/beta']);
        $this->factory = $this->factory->forServer('beta');

        $this->assertContains('beta_tool', array_column($this->serve($list)->responseFor(2)['result']['tools'] ?? [], 'name'));
    }

    public function testResourcesAndPromptsAreSkippedToo(): void
    {
        $transport = $this->serve([
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/list'],
        ]);
        $this->assertSame([], $transport->responseFor(2)['result']['resources'] ?? null);
        $this->assertSame([], $transport->responseFor(3)['result']['prompts'] ?? null);

        Configure::write('TestApp', ['guides' => true, 'prompts' => true]);
        $transport = $this->serve([
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/list'],
        ]);
        $this->assertSame(['guide'], array_column($transport->responseFor(2)['result']['resources'] ?? [], 'name'));
        $this->assertSame(['weekly'], array_column($transport->responseFor(3)['result']['prompts'] ?? [], 'name'));
    }

    public function testSkippedElementsAreReported(): void
    {
        $this->assertSame([
            'tool beta_tool (TestApp\McpWhen\Flags::onlyBeta() returned false)',
            'tool monthly_report (TestApp.reports is off)',
            'resource guide (TestApp.guides is off)',
            'prompt weekly (TestApp.prompts is off)',
        ], $this->factory->skippedElements());
    }

    public function testInvalidAttributeFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[McpWhen] needs either config or callback');

        new McpWhen();
    }

    public function testMcpWhenPrunesARegistryTheHookSet(): void
    {
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->setRegistry(new Registry());
        });

        $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);

        $names = array_column($transport->responseFor(2)['result']['tools'] ?? [], 'name');
        $this->assertContains('always', $names);
        $this->assertNotContains('monthly_report', $names);
        $this->assertNotContains('beta_tool', $names);
    }

    public function testMcpWhenPrunesARegistryOfTheHooksOwn(): void
    {
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->setRegistry(new DelegatingRegistry());
        });

        $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);

        $names = array_column($transport->responseFor(2)['result']['tools'] ?? [], 'name');
        $this->assertContains('always', $names);
        $this->assertNotContains('monthly_report', $names);
        $this->assertNotContains('beta_tool', $names);
    }

    public function testCodeRegisteredToolsAreUntouched(): void
    {
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->addTool(fn(): string => 'ok', 'from_code');
        });

        $transport = $this->serve([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']]);

        $this->assertContains('from_code', array_column($transport->responseFor(2)['result']['tools'] ?? [], 'name'));
    }

    /**
     * @param list<array<string, mixed>> $requests
     */
    private function serve(array $requests): RecordingTransport
    {
        $transport = new RecordingTransport(array_map(fn(array $message): string => (string)json_encode($message), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ...$requests,
        ]));
        $this->factory->create(true)->run($transport);

        return $transport;
    }
}
