<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\McpClients;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\Test\Support\FakeOAuthServer;

/**
 * The connect and callback routes of a user mode client, in the test app.
 */
class McpClientControllerTest extends TestCase
{
    use IntegrationTestTrait;

    private FakeOAuthServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM mcp_client_connections');
        $db->execute('DELETE FROM mcp_client_registrations');
        Configure::write('Mcp.clients.linear', ['url' => FakeOAuthServer::MCP, 'auth' => 'user']);
        $this->server = new FakeOAuthServer();
        McpClients::setRegistry($this->server->registry());
    }

    protected function tearDown(): void
    {
        McpClients::reset();
        parent::tearDown();
    }

    /**
     * Starts a flow as user $userId.
     *
     * @return array<string, string> The authorization request's query.
     */
    private function connect(int $userId = 1, string $redirect = '/settings'): array
    {
        $this->session(['Auth' => ['id' => $userId]]);
        $this->get('/mcp-client/linear/connect?redirect=' . urlencode($redirect));
        $this->assertResponseCode(302);
        $location = (string)$this->_response?->getHeaderLine('Location');
        $this->assertStringStartsWith(FakeOAuthServer::ISSUER . '/authorize?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);

        return array_map('strval', $query);
    }

    /**
     * @param array<string, string> $query The callback's query.
     * @param int $userId The logged in user.
     * @return void
     */
    private function returnFromAuthorization(array $query, int $userId = 1): void
    {
        // The CLI session lives in $_SESSION; reading it through a Session object would start a new one and empty it.
        $session = $_SESSION ?? [];
        $session['Auth'] = ['id' => $userId];
        $this->session($session);
        $this->get('/mcp-client/linear/callback?' . http_build_query($query));
    }

    public function testConnectRedirectsToTheAuthorizationServer(): void
    {
        $query = $this->connect();

        $this->assertSame('http://localhost/mcp-client/linear/callback', $query['redirect_uri']);
        $this->assertSame('dyn-client-1', $query['client_id']);
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function testConnectNeedsALoggedInUser(): void
    {
        $this->get('/mcp-client/linear/connect');

        $this->assertResponseCode(401);
        $this->assertSame([], $this->server->mcp->requests);
    }

    public function testConnectForAnUnknownOrAppModeClientIsNotFound(): void
    {
        Configure::write('Mcp.clients.app', ['url' => FakeOAuthServer::MCP]);
        $this->session(['Auth' => ['id' => 1]]);

        $this->get('/mcp-client/app/connect');
        $this->assertResponseCode(404);

        $this->get('/mcp-client/nope/connect');
        $this->assertResponseCode(404);
    }

    public function testCallbackStoresTheConnectionAndRedirects(): void
    {
        $query = $this->connect();

        $this->returnFromAuthorization(['code' => 'good-code', 'state' => $query['state'], 'iss' => FakeOAuthServer::ISSUER]);

        $this->assertRedirect('/settings');
        $this->assertFlashMessage('Connected to linear.');
        $stored = (new TableConnectionStore())->get('linear', '1');
        $this->assertNotNull($stored);
        $this->assertSame('at-1', $stored->accessToken);
    }

    public function testCallbackWithAWrongStateStoresNothing(): void
    {
        $this->connect();

        $this->returnFromAuthorization(['code' => 'good-code', 'state' => 'forged', 'iss' => FakeOAuthServer::ISSUER]);

        $this->assertRedirect('/settings');
        $this->assertFlashMessage('The connection to linear could not be completed. Try again.');
        $this->assertSame([], $this->server->tokenRequests);
        $this->assertNull((new TableConnectionStore())->get('linear', '1'));
    }

    public function testCallbackWithoutAFlowStoresNothing(): void
    {
        $this->session(['Auth' => ['id' => 1]]);
        $this->get('/mcp-client/linear/callback?code=good-code&state=abc');

        $this->assertRedirect('/');
        $this->assertSame([], $this->server->tokenRequests);
    }

    public function testCallbackByAnotherUserStoresNothing(): void
    {
        $query = $this->connect(1);

        $this->returnFromAuthorization(['code' => 'good-code', 'state' => $query['state'], 'iss' => FakeOAuthServer::ISSUER], 2);

        $this->assertSame([], $this->server->tokenRequests);
        $this->assertNull((new TableConnectionStore())->get('linear', '1'));
        $this->assertNull((new TableConnectionStore())->get('linear', '2'));
    }

    public function testStateCannotBeReplayed(): void
    {
        $query = $this->connect();
        $this->returnFromAuthorization(['code' => 'good-code', 'state' => $query['state'], 'iss' => FakeOAuthServer::ISSUER]);
        $this->assertCount(1, $this->server->tokenRequests);

        $this->returnFromAuthorization(['code' => 'good-code', 'state' => $query['state'], 'iss' => FakeOAuthServer::ISSUER]);

        $this->assertCount(1, $this->server->tokenRequests);
    }

    public function testOnlyLocalRedirects(): void
    {
        foreach (['https://evil.example.com/x', '//evil.example.com/x', '/\\evil.example.com', 'javascript:alert(1)'] as $redirect) {
            $query = $this->connect(1, $redirect);
            $this->returnFromAuthorization(['code' => 'good-code', 'state' => $query['state'], 'iss' => FakeOAuthServer::ISSUER]);
            $this->assertRedirect('/', $redirect);
        }
    }

    public function testErrorFromTheAuthorizationServer(): void
    {
        $query = $this->connect();

        $this->returnFromAuthorization(['error' => 'access_denied', 'state' => $query['state'], 'iss' => FakeOAuthServer::ISSUER]);

        $this->assertRedirect('/settings');
        $this->assertFlashMessage('The connection to linear could not be completed: access_denied.');
        $this->assertNull((new TableConnectionStore())->get('linear', '1'));
    }

    public function testADiscoveryFailureIsShownNotThrown(): void
    {
        $this->server->authorizationServer(['token_endpoint' => 'https://internal.example.com/token']);
        $this->session(['Auth' => ['id' => 1]]);

        $this->get('/mcp-client/linear/connect?redirect=/settings');

        $this->assertRedirect('/settings');
        $this->assertFlashMessage('The connection to linear could not be started. Try again later.');
    }

    public function testRoutesOnlyWithAUserModeClient(): void
    {
        $this->connect();
        $this->assertSame(
            '/mcp-client/linear/callback',
            Router::url(['plugin' => 'CakeDC/Mcp', 'controller' => 'McpClient', 'action' => 'callback', 'linear']),
        );
        $this->assertContains('McpClient', array_merge(...array_column(McpConfig::rbacPermissions(), 'controller')));

        Configure::write('Mcp.clients', ['app' => ['url' => FakeOAuthServer::MCP]]);
        Router::reload();
        $this->session(['Auth' => ['id' => 1]]);
        $this->get('/mcp-client/linear/connect');

        $this->assertResponseCode(404);
        $this->assertSame([['role' => '*', 'plugin' => 'CakeDC/Mcp', 'controller' => ['Mcp', 'ProtectedResource'],
            'action' => ['handle', 'metadata'], 'bypassAuth' => true]], McpConfig::rbacPermissions());
    }
}
