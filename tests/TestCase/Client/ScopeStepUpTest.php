<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Client\Response as ClientResponse;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ClientRegistry;
use CakeDC\Mcp\Client\Http\EndpointGuard;
use CakeDC\Mcp\Client\Http\GuardedHttp;
use CakeDC\Mcp\Client\McpClients;
use CakeDC\Mcp\Client\Store\StoredConnection;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use CakeDC\Mcp\Test\Support\FakeOAuthServer;
use CakeDC\Mcp\Test\Support\FixedHttpClientFactory;
use CakeDC\Mcp\Test\Support\InterceptingHttpClient;
use Mcp\Exception\ExceptionInterface;

/**
 * Scope step-up: a `403 insufficient_scope` in user mode marks the grant, and the next connect asks for the
 * scopes the user already had plus the ones the server asked for.
 */
class ScopeStepUpTest extends TestCase
{
    private FakeOAuthServer $oauth;

    private TableConnectionStore $store;

    private bool $discoverForbidden = false;

    protected function setUp(): void
    {
        parent::setUp();
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM mcp_client_connections');
        $db->execute('DELETE FROM mcp_client_registrations');
        DateTime::setTestNow(new DateTime('2026-11-21 10:00:00'));
        Configure::write('Mcp.clients.linear', ['url' => FakeOAuthServer::MCP, 'auth' => 'user']);
        $this->oauth = new FakeOAuthServer();
        $this->store = new TableConnectionStore();
        $mcp = $this->oauth->mcp;
        $client = new InterceptingHttpClient($mcp, function ($request, array $rpc): ?ClientResponse {
            if ($this->discoverForbidden && ($rpc['method'] ?? null) === 'initialize') {
                return new ClientResponse(['HTTP/1.1 400 Bad Request', 'Content-Type: application/json'], '{}');
            }
            $forbidden = ($rpc['method'] ?? null) === 'tools/call' && ($rpc['params']['name'] ?? null) === 'delete_all'
                || $this->discoverForbidden && ($rpc['method'] ?? null) === 'server/discover';
            if ($forbidden) {
                return new ClientResponse(
                    [
                        'HTTP/1.1 403 Forbidden',
                        'Content-Type: application/json',
                        'WWW-Authenticate: Bearer error="insufficient_scope", scope="files:write admin", '
                        . 'resource_metadata="' . FakeOAuthServer::RESOURCE_METADATA . '"',
                    ],
                    '',
                );
            }

            return null;
        });
        McpClients::setRegistry(new ClientRegistry(
            new FixedHttpClientFactory($client),
            $this->store,
            new GuardedHttp(new EndpointGuard($this->oauth->dns), $this->oauth->guarded, 1024 * 1024),
        ));
        $mcp->tokens[] = 'at-seed';
        $this->store->save(new StoredConnection(
            'linear',
            '7',
            'at-seed',
            'rt-seed',
            DateTime::now()->getTimestamp() + 3600,
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

    protected function tearDown(): void
    {
        McpClients::reset();
        DateTime::setTestNow(null);
        parent::tearDown();
    }

    public function testAnInsufficientScopeMarksTheGrant(): void
    {
        $user = McpClients::get('linear')->for(7);
        $this->assertFalse($user->needsMoreScope());

        try {
            $user->callTool('delete_all');
            $this->fail('No error');
        } catch (ExceptionInterface) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($user->needsMoreScope());
        $this->assertSame(['read', 'write', 'files:write', 'admin'], $user->stepUpScopes());
        // It is kept for the next requests of the application, and the user stays connected.
        McpClients::setRegistry(McpClients::registry());
        $this->assertTrue(McpClients::get('linear')->for(7)->isConnected());
        $this->assertSame(['read', 'write', 'files:write', 'admin'], $this->store->get('linear', '7')?->stepUpScopes());
    }

    public function testTheNextConnectAsksForTheUnionOfScopes(): void
    {
        try {
            McpClients::get('linear')->for(7)->callTool('delete_all');
        } catch (ExceptionInterface) {
        }

        [, $url] = McpClients::registry()->flow('linear')->start('7', 'http://localhost/mcp-client/linear/callback', '/');

        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $scopes = explode(' ', (string)($query['scope'] ?? ''));
        foreach (['read', 'write', 'files:write', 'admin'] as $scope) {
            $this->assertContains($scope, $scopes);
        }
    }

    public function testAnInsufficientScopeBeforeTheCallIsSeenToo(): void
    {
        // The server asks for more scope on server/discover, and the SDK's fallback to the handshake then fails:
        // the 403 is not the last answer, but it is the one that counts.
        $this->discoverForbidden = true;
        $user = McpClients::get('linear')->for(7);

        try {
            $user->listTools();
            $this->fail('No error');
        } catch (ExceptionInterface) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($user->needsMoreScope());
    }

    public function testOtherUsersAndCallsAreNotMarked(): void
    {
        McpClients::get('linear')->for(7)->callTool('echo', ['text' => 'hi']);

        $this->assertFalse(McpClients::get('linear')->for(7)->needsMoreScope());
        $this->assertSame([], McpClients::get('linear')->for(7)->stepUpScopes());
    }
}
