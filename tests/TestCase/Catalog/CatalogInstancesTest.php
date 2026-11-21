<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Catalog;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Server\ServerFactory;
use CakeDC\Mcp\Test\Support\CountingTool;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use Mcp\Server\Builder;
use stdClass;

/**
 * A catalogued tool runs on the same object whether it is called by name or through `call_tools`.
 */
class CatalogInstancesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', []);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['bump']]);
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->addTool([CountingTool::class, 'bump'], 'bump', description: 'Counts its calls.');
        });
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testDirectAndCatalogCallsShareTheToolObject(): void
    {
        $call = fn(int $id, string $name, array $arguments = []): string => (string)json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments === [] ? new stdClass() : $arguments],
        ]);
        $transport = new RecordingTransport([
            (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]]),
            (string)json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
            $call(2, 'bump'),
            $call(3, 'call_tools', ['calls' => [['name' => 'bump']]]),
            $call(4, 'bump'),
        ]);
        $identity = new CurrentIdentity();
        $identity->set(['id' => 1]);
        $container = new Container();
        $container->addShared(CurrentIdentity::class, $identity);

        (new ServerFactory($container, $identity))->forServer('default')->create(true)->run($transport);

        $this->assertSame('1', $transport->responseFor(2)['result']['content'][0]['text'] ?? null);
        $inner = $transport->responseFor(3)['result']['structuredContent']['results'][0]['content'][0]['text'] ?? null;
        $this->assertSame('2', $inner, 'call_tools ran the tool on another object.');
        $this->assertSame('3', $transport->responseFor(4)['result']['content'][0]['text'] ?? null);
    }
}
