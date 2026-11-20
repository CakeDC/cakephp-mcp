<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\Exception\NotConnectedException;
use CakeDC\Mcp\Client\McpClients;
use CakeDC\Mcp\Client\Store\StoredConnection;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use CakeDC\Mcp\McpPlugin;
use CakeDC\Mcp\Test\Support\FakeOAuthServer;
use LogicException;

class UserConnectionTest extends TestCase
{
    private FakeOAuthServer $server;

    private TableConnectionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM mcp_client_connections');
        $db->execute('DELETE FROM mcp_client_registrations');
        Configure::write('Mcp.clients.linear', ['url' => FakeOAuthServer::MCP, 'auth' => 'user']);
        DateTime::setTestNow(new DateTime('2026-11-20 10:00:00'));
        $this->server = new FakeOAuthServer();
        $this->store = new TableConnectionStore();
        McpClients::setRegistry($this->server->registry($this->store));
    }

    protected function tearDown(): void
    {
        McpClients::reset();
        DateTime::setTestNow(null);
        Log::drop('client_test');
        parent::tearDown();
    }

    /**
     * Stores a grant for $userId whose access token the MCP server accepts (unless $accepted is false).
     */
    private function seed(
        string $userId = '7',
        int $expiresIn = 3600,
        ?string $refresh = 'rt-seed',
        bool $accepted = true,
    ): void {
        $access = 'at-seed-' . $userId;
        if ($accepted) {
            $this->server->mcp->tokens[] = $access;
        }
        $this->store->save(new StoredConnection(
            'linear',
            $userId,
            $access,
            $refresh,
            DateTime::now()->getTimestamp() + $expiresIn,
            'read write',
            FakeOAuthServer::ISSUER,
            FakeOAuthServer::ISSUER . '/token',
            FakeOAuthServer::ISSUER . '/revoke',
            'dyn-client-1',
            null,
            'none',
            FakeOAuthServer::MCP,
        ));
    }

    private function whoami(string $userId = '7'): ?string
    {
        $content = McpClients::get('linear')->for($userId)->callTool('whoami')->content[0];

        return property_exists($content, 'text') ? $content->text : null;
    }

    public function testNotConnectedWithoutAStoredGrant(): void
    {
        $connection = McpClients::get('linear')->for(7);

        $this->assertFalse($connection->isConnected());
        $this->expectException(NotConnectedException::class);
        $this->expectExceptionMessage('McpClients::get(\'linear\')->for($user)->connectUrl()');

        $connection->listTools();
    }

    public function testCallsSendTheUsersToken(): void
    {
        $this->seed();

        $this->assertTrue(McpClients::get('linear')->for(7)->isConnected());
        $this->assertSame('at-seed-7', $this->whoami());
        $this->assertSame([], $this->server->tokenRequests);
    }

    public function testRefreshesBeforeExpiry(): void
    {
        $this->seed('7', 30);

        $this->assertSame('at-1', $this->whoami());

        $sent = $this->server->tokenRequests[0];
        $this->assertSame('refresh_token', $sent['grant_type']);
        $this->assertSame('rt-seed', $sent['refresh_token']);
        $this->assertSame(FakeOAuthServer::MCP, $sent['resource']);
        $this->assertSame('dyn-client-1', $sent['client_id']);
        $stored = $this->store->get('linear', '7');
        $this->assertNotNull($stored);
        $this->assertSame('at-1', $stored->accessToken);
        $this->assertSame('rt-1', $stored->refreshToken);
        $this->assertSame(DateTime::now()->getTimestamp() + 3600, $stored->expiresAt);
        // The same connection keeps using the new token without refreshing again.
        $this->assertSame('at-1', $this->whoami());
        $this->assertCount(1, $this->server->tokenRequests);
    }

    public function testRejectedRefreshDisconnects(): void
    {
        $this->seed('7', -10);
        $this->server->rejectRefresh = true;

        try {
            $this->whoami();
            $this->fail('No exception');
        } catch (NotConnectedException) {
            $this->assertNull($this->store->get('linear', '7'));
            $this->assertFalse(McpClients::get('linear')->for(7)->isConnected());
        }
    }

    public function testAnExpiredTokenWithoutRefreshTokenIsNotConnected(): void
    {
        $this->seed('7', -10, null);

        $this->assertFalse(McpClients::get('linear')->for(7)->isConnected());
        $this->expectException(NotConnectedException::class);

        $this->whoami();
    }

    public function testA401RefreshesAndRetriesOnce(): void
    {
        $this->seed('7', 3600, 'rt-seed', false);

        $this->assertSame('at-1', $this->whoami());
        $this->assertCount(1, $this->server->tokenRequests);
    }

    public function testA401WithoutRefreshTokenDisconnects(): void
    {
        $this->seed('7', 3600, null, false);

        try {
            $this->whoami();
            $this->fail('No exception');
        } catch (NotConnectedException) {
            $this->assertNull($this->store->get('linear', '7'));
        }
    }

    public function testDisconnectRevokesAndDeletes(): void
    {
        $this->seed();
        $connection = McpClients::get('linear')->for(7);

        $connection->disconnect();

        $this->assertSame([[
            'token' => 'rt-seed',
            'token_type_hint' => 'refresh_token',
            'client_id' => 'dyn-client-1',
        ]], $this->server->revocations);
        $this->assertNull($this->store->get('linear', '7'));
        $this->assertFalse($connection->isConnected());
    }

    public function testDisconnectDeletesEvenWhenRevocationFails(): void
    {
        Log::setConfig('client_test', ['className' => ArrayLog::class, 'scopes' => ['mcp']]);
        $this->seed();
        $this->server->revocationFails = true;

        McpClients::get('linear')->for(7)->disconnect();

        $this->assertSame(['refresh_token', 'access_token'], array_column($this->server->revocations, 'token_type_hint'));
        $this->assertNull($this->store->get('linear', '7'));
        $logs = implode("\n", Log::engine('client_test')?->read() ?? []);
        $this->assertStringContainsString('token revocation answered with status 503', $logs);
        $this->assertStringNotContainsString('rt-seed', $logs);
        $this->assertStringNotContainsString('at-seed', $logs);
    }

    public function testDisconnectWithoutAGrantDoesNothing(): void
    {
        McpClients::get('linear')->for(7)->disconnect();

        $this->assertSame([], $this->server->revocations);
    }

    public function testUsersDoNotShareConnections(): void
    {
        $this->seed('7');
        $this->seed('8');

        $this->assertSame('at-seed-7', $this->whoami('7'));
        $this->assertSame('at-seed-8', $this->whoami('8'));
        $this->assertFalse(McpClients::get('linear')->for(9)->isConnected());
    }

    public function testToolFiltersApplyInUserMode(): void
    {
        Configure::write('Mcp.clients.linear.tools.except', ['whoami']);
        McpClients::setRegistry($this->server->registry($this->store));
        $this->seed();

        $names = array_map(fn($tool): string => $tool->name, McpClients::get('linear')->for(7)->listTools());

        $this->assertNotContains('whoami', $names);
        $this->assertContains('echo', $names);
    }

    public function testConnectUrlPointsToTheConnectRoute(): void
    {
        (new McpPlugin())->routes(Router::createRouteBuilder('/'));

        $this->assertSame(
            'http://localhost/mcp-client/linear/connect?redirect=%2Fsettings',
            McpClients::get('linear')->for(7)->connectUrl('/settings'),
        );
        $this->assertSame('http://localhost/mcp-client/linear/connect', McpClients::get('linear')->for(7)->connectUrl());
    }

    public function testForOnAnAppModeClientFails(): void
    {
        Configure::write('Mcp.clients.app', ['url' => FakeOAuthServer::MCP]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('app mode');

        McpClients::get('app')->for(7);
    }
}
