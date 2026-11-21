<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\ClientRegistry;
use CakeDC\Mcp\Client\Exception\NotConnectedException;
use CakeDC\Mcp\Client\ListCache;
use CakeDC\Mcp\Client\ListChangedHandler;
use CakeDC\Mcp\Client\McpClients;
use CakeDC\Mcp\Client\Store\StoredConnection;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use CakeDC\Mcp\Test\Support\FakeOAuthServer;
use CakeDC\Mcp\Test\Support\FixedHttpClientFactory;
use CakeDC\Mcp\Test\Support\SdkServerHttpClient;
use InvalidArgumentException;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server\Wire\CachePolicy;
use stdClass;

/**
 * `Mcp.clients.<name>.cache` keeps list results that carry 2026-07-28 cache hints for their ttl, keyed so a
 * private list is never served to another user.
 */
class ClientCacheTest extends TestCase
{
    private SdkServerHttpClient $server;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('mcp_client_lists', ['className' => ArrayEngine::class]);
        DateTime::setTestNow(new DateTime('2026-11-21 10:00:00'));
        $this->server = new SdkServerHttpClient();
        McpClients::setRegistry(new ClientRegistry(new FixedHttpClientFactory($this->server)));
        Configure::write('Mcp.clients.remote', [
            'url' => 'https://mcp.example.com/mcp',
            'token' => 'app-token',
            'cache' => 'mcp_client_lists',
        ]);
    }

    protected function tearDown(): void
    {
        McpClients::reset();
        Cache::drop('mcp_client_lists');
        DateTime::setTestNow(null);
        parent::tearDown();
    }

    private function sent(string $method): int
    {
        return count(array_filter($this->server->methods(), fn(string $m): bool => $m === $method));
    }

    public function testAListWithHintsIsServedFromTheCacheWithinItsTtl(): void
    {
        $this->server->cachePolicy = CachePolicy::default(60000, CacheScope::Private);

        $first = McpClients::get('remote')->listTools();
        McpClients::reset();
        McpClients::setRegistry(new ClientRegistry(new FixedHttpClientFactory($this->server)));
        $second = McpClients::get('remote')->listTools();

        $this->assertSame(1, $this->sent('tools/list'));
        $this->assertEquals($first, $second);
        $this->assertSame(['readme'], array_map(fn($r) => $r->name, McpClients::get('remote')->listResources()));
        McpClients::get('remote')->listResources();
        $this->assertSame(1, $this->sent('resources/list'));
    }

    public function testAnExpiredEntryIsFetchedAgain(): void
    {
        $this->server->cachePolicy = CachePolicy::default(60000, CacheScope::Private);
        McpClients::get('remote')->listTools();

        DateTime::setTestNow(new DateTime('2026-11-21 10:01:01'));
        McpClients::get('remote')->listTools();

        $this->assertSame(2, $this->sent('tools/list'));
    }

    public function testAZeroTtlIsNotCached(): void
    {
        $this->server->cachePolicy = CachePolicy::default(0, CacheScope::Public);

        McpClients::get('remote')->listTools();
        McpClients::get('remote')->listTools();

        $this->assertSame(2, $this->sent('tools/list'));
    }

    public function testNoHintsNoCaching(): void
    {
        McpClients::get('remote')->listTools();
        McpClients::get('remote')->listTools();

        $this->assertSame(2, $this->sent('tools/list'));
    }

    public function testWithoutTheSettingNothingIsCached(): void
    {
        Configure::delete('Mcp.clients.remote.cache');
        $this->server->cachePolicy = CachePolicy::default(60000, CacheScope::Public);

        McpClients::get('remote')->listTools();
        McpClients::get('remote')->listTools();

        $this->assertSame(2, $this->sent('tools/list'));
    }

    public function testAnUnknownCacheConfigFailsAtConfigRead(): void
    {
        Configure::write('Mcp.clients.remote.cache', 'no_such_cache');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.clients.remote.cache"');
        ClientConfig::fromConfig('remote');
    }

    public function testListChangedDropsTheEntry(): void
    {
        $this->server->cachePolicy = CachePolicy::default(60000, CacheScope::Private);
        $cache = new ListCache(ClientConfig::fromConfig('remote'), null);
        McpClients::get('remote')->listTools();
        $this->assertNotNull($cache->get('tools/list', null));

        $handler = new ListChangedHandler($cache);
        $this->assertTrue($handler->supports(new ToolListChangedNotification()));
        $handler->handle(new ToolListChangedNotification());

        $this->assertNull($cache->get('tools/list', null));
        McpClients::get('remote')->listTools();
        $this->assertSame(2, $this->sent('tools/list'));
    }

    public function testAToolListWithHeaderAnnotationsIsNotCached(): void
    {
        $cache = new ListCache(ClientConfig::fromConfig('remote'), null);

        $cache->put('tools/list', null, [
            'tools' => [['name' => 'a', 'inputSchema' => ['type' => 'object', 'properties' => [
                'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
            ]]]],
            'ttlMs' => 60000,
            'cacheScope' => 'public',
        ], new stdClass());

        // The SDK needs the listing to send the Mcp-Param headers of those tools.
        $this->assertNull($cache->get('tools/list', null));
    }

    private function userMode(): FakeOAuthServer
    {
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM mcp_client_connections');
        $db->execute('DELETE FROM mcp_client_registrations');
        Configure::write('Mcp.clients.linear', [
            'url' => FakeOAuthServer::MCP,
            'auth' => 'user',
            'cache' => 'mcp_client_lists',
        ]);
        $oauth = new FakeOAuthServer();
        $store = new TableConnectionStore();
        McpClients::setRegistry($oauth->registry($store));
        foreach (['7', '8'] as $userId) {
            $oauth->mcp->tokens[] = 'at-' . $userId;
            $store->save(new StoredConnection(
                'linear',
                $userId,
                'at-' . $userId,
                'rt-' . $userId,
                DateTime::now()->getTimestamp() + 3600,
                'read',
                FakeOAuthServer::ISSUER,
                FakeOAuthServer::ISSUER . '/token',
                FakeOAuthServer::ISSUER . '/revoke',
                'dyn-client-1',
                null,
                'none',
                FakeOAuthServer::MCP,
            ));
        }

        return $oauth;
    }

    public function testAPrivateListIsNeverServedToAnotherUser(): void
    {
        $oauth = $this->userMode();
        $oauth->mcp->cachePolicy = CachePolicy::default(60000, CacheScope::Private);

        McpClients::get('linear')->for(['id' => 7])->listTools();
        McpClients::get('linear')->for(['id' => 7])->listTools();
        McpClients::get('linear')->for(['id' => 8])->listTools();

        $lists = array_filter($oauth->mcp->requests, fn(array $r): bool => $r['rpc'] === 'tools/list');
        $this->assertSame(
            ['Bearer at-7', 'Bearer at-8'],
            array_values(array_map(fn(array $r): string => $r['headers']['authorization'] ?? '', $lists)),
        );
    }

    public function testAPublicListIsShared(): void
    {
        $oauth = $this->userMode();
        $oauth->mcp->cachePolicy = CachePolicy::default(60000, CacheScope::Public);

        McpClients::get('linear')->for(['id' => 7])->listTools();
        McpClients::get('linear')->for(['id' => 8])->listTools();

        $this->assertCount(1, array_filter($oauth->mcp->requests, fn(array $r): bool => $r['rpc'] === 'tools/list'));
    }

    public function testAUserWhoIsNotConnectedGetsNoCachedList(): void
    {
        $oauth = $this->userMode();
        $oauth->mcp->cachePolicy = CachePolicy::default(60000, CacheScope::Public);
        McpClients::get('linear')->for(['id' => 7])->listTools();

        $this->expectException(NotConnectedException::class);
        McpClients::get('linear')->for(['id' => 9])->listTools();
    }
}
