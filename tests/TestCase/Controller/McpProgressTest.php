<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use stdClass;
use TestApp\TestTokens;

/**
 * Progress notifications over HTTP (TestApp\McpProgress\SlowTools): server-sent events, then the result.
 */
class McpProgressTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Mcp.discovery.scanDirs', ['src/McpProgress']);
    }

    public function testModernCallStreamsProgressThenTheResult(): void
    {
        $this->modernCall(['progressToken' => 'p1']);

        $this->assertResponseOk();
        $this->assertSame('text/event-stream', $this->_response->getHeaderLine('Content-Type'));
        $events = $this->events();
        $this->assertSame(['notifications/progress', 'notifications/progress'], array_column(array_slice($events, 0, 2), 'method'));
        $this->assertSame(['p1', 'p1'], array_column(array_column(array_slice($events, 0, 2), 'params'), 'progressToken'));
        $this->assertSame('Imported 10 orders.', $events[2]['result']['content'][0]['text'] ?? null);
    }

    public function testHandshakeCallStreamsProgressThenTheResult(): void
    {
        $headers = $this->session();
        $this->post('/mcp', (string)json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'import_orders',
            'arguments' => new stdClass(),
            '_meta' => ['progressToken' => 'p2'],
        ]]));

        $this->assertResponseOk();
        $events = $this->events();
        $this->assertSame([2, 2], array_column(array_column(array_slice($events, 0, 2), 'params'), 'total'));
        $this->assertSame(2, $events[2]['id'] ?? null);
        $this->assertNotSame([], $headers);
    }

    public function testNoProgressTokenNoProgress(): void
    {
        $this->modernCall([]);

        $this->assertResponseOk();
        $methods = array_column($this->events(), 'method');
        $this->assertNotContains('notifications/progress', $methods);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function modernCall(array $meta): void
    {
        $this->configRequest(['headers' => $this->headers() + [
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'import_orders',
        ]]);
        $this->post('/mcp', (string)json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => [
            'name' => 'import_orders',
            'arguments' => new stdClass(),
            '_meta' => $meta + [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            ],
        ]]));
    }

    /**
     * Opens a 2025-06-18 session and leaves its headers configured for the next request.
     *
     * @return array<string, string>
     */
    private function session(): array
    {
        $this->configRequest(['headers' => $this->headers()]);
        $this->post('/mcp', (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ]]));
        $headers = $this->headers() + [
            'Mcp-Session-Id' => $this->_response->getHeaderLine('Mcp-Session-Id'),
            'MCP-Protocol-Version' => '2025-06-18',
        ];
        $this->configRequest(['headers' => $headers]);
        $this->post('/mcp', (string)json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        $this->configRequest(['headers' => $headers]);

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . TestTokens::EDITOR,
        ];
    }

    /**
     * The JSON messages of the response, from its `data:` lines (or the body when it is plain JSON).
     *
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        // An event stream echoes and flushes as it is read, the way it reaches a real client: a buffer with a
        // callback keeps every flushed chunk.
        $captured = '';
        ob_start(function (string $chunk) use (&$captured): string {
            $captured .= $chunk;

            return '';
        });
        $returned = (string)$this->_response->getBody();
        ob_end_clean();
        $body = $captured . $returned;
        if (!str_contains($body, 'data:')) {
            return [json_decode($body, true)];
        }
        $events = [];
        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'data:')) {
                $events[] = json_decode(trim(substr($line, 5)), true);
            }
        }

        return $events;
    }
}
