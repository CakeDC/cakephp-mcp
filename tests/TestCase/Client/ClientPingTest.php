<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ClientRegistry;
use CakeDC\Mcp\Client\McpClients;
use CakeDC\Mcp\Client\PingHandler;
use CakeDC\Mcp\Test\Support\FixedHttpClientFactory;
use CakeDC\Mcp\Test\Support\SdkServerHttpClient;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\EmptyResult;

/**
 * The client answers the server's `ping`, and closes its sessions when the process ends.
 */
class ClientPingTest extends TestCase
{
    public function testAPingIsAnsweredWithAnEmptyResult(): void
    {
        $handler = new PingHandler();
        $ping = (new PingRequest())->withId(7);

        $this->assertTrue($handler->supports($ping));
        $this->assertFalse($handler->supports((new ListRootsRequest())->withId(8)));
        $response = $handler->handle($ping);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(7, $response->getId());
        $this->assertInstanceOf(EmptyResult::class, $response->result);
    }

    public function testTheSessionsAreClosedWhenTheProcessEnds(): void
    {
        $server = new SdkServerHttpClient();
        McpClients::setRegistry(new ClientRegistry(new FixedHttpClientFactory($server)));
        Configure::write('Mcp.clients.remote', [
            'url' => 'https://mcp.example.com/mcp',
            'token' => 't',
            'protocolVersion' => '2025-06-18',
        ]);
        McpClients::get('remote')->listTools();

        McpClients::closeAtShutdown();

        $this->assertSame('DELETE', end($server->requests)['method']);
        McpClients::reset();
    }
}
