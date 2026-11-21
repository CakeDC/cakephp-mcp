<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Builder;
use Mcp\Server\ClientGateway;
use Mcp\Server\RequestContext;
use stdClass;
use TestApp\TestTokens;

/**
 * A tool run by `call_tools` that asks the user for input (2026-07-28 revision) ends that call with a tool error:
 * the client would re-send the whole batch with the answer, and the tools before it would run again.
 */
class McpCatalogInputTest extends TestCase
{
    use IntegrationTestTrait;

    public static int $created = 0;

    public static int $asked = 0;

    protected function setUp(): void
    {
        parent::setUp();
        self::$created = 0;
        self::$asked = 0;
        Configure::write('Mcp.discovery.scanDirs', ['src/McpProgress']);
        Configure::write('Mcp.catalog', [
            'enabled' => true,
            'tools' => ['create_order', 'confirm_shipping', 'ask_who', 'import_orders'],
        ]);
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->addTool(function (): string {
                McpCatalogInputTest::$created++;

                return 'order created';
            }, name: 'create_order', description: 'Creates an order.');
            $builder->addTool(function (ClientGateway $client): string {
                $answer = $client->elicit(
                    'Ship it to which name?',
                    new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']),
                );

                return 'shipped to ' . ($answer->content['name'] ?? '?');
            }, name: 'confirm_shipping', description: 'Asks the user before shipping.');
            $builder->addTool(function (RequestContext $context): mixed {
                McpCatalogInputTest::$asked++;
                $input = $context->getInputContext();
                if ($input === null || !$input->has('who')) {
                    return new InputRequiredResult(['who' => new ElicitRequest(
                        'Who?',
                        new ElicitationSchema(['name' => new StringSchemaDefinition('Name')], ['name']),
                    )]);
                }

                return 'hello';
            }, name: 'ask_who', description: 'Needs input across rounds.');
        });
    }

    protected function tearDown(): void
    {
        if (in_array('audit_input', Log::configured(), true)) {
            Log::drop('audit_input');
        }
        parent::tearDown();
    }

    public function testAToolThatElicitsEndsTheBatchWithAToolErrorAndKeepsWhatRan(): void
    {
        $this->call(1, 'call_tools', ['calls' => [
            ['name' => 'create_order', 'arguments' => new stdClass()],
            ['name' => 'confirm_shipping', 'arguments' => new stdClass()],
            ['name' => 'create_order', 'arguments' => new stdClass()],
        ]]);

        $result = $this->json()['result'] ?? [];
        $this->assertSame('complete', $result['resultType'] ?? null, (string)json_encode($result));
        $this->assertTrue($result['isError'] ?? null);
        $results = $result['structuredContent']['results'] ?? [];
        $this->assertSame(['create_order', 'confirm_shipping'], array_column($results, 'name'));
        $this->assertFalse($results[0]['isError']);
        $this->assertTrue($results[1]['isError']);
        $this->assertSame(
            'confirm_shipping needs input from the user: call it on its own, not through call_tools.',
            $results[1]['content'][0]['text'] ?? null,
        );
        $this->assertSame(1, self::$created);
    }

    public function testAToolThatReturnsInputRequiredEndsWithAToolError(): void
    {
        $this->call(1, 'call_tools', ['calls' => [['name' => 'ask_who', 'arguments' => new stdClass()]]]);

        $results = $this->json()['result']['structuredContent']['results'] ?? [];
        $this->assertSame(
            'ask_who needs input from the user: call it on its own, not through call_tools.',
            $results[0]['content'][0]['text'] ?? null,
        );
        $this->assertSame(1, self::$asked);
    }

    public function testCalledOnItsOwnAToolStillAsks(): void
    {
        $this->call(1, 'ask_who', new stdClass());

        $this->assertSame('input_required', $this->json()['result']['resultType'] ?? null);
    }

    public function testAToolThatAskedIsAuditedUnderItsName(): void
    {
        Log::setConfig('audit_input', ['className' => ArrayLog::class, 'scopes' => ['mcp_audit']]);
        Configure::write('Mcp.audit.enabled', true);

        $this->call(1, 'call_tools', ['calls' => [
            ['name' => 'ask_who', 'arguments' => new stdClass()],
        ]]);
        $this->call(2, 'call_tools', ['calls' => [
            ['name' => 'confirm_shipping', 'arguments' => new stdClass()],
        ]]);

        $engine = Log::engine('audit_input');
        $lines = implode("\n", array_map('strval', $engine instanceof ArrayLog ? $engine->read() : []));
        $this->assertStringContainsString('MCP tools/call ask_who tool_error', $lines);
        $this->assertStringContainsString('MCP tools/call confirm_shipping tool_error', $lines);
    }

    public function testProgressOfAnInnerToolStillReachesTheClient(): void
    {
        $this->call(1, 'call_tools', ['calls' => [['name' => 'import_orders', 'arguments' => new stdClass()]]]);

        $this->assertResponseOk();
        $this->assertFalse($this->json()['result']['isError'] ?? true, (string)$this->_response->getBody());
    }

    /**
     * The final JSON-RPC response, from a JSON body or the last frame of an event stream.
     *
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $body = (string)$this->_response->getBody();
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $last = [];
        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $frame = json_decode(substr($line, 6), true);
                if (is_array($frame) && array_key_exists('id', $frame)) {
                    $last = $frame;
                }
            }
        }

        return $last;
    }

    private function call(int $id, string $name, mixed $arguments): void
    {
        $this->configRequest(['headers' => [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . TestTokens::EDITOR,
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => $name,
        ]]);
        $this->post('/mcp', (string)json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name' => $name,
            'arguments' => $arguments,
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => ['elicitation' => ['form' => new stdClass()]],
                'progressToken' => 'p1',
            ],
        ]]));
    }
}
