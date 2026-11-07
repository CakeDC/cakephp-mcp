<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Event;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Audit\AuditRecord;
use CakeDC\Mcp\Audit\AuditSettings;
use CakeDC\Mcp\Audit\AuditWriter;
use CakeDC\Mcp\Authorization\AllowAllToolAuthorizer;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolGate;
use CakeDC\Mcp\Event\AuditListener;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use Mcp\Event\ErrorEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class AuditListenerTest extends TestCase
{
    use IntegrationTestTrait;
    use McpTestTrait;

    /**
     * @var list<array<string, mixed>>
     */
    private array $records = [];

    /**
     * @var callable
     */
    private $listener;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.audit.enabled', true);
        Configure::write('Mcp.audit.log', false);
        $this->records = [];
        $this->listener = function (EventInterface $event): void {
            $this->records[] = (array)$event->getData();
        };
        EventManager::instance()->on('Mcp.audit', $this->listener);
    }

    protected function tearDown(): void
    {
        EventManager::instance()->off('Mcp.audit', $this->listener);
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function onlyRecord(): array
    {
        $this->assertCount(1, $this->records);

        return $this->records[0];
    }

    private function onlyForAdmins(string $tool): ToolAuthorizerInterface
    {
        return new class ($tool) implements ToolAuthorizerInterface {
            public function __construct(private readonly string $tool)
            {
            }

            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool !== $this->tool || (is_array($identity) && ($identity['role'] ?? null) === 'admin');
            }
        };
    }

    private function listener(?ToolGate $gate = null): AuditListener
    {
        $identity = new CurrentIdentity();

        return new AuditListener(
            AuditSettings::fromConfig(),
            new AuditWriter(AuditSettings::fromConfig()),
            $gate ?? new ToolGate(new AllowAllToolAuthorizer(), $identity),
            $identity,
        );
    }

    public function testOffByDefault(): void
    {
        Configure::delete('Mcp.audit');

        $this->mcpCallTool('echo', ['text' => 'hi']);

        $this->assertSame([], $this->records);
    }

    public function testOkCall(): void
    {
        $this->mcpActingAs(['id' => 7]);

        $this->mcpCallTool('echo', ['text' => 'hi']);

        $record = $this->onlyRecord();
        $this->assertSame('tools/call', $record['method']);
        $this->assertSame('echo', $record['name']);
        $this->assertSame(AuditRecord::OK, $record['outcome']);
        $this->assertSame(7, $record['identity']);
        $this->assertSame(['text' => 'hi'], $record['arguments']);
        $this->assertIsInt($record['durationMs']);
        $this->assertGreaterThanOrEqual(0, $record['durationMs']);
        $this->assertIsString($record['session']);
        $this->assertNotSame('', $record['session']);
        $this->assertSame(2, $record['request']);
        $this->assertNull($record['error']);
    }

    public function testToolError(): void
    {
        $this->mcpCallTool('create_note', ['title' => 'ab']);

        $record = $this->onlyRecord();
        $this->assertSame(AuditRecord::TOOL_ERROR, $record['outcome']);
        $this->assertStringContainsString('Use at least 3 characters.', (string)$record['error']);
    }

    public function testThrowingToolIsError(): void
    {
        $this->mcpCallTool('explode');

        $record = $this->onlyRecord();
        $this->assertSame(AuditRecord::ERROR, $record['outcome']);
        $this->assertSame('Error while executing tool', $record['error']);
    }

    public function testUnknownToolAndBadArgumentsAreInvalid(): void
    {
        $this->mcpCallTool('missing_tool');
        $this->mcpCallTool('echo', ['text' => ['not', 'a', 'string']]);

        $this->assertCount(2, $this->records);
        $this->assertSame(AuditRecord::INVALID, $this->records[0]['outcome']);
        $this->assertSame(AuditRecord::INVALID, $this->records[1]['outcome']);
    }

    public function testDeniedIsRecordedButLooksUnknownToTheClient(): void
    {
        Configure::write('Mcp.authorization.authorizer', $this->onlyForAdmins('admin_only'));
        $this->mcpActingAs(['id' => 1, 'role' => 'user']);

        $denied = $this->mcpCallTool('admin_only');
        $unknown = $this->mcpCallTool('missing_tool');

        $this->assertSame(AuditRecord::DENIED, $this->records[0]['outcome']);
        $this->assertSame(AuditRecord::INVALID, $this->records[1]['outcome']);
        $this->assertSame(
            json_encode($unknown),
            str_replace('admin_only', 'missing_tool', (string)json_encode($denied)),
        );
    }

    public function testUnknownToolIsInvalidEvenWhenTheAuthorizerRefusesUnknownNames(): void
    {
        // Like RbacToolAuthorizer: only the granted tools pass, so a missing tool is refused too.
        Configure::write('Mcp.authorization.authorizer', new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool === 'echo';
            }
        });
        $this->mcpActingAs(['id' => 1]);

        $unknown = $this->mcpCallTool('no_such_tool');
        $denied = $this->mcpCallTool('admin_only');

        $this->assertSame(AuditRecord::INVALID, $this->records[0]['outcome']);
        $this->assertSame(AuditRecord::DENIED, $this->records[1]['outcome']);
        $this->assertSame(
            json_encode($unknown),
            str_replace('admin_only', 'no_such_tool', (string)json_encode($denied)),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function claims(): array
    {
        return [
            'client_id' => [['client_id' => 'a'], 'a'],
            'azp' => [['azp' => 'b'], 'b'],
            'aud string' => [['aud' => 'c'], 'c'],
            'aud list of one' => [['aud' => ['d']], 'd'],
            'aud list of two' => [['aud' => ['d', 'e']], null],
        ];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('claims')]
    public function testClientFromClaims(array $claims, ?string $client): void
    {
        $this->assertSame($client, AuditListener::clientOf(new AccessToken([], $claims + ['sub' => '1'])));
        $this->mcpActingAs(['id' => 1], null, $claims);

        $this->mcpCallTool('echo', ['text' => 'hi']);

        $this->assertSame($client, $this->onlyRecord()['client']);
    }

    public function testNoTokenNoClient(): void
    {
        $this->assertNull(AuditListener::clientOf(null));
    }

    public function testListingIsNotRecorded(): void
    {
        $this->mcpListTools();

        $this->assertSame([], $this->records);
    }

    public function testPromptsAndResourcesOnlyWhenListed(): void
    {
        $session = new Session(new InMemorySessionStore());
        $prompt = GetPromptRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'prompts/get',
            'params' => ['name' => 'briefing', 'arguments' => ['days' => '14']],
        ]);
        $resource = ReadResourceRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 6, 'method' => 'resources/read', 'params' => ['uri' => 'app://glossary'],
        ]);

        $listener = $this->listener();
        $listener->onResponse(new ResponseEvent(new Response(5, []), $prompt, $session));
        $this->assertSame([], $this->records);

        Configure::write('Mcp.audit.methods', ['prompts/get', 'resources/read']);
        $listener = $this->listener();
        $listener->onResponse(new ResponseEvent(new Response(5, []), $prompt, $session));
        $listener->onResponse(new ResponseEvent(new Response(6, []), $resource, $session));

        $this->assertSame('briefing', $this->records[0]['name']);
        $this->assertSame(['days' => '14'], $this->records[0]['arguments']);
        $this->assertSame('app://glossary', $this->records[1]['name']);
        $this->assertSame([], $this->records[1]['arguments']);
    }

    public function testTrustedGateHasNoDenials(): void
    {
        $identity = new CurrentIdentity();
        $listener = $this->listener(new ToolGate($this->onlyForAdmins('admin_only'), $identity, true));
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'admin_only', 'arguments' => []],
        ]);

        $listener->onError(new ErrorEvent(
            Error::forInvalidParams('Tool not found: "admin_only".', 3),
            $request,
            new Session(new InMemorySessionStore()),
            null,
        ));

        $this->assertSame(AuditRecord::INVALID, $this->onlyRecord()['outcome']);
    }

    public function testUnknownStartTimeGivesNullDuration(): void
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => []],
        ]);

        $this->listener()->onResponse(new ResponseEvent(new Response(4, []), $request, new Session(new InMemorySessionStore())));

        $this->assertNull($this->onlyRecord()['durationMs']);
    }

    public function testAToolWaitingForInputIsNotRecordedYet(): void
    {
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'confirm', 'arguments' => []],
        ]);

        $this->listener()->onResponse(new ResponseEvent(
            new Response(4, new InputRequiredResult(requestState: 'round-1')),
            $request,
            new Session(new InMemorySessionStore()),
        ));

        $this->assertSame([], $this->records);
    }

    public function testLongNamesAndRequestIdsAreCut(): void
    {
        $name = str_repeat('n', 300);
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => str_repeat('r', 300), 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => []],
        ]);

        $this->listener()->onResponse(new ResponseEvent(
            new Response(str_repeat('r', 300), []),
            $request,
            new Session(new InMemorySessionStore()),
        ));

        $this->assertSame(str_repeat('n', 255), $this->onlyRecord()['name']);
        $this->assertSame(str_repeat('r', 255), $this->onlyRecord()['request']);
    }

    public function testAFailingAuthorizerDoesNotEscapeTheListener(): void
    {
        $identity = new CurrentIdentity();
        $gate = new ToolGate(new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                throw new RuntimeException('authorizer exploded');
            }
        }, $identity);
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => []],
        ]);

        $this->listener($gate)->onError(new ErrorEvent(
            Error::forInvalidParams('Tool not found: "echo".', 3),
            $request,
            new Session(new InMemorySessionStore()),
            null,
        ));

        $this->assertSame([], $this->records);
    }

    public function testDurationComesFromTheClock(): void
    {
        $identity = new CurrentIdentity();
        $times = [10.0, 10.25];
        $listener = new AuditListener(
            AuditSettings::fromConfig(),
            new AuditWriter(AuditSettings::fromConfig()),
            new ToolGate(new AllowAllToolAuthorizer(), $identity),
            $identity,
            function () use (&$times): float {
                return (float)array_shift($times);
            },
        );
        $session = new Session(new InMemorySessionStore());
        $request = CallToolRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => []],
        ]);

        $listener->onRequest(new RequestEvent($request, $session));
        $listener->onResponse(new ResponseEvent(new Response(4, []), $request, $session));

        $this->assertSame(250, $this->onlyRecord()['durationMs']);
    }
}
