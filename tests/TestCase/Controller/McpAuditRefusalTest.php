<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\McpHttpTrait;

class McpAuditRefusalTest extends TestCase
{
    use IntegrationTestTrait;
    use McpHttpTrait;

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
        Configure::write('Mcp.audit.enabled', true);
        Log::setConfig('audit_test', ['className' => ArrayLog::class, 'scopes' => ['mcp_audit']]);
        $this->records = [];
        $this->listener = function (EventInterface $event): void {
            $this->records[] = (array)$event->getData();
        };
        EventManager::instance()->on('Mcp.audit', $this->listener);
    }

    protected function tearDown(): void
    {
        EventManager::instance()->off('Mcp.audit', $this->listener);
        Log::drop('audit_test');
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $engine = Log::engine('audit_test');
        $this->assertInstanceOf(ArrayLog::class, $engine);

        return array_values(array_map('strval', $engine->read()));
    }

    public function testUnauthorizedToolCallIsRecorded(): void
    {
        $this->callTool('echo', ['text' => 'hi']);

        $this->assertResponseCode(401);
        $this->assertCount(1, $this->records);
        $record = $this->records[0];
        $this->assertSame('tools/call', $record['method']);
        $this->assertSame('echo', $record['name']);
        $this->assertSame('unauthorized', $record['outcome']);
        $this->assertNull($record['identity']);
        $this->assertNull($record['client']);
        $this->assertNull($record['session']);
        $this->assertSame(['text' => 'hi'], $record['arguments']);
        $this->assertStringStartsWith('warning: ', $this->lines()[0]);
    }

    public function testUnauthorizedInitializeIsNot(): void
    {
        $this->postMcp($this->mcpMessage(1, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ]));

        $this->assertResponseCode(401);
        $this->assertSame([], $this->records);
    }

    public function testTheTokenNeverAppears(): void
    {
        $token = 'not-a-valid-token';
        $this->callTool('echo', ['text' => 'hi'], $token);

        $this->assertResponseCode(401);
        $this->assertCount(1, $this->records);
        $recorded = implode("\n", $this->lines()) . json_encode($this->records);
        $this->assertStringNotContainsString($token, $recorded);
        $this->assertStringNotContainsString('Bearer', $recorded);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unreadableBodies(): array
    {
        return [
            'empty' => [''],
            'not json' => ['{not json'],
            'a list' => ['[{"jsonrpc":"2.0","id":1,"method":"tools/call"}]'],
        ];
    }

    private function postRaw(string $body): void
    {
        $this->configRequest(['headers' => [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ]]);
        $this->post('/mcp', $body);
    }

    /**
     * @dataProvider unreadableBodies
     */
    public function testUnreadableBodyIsNotRecorded(string $body): void
    {
        $this->postRaw($body);
        $onStatus = $this->_response?->getStatusCode();
        $this->assertSame([], $this->records);

        Configure::write('Mcp.audit.enabled', false);
        $this->postRaw($body);

        $this->assertSame($onStatus, $this->_response?->getStatusCode());
        $this->assertContains($onStatus, [400, 401]);
    }

    public function testOffAddsNothing(): void
    {
        $this->callTool('echo', ['text' => 'hi']);
        $onBody = (string)$this->_response?->getBody();
        $onChallenge = $this->_response?->getHeaderLine('WWW-Authenticate');

        Configure::write('Mcp.audit.enabled', false);
        $this->records = [];
        $this->callTool('echo', ['text' => 'hi']);

        $this->assertResponseCode(401);
        $this->assertSame($onBody, (string)$this->_response?->getBody());
        $this->assertSame($onChallenge, $this->_response?->getHeaderLine('WWW-Authenticate'));
        $this->assertSame([], $this->records);
    }
}
