<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\OAuth;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\Http\BlockedUrlException;
use CakeDC\Mcp\Client\OAuth\AuthServer;
use CakeDC\Mcp\Client\OAuth\OAuthException;
use CakeDC\Mcp\Client\OAuth\Registrar;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use CakeDC\Mcp\Test\Support\FakeOAuthServer;

class RegistrarTest extends TestCase
{
    private const CALLBACK = 'http://localhost/mcp-client/linear/callback';

    private FakeOAuthServer $server;

    private TableConnectionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        ConnectionManager::get('test')->execute('DELETE FROM mcp_client_registrations');
        $this->server = new FakeOAuthServer();
        $this->store = new TableConnectionStore();
        Configure::write('Mcp.clients.linear', ['url' => FakeOAuthServer::MCP, 'auth' => 'user']);
    }

    private static function authServer(?string $registrationUrl = FakeOAuthServer::ISSUER . '/register'): AuthServer
    {
        return new AuthServer(
            issuer: FakeOAuthServer::ISSUER,
            authorizationUrl: FakeOAuthServer::ISSUER . '/authorize',
            tokenUrl: FakeOAuthServer::ISSUER . '/token',
            registrationUrl: $registrationUrl,
            revocationUrl: null,
            scopes: [],
            resource: FakeOAuthServer::MCP,
            issParameter: false,
            authMethods: ['client_secret_post'],
            trusted: false,
        );
    }

    private function registrar(): Registrar
    {
        $config = ClientConfig::fromConfig('linear');

        return new Registrar($this->server->registry($this->store)->outbound($config), $this->store, $config);
    }

    public function testAConfiguredClientIdIsUsedAsIs(): void
    {
        Configure::write('Mcp.clients.linear.clientId', 'given-id');
        Configure::write('Mcp.clients.linear.clientSecret', 'given-secret');
        Configure::write('Mcp.clients.linear.issuer', FakeOAuthServer::ISSUER);

        $registration = $this->registrar()->clientFor(self::authServer(), self::CALLBACK);

        $this->assertSame('given-id', $registration->clientId);
        $this->assertSame('given-secret', $registration->clientSecret);
        $this->assertSame('client_secret_post', $registration->authMethod, 'the only secret method the server lists');
        $this->assertSame([], $this->server->registrations);
        $this->assertNull($this->store->registration('linear', FakeOAuthServer::ISSUER));
    }

    public function testRegistersOnceThenReuses(): void
    {
        $first = $this->registrar()->clientFor(self::authServer(), self::CALLBACK);
        $second = $this->registrar()->clientFor(self::authServer(), self::CALLBACK);

        $this->assertSame('dyn-client-1', $first->clientId);
        $this->assertSame('none', $first->authMethod);
        $this->assertEquals($first, $second);
        $this->assertCount(1, $this->server->registrations);
        $sent = $this->server->registrations[0];
        $this->assertSame([self::CALLBACK], $sent['redirect_uris']);
        $this->assertSame(['authorization_code', 'refresh_token'], $sent['grant_types']);
        $this->assertSame('none', $sent['token_endpoint_auth_method']);
        $this->assertSame('CakePHP MCP Client', $sent['client_name']);
    }

    public function testANewCallbackRegistersAgain(): void
    {
        $this->registrar()->clientFor(self::authServer(), self::CALLBACK);
        $other = $this->registrar()->clientFor(self::authServer(), 'https://app.example.com/mcp-client/linear/callback');

        $this->assertSame('dyn-client-2', $other->clientId);
    }

    public function testARegistrationEndpointOnAPrivateHostIsRefused(): void
    {
        $this->expectException(BlockedUrlException::class);

        $this->registrar()->clientFor(self::authServer('https://internal.example.com/register'), self::CALLBACK);
    }

    public function testNoRegistrationEndpointAndNoClientIdFailsClearly(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('"Mcp.clients.linear.clientId"');

        $this->registrar()->clientFor(self::authServer(null), self::CALLBACK);
    }
}
