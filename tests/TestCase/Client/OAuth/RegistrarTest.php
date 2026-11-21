<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\OAuth;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\Http\BlockedUrlException;
use CakeDC\Mcp\Client\OAuth\AuthServer;
use CakeDC\Mcp\Client\OAuth\OAuthException;
use CakeDC\Mcp\Client\OAuth\Registrar;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use CakeDC\Mcp\McpPlugin;
use CakeDC\Mcp\Test\Support\FakeOAuthServer;
use InvalidArgumentException;

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
        // The connect, callback and client.json routes, as the application has them.
        Router::reload();
        (new McpPlugin())->routes(Router::createRouteBuilder('/'));
    }

    protected function tearDown(): void
    {
        Router::reload();
        parent::tearDown();
    }

    private static function authServer(
        ?string $registrationUrl = FakeOAuthServer::ISSUER . '/register',
        bool $metadataDocuments = false,
    ): AuthServer {
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
            metadataDocuments: $metadataDocuments,
        );
    }

    private function registrar(): Registrar
    {
        $config = ClientConfig::fromConfig('linear');

        return new Registrar($this->server->registry($this->store)->outbound($config), $this->store, $config);
    }

    public function testAutoUsesTheMetadataDocumentWhenTheServerSupportsItAndTheBaseUrlIsPublic(): void
    {
        Router::fullBaseUrl('https://app.example.com');
        try {
            $registration = $this->registrar()->clientFor(self::authServer(metadataDocuments: true), self::CALLBACK);
        } finally {
            Router::fullBaseUrl('http://localhost');
        }

        $this->assertSame('https://app.example.com/mcp-client/linear/client.json', $registration->clientId);
        $this->assertNull($registration->clientSecret);
        $this->assertSame('none', $registration->authMethod);
        $this->assertSame([], $this->server->registrations);
        $this->assertNull($this->store->registration('linear', FakeOAuthServer::ISSUER));
    }

    public function testAutoRegistersWhenTheServerDoesNotSupportIt(): void
    {
        Router::fullBaseUrl('https://app.example.com');
        try {
            $registration = $this->registrar()->clientFor(self::authServer(), self::CALLBACK);
        } finally {
            Router::fullBaseUrl('http://localhost');
        }

        $this->assertSame('dyn-client-1', $registration->clientId);
    }

    public function testAutoRegistersWhenTheBaseUrlIsNotPublic(): void
    {
        $registration = $this->registrar()->clientFor(self::authServer(metadataDocuments: true), self::CALLBACK);

        $this->assertSame('dyn-client-1', $registration->clientId);
    }

    public function testDynamicAlwaysRegisters(): void
    {
        Configure::write('Mcp.clients.linear.registration', 'dynamic');
        Router::fullBaseUrl('https://app.example.com');
        try {
            $registration = $this->registrar()->clientFor(self::authServer(metadataDocuments: true), self::CALLBACK);
        } finally {
            Router::fullBaseUrl('http://localhost');
        }

        $this->assertSame('dyn-client-1', $registration->clientId);
    }

    public function testMetadataFailsWithABaseUrlThatIsNotPublicHttps(): void
    {
        Configure::write('Mcp.clients.linear.registration', 'metadata');

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('App.fullBaseUrl');
        $this->registrar()->clientFor(self::authServer(metadataDocuments: true), self::CALLBACK);
    }

    public function testMetadataFailsWhenTheServerDoesNotSupportIt(): void
    {
        Configure::write('Mcp.clients.linear.registration', 'metadata');
        Router::fullBaseUrl('https://app.example.com');
        try {
            $this->expectException(OAuthException::class);
            $this->expectExceptionMessage('client_id_metadata_document_supported');
            $this->registrar()->clientFor(self::authServer(), self::CALLBACK);
        } finally {
            Router::fullBaseUrl('http://localhost');
        }
    }

    public function testAConfiguredClientIdWinsOverTheMetadataDocument(): void
    {
        Configure::write('Mcp.clients.linear.registration', 'metadata');
        Configure::write('Mcp.clients.linear.clientId', 'given-id');
        Router::fullBaseUrl('https://app.example.com');
        try {
            $registration = $this->registrar()->clientFor(self::authServer(metadataDocuments: true), self::CALLBACK);
        } finally {
            Router::fullBaseUrl('http://localhost');
        }

        $this->assertSame('given-id', $registration->clientId);
    }

    public function testAnUnknownRegistrationFailsAtConfigRead(): void
    {
        Configure::write('Mcp.clients.linear.registration', 'manual');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.clients.linear.registration"');
        ClientConfig::fromConfig('linear');
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

    public function testRegistrationSendsTheApplicationTypeAndTheScopes(): void
    {
        $server = new AuthServer(
            issuer: FakeOAuthServer::ISSUER,
            authorizationUrl: FakeOAuthServer::ISSUER . '/authorize',
            tokenUrl: FakeOAuthServer::ISSUER . '/token',
            registrationUrl: FakeOAuthServer::ISSUER . '/register',
            revocationUrl: null,
            scopes: ['read', 'write'],
            resource: FakeOAuthServer::MCP,
            issParameter: false,
            authMethods: [],
            trusted: false,
        );

        $this->registrar()->clientFor($server, 'https://app.example.com/mcp-client/linear/callback');
        $this->registrar()->clientFor(self::authServer(), self::CALLBACK);

        $this->assertSame('web', $this->server->registrations[0]['application_type'] ?? null);
        $this->assertSame('read write', $this->server->registrations[0]['scope'] ?? null);
        // A callback on this machine is a native application in OpenID Connect terms; no scopes, no scope.
        $this->assertSame('native', $this->server->registrations[1]['application_type'] ?? null);
        $this->assertArrayNotHasKey('scope', $this->server->registrations[1]);
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
