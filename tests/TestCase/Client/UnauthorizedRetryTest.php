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
use Throwable;

/**
 * A 401 is retried with a new token only when it answered the call before the server accepted any of its
 * requests: a 401 to the GET that resumes the stream of a tools/call the server already ran does not send the
 * call again.
 */
class UnauthorizedRetryTest extends TestCase
{
    private FakeOAuthServer $oauth;

    private int $ran = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM mcp_client_connections');
        $db->execute('DELETE FROM mcp_client_registrations');
        DateTime::setTestNow(new DateTime('2026-11-21 10:00:00'));
        $this->oauth = new FakeOAuthServer();
    }

    protected function tearDown(): void
    {
        McpClients::reset();
        DateTime::setTestNow(null);
        parent::tearDown();
    }

    /**
     * The MCP server runs the tool and answers with a stream it closes before the result; the GET that resumes it
     * gets a 401, as when the token expires or is revoked in between.
     *
     * @param \CakeDC\Mcp\Client\Store\TableConnectionStore $store The store.
     * @return void
     */
    private function serverThatClosesTheStream(TableConnectionStore $store): void
    {
        $mcp = $this->oauth->mcp;
        $http = new InterceptingHttpClient($mcp, function ($request, array $rpc) use ($mcp): ?ClientResponse {
            if (($rpc['method'] ?? null) === 'tools/call') {
                $result = $mcp->sendRequest($request);
                $this->ran++;
                if ($this->ran > 1) {
                    return new ClientResponse(
                        ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
                        (string)$result->getBody(),
                    );
                }

                return new ClientResponse(
                    ['HTTP/1.1 200 OK', 'Content-Type: text/event-stream'],
                    "id: event-1\nretry: 5\ndata: \n\n",
                );
            }
            if ($request->getMethod() === 'GET' && $request->getHeaderLine('Last-Event-ID') !== '') {
                return new ClientResponse(
                    ['HTTP/1.1 401 Unauthorized', 'WWW-Authenticate: Bearer error="invalid_token"'],
                    '',
                );
            }

            return null;
        });
        McpClients::setRegistry(new ClientRegistry(
            new FixedHttpClientFactory($http),
            $store,
            new GuardedHttp(new EndpointGuard($this->oauth->dns), $this->oauth->guarded, 1024 * 1024),
            function (): void {
            },
        ));
    }

    public function testInUserModeACallTheServerRanIsNotSentAgain(): void
    {
        Configure::write('Mcp.clients.linear', [
            'url' => FakeOAuthServer::MCP,
            'auth' => 'user',
            'protocolVersion' => '2025-11-25',
        ]);
        $store = new TableConnectionStore();
        $this->oauth->mcp->tokens[] = 'at-7';
        $store->save(new StoredConnection(
            'linear',
            '7',
            'at-7',
            'rt-7',
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
        $this->serverThatClosesTheStream($store);

        try {
            McpClients::get('linear')->for(['id' => 7])->callTool('echo', ['text' => 'once']);
        } catch (Throwable) {
            // Failing is fine: the caller knows the call may have run.
        }

        $this->assertSame(1, $this->ran);
    }

    public function testWithClientCredentialsACallTheServerRanIsNotSentAgain(): void
    {
        $this->oauth->mcp->documents[FakeOAuthServer::AS_METADATA] = [
            'issuer' => FakeOAuthServer::ISSUER,
            'token_endpoint' => FakeOAuthServer::ISSUER . '/token',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ];
        Configure::write('Mcp.clients.robot', [
            'url' => FakeOAuthServer::MCP,
            'auth' => 'client_credentials',
            'clientId' => 'robot-id',
            'clientSecret' => 'robot-secret',
            'issuer' => FakeOAuthServer::ISSUER,
            'scopes' => ['read'],
            'protocolVersion' => '2025-11-25',
        ]);
        $this->serverThatClosesTheStream(new TableConnectionStore());

        try {
            McpClients::get('robot')->callTool('echo', ['text' => 'once']);
        } catch (Throwable) {
            // Failing is fine.
        }

        $this->assertSame(1, $this->ran);
    }
}
