<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Catalog;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Catalog\CatalogPatterns;
use CakeDC\Mcp\Catalog\ToolCatalog;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Server\ServerFactory;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use InvalidArgumentException;
use LogicException;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Tool;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Builder;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Handler\ToolHandlerInterface;
use RuntimeException;
use stdClass;

/**
 * The tool catalog (`Mcp.catalog`) over the tools of TestApp\McpDocs.
 */
class ToolCatalogTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpDocs']);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['order_*', 'find_order', 'list_orders']]);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        Cache::drop('mcp_oauth_test');
        parent::tearDown();
    }

    public function testCataloguedToolsLeaveTheListButStayCallable(): void
    {
        $names = array_column($this->mcpListTools(), 'name');

        $this->assertContains('find_tools', $names);
        $this->assertContains('call_tools', $names);
        $this->assertContains('orders_compact', $names);
        $this->assertNotContains('order_status', $names);
        $this->assertNotContains('find_order', $names);

        $this->mcpCallTool('order_status', ['number' => 'A-1']);
        $this->assertMcpToolSucceeded();
    }

    public function testFindToolsRanksByNameTitleAndDescription(): void
    {
        $this->mcpCallTool('find_tools', ['query' => 'status']);

        $this->assertMcpToolSucceeded();
        $tools = $this->mcpResult()['structuredContent']['tools'];
        $this->assertSame('order_status', $tools[0]['name']);
        $this->assertSame(['number'], $tools[0]['inputSchema']['required']);
        $this->assertNotContains('orders_compact', array_column($tools, 'name'));
    }

    public function testFindToolsFollowsTheSchemaNormalizer(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpSchema']);
        Configure::write('Mcp.catalog.tools', ['report']);
        Configure::write('Mcp.schema.normalize', true);

        $this->mcpCallTool('find_tools', ['query' => 'report']);

        $this->assertMcpToolSucceeded();
        $schema = $this->mcpResult()['structuredContent']['tools'][0]['inputSchema'];
        $this->assertSame('string', $schema['properties']['date_from']['type']);
    }

    private function searchCatalog(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpCatalog']);
        Configure::write('Mcp.catalog.tools', ['*']);
    }

    /**
     * @return list<string>
     */
    private function found(string $query): array
    {
        $this->mcpCallTool('find_tools', ['query' => $query]);
        $this->assertMcpToolSucceeded();

        return array_column($this->mcpResult()['structuredContent']['tools'], 'name');
    }

    public function testFindToolsMatchesWordsOutsideAscii(): void
    {
        $this->searchCatalog();

        $this->assertSame(['buscar_facturas'], $this->found('año'));
        // "año" is one word, not "a" and "o", which would match nearly every tool.
        $this->assertNotContains('list_orders', $this->found('año'));
        $this->assertSame(['buscar_facturas'], $this->found('AÑO'));
    }

    public function testFindToolsSearchesTheInputSchema(): void
    {
        $this->searchCatalog();

        $this->assertSame(['send_payment'], $this->found('iban'));
        $this->assertSame(['send_payment'], $this->found('account'));
    }

    public function testFindToolsRefusesAnOverlongQuery(): void
    {
        $this->searchCatalog();

        $this->mcpCallTool('find_tools', ['query' => str_repeat('a', 201)]);

        // The input schema says so (maxLength), so the arguments are refused before the search runs.
        $this->assertMcpJsonRpcError(-32602, 'longer than 200 characters');
    }

    public function testFindToolsSaysWhetherThereIsMore(): void
    {
        $this->searchCatalog();

        $this->mcpCallTool('find_tools', ['limit' => 2]);
        $this->assertTrue($this->mcpResult()['structuredContent']['hasMore']);

        $this->mcpCallTool('find_tools', ['limit' => 3]);
        $this->assertFalse($this->mcpResult()['structuredContent']['hasMore']);
    }

    public function testFindToolsCapsTheSizeOfItsResult(): void
    {
        Configure::write('Mcp.discovery.scanDirs', []);
        Configure::write('Mcp.catalog.tools', ['big_*']);
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            for ($i = 0; $i < 30; $i++) {
                $builder->addTool(
                    fn(): string => 'ok',
                    name: sprintf('big_%02d', $i),
                    description: str_repeat('A long description. ', 300),
                );
            }
        });

        $this->mcpCallTool('find_tools', ['limit' => 50]);

        $this->assertMcpToolSucceeded();
        $result = $this->mcpResult()['structuredContent'];
        $this->assertTrue($result['hasMore']);
        $this->assertGreaterThan(0, count($result['tools']));
        $this->assertLessThan(30, count($result['tools']));
        $this->assertLessThanOrEqual(ToolCatalog::MAX_RESULT_BYTES, strlen((string)json_encode($result['tools'])));
    }

    public function testFindToolsWithAnEmptyQuery(): void
    {
        $this->mcpCallTool('find_tools', ['limit' => 3]);

        $this->assertMcpToolSucceeded();
        $this->assertCount(3, $this->mcpResult()['structuredContent']['tools']);
    }

    public function testFindToolsHidesToolsTheCallerCannotCall(): void
    {
        Configure::write('Mcp.authorization.authorizer', $this->denying('order_status'));

        $this->mcpCallTool('find_tools', ['query' => 'status']);

        $this->assertMcpToolSucceeded();
        $this->assertNotContains('order_status', array_column($this->mcpResult()['structuredContent']['tools'], 'name'));
    }

    public function testCallToolsRunsInOrderAndStopsAtTheFirstError(): void
    {
        $this->mcpCallTool('call_tools', ['calls' => [
            ['name' => 'order_status', 'arguments' => ['number' => 'A-1']],
            ['name' => 'find_order', 'arguments' => ['number' => 7]],
            ['name' => 'order_numbers', 'arguments' => new stdClass()],
        ]]);

        $this->assertMcpToolError();
        $results = $this->mcpResult()['structuredContent']['results'];
        $this->assertCount(2, $results);
        $this->assertSame(['order_status', 'find_order'], array_column($results, 'name'));
        $this->assertFalse($results[0]['isError']);
        $this->assertSame('open', $results[0]['content'][0]['text']);
        $this->assertTrue($results[1]['isError']);
        $this->assertStringContainsString("Invalid parameters for tool 'find_order'", $results[1]['content'][0]['text']);
    }

    public function testCallToolsDeniesLikeAnUnknownTool(): void
    {
        Configure::write('Mcp.authorization.authorizer', $this->denying('order_status'));

        $this->mcpCallTool('call_tools', ['calls' => [['name' => 'order_status', 'arguments' => ['number' => 'A-1']]]]);

        $this->assertMcpToolError();
        $result = $this->mcpResult()['structuredContent']['results'][0];
        $this->assertSame('Tool not found: "order_status".', $result['content'][0]['text']);
    }

    public function testCallToolsRefusesToolsOutsideTheCatalogAndItself(): void
    {
        foreach (['orders_compact', 'call_tools', 'find_tools', 'no_such_tool'] as $name) {
            $this->mcpCallTool('call_tools', ['calls' => [['name' => $name, 'arguments' => new stdClass()]]]);

            $this->assertMcpToolError();
            $result = $this->mcpResult()['structuredContent']['results'][0];
            $this->assertSame(sprintf('Tool not found: "%s".', $name), $result['content'][0]['text'], $name);
        }
    }

    public function testWhatAToolThrowsFailsOnlyItsOwnCall(): void
    {
        Configure::write('Mcp.catalog.tools', ['order_*', 'summarize', 'crash']);
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->addTool(function (): string {
                // The SDK rethrows it on purpose; through call_tools it would make the client send the batch again.
                throw new MissingRequiredClientCapabilityException(new ClientCapabilities(sampling: true));
            }, 'summarize');
            $builder->addTool(fn(): string => throw new LogicException('A bug.'), 'crash');
        });

        foreach (['summarize' => 'needs a capability the client did not declare', 'crash' => 'Error while executing tool'] as $tool => $text) {
            $this->mcpCallTool('call_tools', ['calls' => [
                ['name' => 'order_status', 'arguments' => ['number' => 'A-1']],
                ['name' => $tool, 'arguments' => new stdClass()],
            ]]);

            $this->assertMcpToolError();
            $results = $this->mcpResult()['structuredContent']['results'] ?? [];
            $this->assertCount(2, $results, $tool);
            $this->assertFalse($results[0]['isError'], $tool);
            $this->assertTrue($results[1]['isError'], $tool);
            $this->assertStringContainsString($text, $results[1]['content'][0]['text'], $tool);
            $this->assertStringNotContainsString('A bug', (string)json_encode($results));
        }
    }

    public function testAnAuthorizerThatThrowsFailsOnlyItsCall(): void
    {
        Configure::write('Mcp.authorization.authorizer', new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool === 'find_order' ? throw new RuntimeException('The rules store is down.') : true;
            }
        });

        $this->mcpCallTool('call_tools', ['calls' => [
            ['name' => 'order_status', 'arguments' => ['number' => 'A-1']],
            ['name' => 'find_order', 'arguments' => ['number' => 7]],
        ]]);

        $this->assertMcpToolError();
        $results = $this->mcpResult()['structuredContent']['results'] ?? [];
        $this->assertCount(2, $results);
        $this->assertTrue($results[1]['isError']);
        $this->assertStringNotContainsString('rules store', (string)json_encode($results));
    }

    public function testAnInnerCallWhoseAuthorizerThrowsIsAudited(): void
    {
        Configure::write('Mcp.audit', ['enabled' => true, 'log' => false]);
        Configure::write('Mcp.authorization.authorizer', new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool === 'find_order' ? throw new RuntimeException('The rules store is down.') : true;
            }
        });
        $records = [];
        $listener = function (EventInterface $event) use (&$records): void {
            $records[] = $event->getData();
        };
        EventManager::instance()->on('Mcp.audit', $listener);
        try {
            $this->mcpCallTool('call_tools', ['calls' => [['name' => 'find_order', 'arguments' => ['number' => 7]]]]);
        } finally {
            EventManager::instance()->off('Mcp.audit', $listener);
        }

        $inner = array_values(array_filter($records, fn(array $record): bool => ($record['name'] ?? null) === 'find_order'));
        $this->assertCount(1, $inner, (string)json_encode($records));
        $this->assertSame('error', $inner[0]['outcome']);
    }

    public function testANameWithANulByteMatchesNoPattern(): void
    {
        // fnmatch() raises a ValueError for it.
        $this->assertFalse(CatalogPatterns::matches(['order_*'], "order_status\0x"));
    }

    public function testArgumentsThatAreNotAnObjectAreAnError(): void
    {
        $this->mcpCallTool('call_tools', ['calls' => [['name' => 'order_numbers', 'arguments' => 'all']]]);

        $this->assertMcpToolError();
        $result = $this->mcpResult()['structuredContent']['results'][0];
        $this->assertSame('The arguments of order_numbers must be an object.', $result['content'][0]['text']);
    }

    public function testCallToolsRespectsMaxCalls(): void
    {
        $call = ['name' => 'order_numbers', 'arguments' => new stdClass()];

        $this->mcpCallTool('call_tools', ['calls' => array_fill(0, 6, $call)]);

        $this->assertMcpJsonRpcError(-32602);
    }

    public function testScopeSimulationCoversInnerCalls(): void
    {
        $this->scoped();
        $this->mcpActingAs(['id' => 1], ['notes:read']);

        $this->mcpCallTool('call_tools', ['calls' => [['name' => 'order_status', 'arguments' => ['number' => 'A-1']]]]);

        $this->assertMcpInsufficientScope(['orders:read']);
    }

    public function testFindToolsHidesToolsTheTokenCannotCall(): void
    {
        $this->scoped();
        $this->mcpActingAs(['id' => 1], ['notes:read']);

        $this->mcpCallTool('find_tools', ['query' => 'order']);

        $this->assertMcpToolSucceeded();
        $names = array_column($this->mcpResult()['structuredContent']['tools'], 'name');
        $this->assertNotContains('order_status', $names);
        $this->assertContains('order_numbers', $names);
    }

    public function testEachInnerCallChecksItsScopesInTheServer(): void
    {
        // Straight to the server, past the HTTP step and the trait's simulation: only the server's own check stands.
        $this->scoped();
        $container = new Container();
        $identity = new CurrentIdentity();
        $container->addShared(CurrentIdentity::class, $identity);
        $identity->set(['id' => 1]);
        $transport = new RecordingTransport(array_map(fn(array $message): string => (string)json_encode($message), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'call_tools',
                'arguments' => ['calls' => [['name' => 'order_status', 'arguments' => ['number' => 'A-1']]]],
            ]],
        ]), new AccessToken(['notes:read'], ['sub' => '1']));

        (new ServerFactory($container, $identity))->create()->run($transport);

        $result = $transport->responseFor(2)['result']['structuredContent']['results'][0] ?? [];
        $this->assertTrue($result['isError'] ?? false);
        $this->assertSame('Tool not found: "order_status".', $result['content'][0]['text'] ?? null);
    }

    public function testInnerCallsAreAuditedUnderTheirOwnName(): void
    {
        Log::setConfig('audit_catalog', ['className' => ArrayLog::class, 'scopes' => ['mcp_audit']]);
        Configure::write('Mcp.audit.enabled', true);
        Configure::write('Mcp.authorization.authorizer', $this->denying('find_order'));
        try {
            $this->mcpCallTool('call_tools', ['calls' => [
                ['name' => 'order_status', 'arguments' => ['number' => 'A-1']],
                ['name' => 'find_order', 'arguments' => ['number' => 'A-1']],
            ]]);

            $engine = Log::engine('audit_catalog');
            $lines = array_map('strval', $engine instanceof ArrayLog ? $engine->read() : []);
            $this->assertCount(3, $lines);
            $this->assertStringContainsString('MCP tools/call order_status ok', $lines[0]);
            $this->assertStringContainsString(' via=call_tools', $lines[0]);
            $this->assertStringContainsString('MCP tools/call find_order denied', $lines[1]);
            $this->assertStringContainsString(' via=call_tools', $lines[1]);
            $this->assertStringContainsString('MCP tools/call call_tools tool_error', $lines[2]);
            $this->assertStringNotContainsString('via=', $lines[2]);
            // The outer record names the failing call, never the output of the calls that ran.
            $this->assertStringContainsString('find_order', $lines[2]);
            $this->assertStringNotContainsString('results', $lines[2]);
        } finally {
            Log::drop('audit_catalog');
        }
    }

    public function testFindToolsReturnsAnnotations(): void
    {
        $this->mcpCallTool('find_tools', ['query' => 'status']);

        $this->assertMcpToolSucceeded();
        $this->assertSame(['readOnlyHint' => true], $this->mcpResult()['structuredContent']['tools'][0]['annotations'] ?? null);
    }

    public function testHostExtensionsWithTheCatalogFail(): void
    {
        foreach (['addRequestHandler', 'setReferenceHandler'] as $method) {
            Configure::write('Mcp.server.configure', function (Builder $builder) use ($method): void {
                $method === 'addRequestHandler'
                    ? $builder->addRequestHandler($this->createStub(RequestHandlerInterface::class))
                    : $builder->setReferenceHandler($this->createStub(ReferenceHandlerInterface::class));
            });
            try {
                $this->mcpListTools();
                $this->fail('The server was built with ' . $method);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('call_tools would not go through it', $e->getMessage(), $method);
            }
        }
    }

    public function testAHookToolNamedLikeTheCatalogFails(): void
    {
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->addTool(fn(): string => 'mine', 'call_tools');
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"call_tools" is the tool catalog\'s own tool');

        $this->mcpListTools();
    }

    public function testAHookToolNamedLikeTheCatalogAddedWithADefinitionFails(): void
    {
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->add(
                new Tool('find_tools', null, ['type' => 'object', 'properties' => new stdClass()], 'Mine', null),
                new class implements ToolHandlerInterface {
                    public function execute(array $arguments, ClientGateway $gateway): mixed
                    {
                        return 'mine';
                    }
                },
            );
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"find_tools" is the tool catalog\'s own tool');

        $this->mcpListTools();
    }

    public function testADiscoveredToolNamedLikeTheCatalogFails(): void
    {
        // The SDK skips a discovered tool whose name is taken: without the check it would vanish in silence.
        Configure::write('Mcp.discovery.scanDirs', ['src/McpDocs', 'src/McpCatalogClash']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"find_tools" is the tool catalog\'s own tool: rename the discovered tool');

        $this->mcpListTools();
    }

    public function testInnerCallsDoNotReuseTheProgressToken(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpProgress']);
        Configure::write('Mcp.catalog.tools', ['import_orders']);

        $this->mcpCallTool('call_tools', ['calls' => [['name' => 'import_orders', 'arguments' => new stdClass()]]]);

        $this->assertMcpToolSucceeded();
        $this->assertSame([], $this->mcpNotifications('notifications/progress'));
    }

    public function testViaCannotBeSpoofed(): void
    {
        Log::setConfig('audit_spoof', ['className' => ArrayLog::class, 'scopes' => ['mcp_audit']]);
        Configure::write('Mcp.audit.enabled', true);
        try {
            $this->direct([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'order_status',
                'arguments' => ['number' => 'A-1'],
                '_meta' => ['io.cakedc.mcp/via' => 'call_tools'],
            ]]]);

            $engine = Log::engine('audit_spoof');
            $lines = array_map('strval', $engine instanceof ArrayLog ? $engine->read() : []);
            $this->assertCount(1, $lines);
            $this->assertStringNotContainsString('via=', $lines[0]);
        } finally {
            Log::drop('audit_spoof');
        }
    }

    public function testImpliedScopesSatisfyTheServerCheck(): void
    {
        $this->scoped();
        Configure::write('Mcp.auth.scopes.implies', ['orders:admin' => ['orders:read']]);

        $transport = $this->direct([['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'call_tools',
            'arguments' => ['calls' => [['name' => 'order_status', 'arguments' => ['number' => 'A-1']]]],
        ]]], new AccessToken(['orders:admin'], ['sub' => '1']));

        $result = $transport->responseFor(2)['result']['structuredContent']['results'][0] ?? [];
        $this->assertFalse($result['isError'] ?? true, (string)json_encode($result));
    }

    public function testCatalogOffChangesNothing(): void
    {
        Configure::write('Mcp.catalog.enabled', false);

        $names = array_column($this->mcpListTools(), 'name');

        $this->assertContains('order_status', $names);
        $this->assertNotContains('find_tools', $names);
    }

    /**
     * Straight to the server, past the HTTP step and the trait's simulation.
     *
     * @param list<array<string, mixed>> $requests
     */
    private function direct(array $requests, ?AccessToken $token = null): RecordingTransport
    {
        $container = new Container();
        $identity = new CurrentIdentity();
        $container->addShared(CurrentIdentity::class, $identity);
        $identity->set(['id' => 1]);
        $transport = new RecordingTransport(array_map(fn(array $message): string => (string)json_encode($message), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ...$requests,
        ]), $token);
        (new ServerFactory($container, $identity))->create()->run($transport);

        return $transport;
    }

    private function scoped(): void
    {
        Cache::setConfig('mcp_oauth_test', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            'scopes' => ['tools' => ['order_status' => ['orders:read']]],
            'external' => ['issuer' => 'https://issuer.example', 'cache' => 'mcp_oauth_test'],
        ]);
    }

    private function denying(string $denied): ToolAuthorizerInterface
    {
        return new class ($denied) implements ToolAuthorizerInterface {
            public function __construct(private readonly string $denied)
            {
            }

            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool !== $this->denied;
            }
        };
    }
}
