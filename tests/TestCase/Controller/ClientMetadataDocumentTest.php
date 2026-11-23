<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\McpClients;
use CakeDC\Mcp\Client\OAuth\ClientMetadataDocument;
use CakeDC\Mcp\Test\Support\FakeOAuthServer;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The OAuth Client ID Metadata Document of each user mode client, at <route>/<name>/client.json, whose URL is the
 * client_id when the authorization server supports such documents.
 */
class ClientMetadataDocumentTest extends TestCase
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
        Router::fullBaseUrl('https://app.example.com');
        $this->server = new FakeOAuthServer();
        McpClients::setRegistry($this->server->registry());
    }

    protected function tearDown(): void
    {
        McpClients::reset();
        Router::fullBaseUrl('http://localhost');
        parent::tearDown();
    }

    public function testTheDocumentDescribesTheClient(): void
    {
        $this->get('/mcp-client/linear/client.json');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        // The authorization server that fetches it may cache it; a shared cache may not, since the URLs in it can
        // come from the request's Host when App.fullBaseUrl is not set.
        $this->assertSame('private, max-age=3600', $this->_response?->getHeaderLine('Cache-Control'));
        $document = json_decode((string)$this->_response?->getBody(), true);
        $this->assertSame([
            'client_id' => 'https://app.example.com/mcp-client/linear/client.json',
            'client_name' => 'CakePHP MCP Client',
            'redirect_uris' => ['https://app.example.com/mcp-client/linear/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'token_endpoint_auth_methods_supported' => ['none'],
            'application_type' => 'web',
        ], $document);
    }

    public function testTheDocumentNeverHoldsASecret(): void
    {
        Configure::write('Mcp.clients.linear', [
            'url' => FakeOAuthServer::MCP,
            'auth' => 'user',
            'clientId' => 'given-id',
            'clientSecret' => 'very-secret-value',
            'issuer' => FakeOAuthServer::ISSUER,
        ]);

        $this->get('/mcp-client/linear/client.json');

        $this->assertResponseOk();
        $this->assertStringNotContainsString('very-secret-value', (string)$this->_response?->getBody());
        $this->assertStringNotContainsString('secret', (string)$this->_response?->getBody());
    }

    public function testOnlyUserModeClientsHaveOne(): void
    {
        Configure::write('Mcp.clients.github', ['url' => 'https://api.example.com/mcp', 'token' => 't']);

        $this->get('/mcp-client/github/client.json');
        $this->assertResponseCode(404);
        $this->get('/mcp-client/nobody/client.json');
        $this->assertResponseCode(404);
    }

    public function testConnectSendsTheDocumentUrlAsClientIdWhenTheServerSupportsIt(): void
    {
        $this->server->authorizationServer(['client_id_metadata_document_supported' => true]);

        $this->session(['Auth' => ['id' => 1]]);
        $this->get('/mcp-client/linear/connect');

        $this->assertResponseCode(302);
        parse_str((string)parse_url((string)$this->_response?->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('https://app.example.com/mcp-client/linear/client.json', $query['client_id'] ?? null);
        $this->assertSame('https://app.example.com/mcp-client/linear/callback', $query['redirect_uri'] ?? null);
        $this->assertSame([], $this->server->registrations, 'no dynamic registration');
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function baseUrls(): array
    {
        return [
            'public https' => ['https://app.example.com', true],
            'with a port and path' => ['https://app.example.com:8443/shop', true],
            'plain http' => ['http://app.example.com', false],
            'localhost' => ['https://localhost', false],
            'a localhost name' => ['https://app.localhost', false],
            'an internal name' => ['https://app.corp.internal', false],
            'a private address' => ['https://10.0.0.4', false],
            'a single label' => ['https://intranet', false],
        ];
    }

    #[DataProvider('baseUrls')]
    public function testTheDocumentIsOnlyUsedWithAPublicHttpsBaseUrl(string $base, bool $public): void
    {
        // Loads the routes, as a request of the application does.
        $this->get('/mcp-client/linear/client.json');
        Router::fullBaseUrl($base);

        $this->assertSame($public, ClientMetadataDocument::servable(ClientConfig::fromConfig('linear')));
    }
}
