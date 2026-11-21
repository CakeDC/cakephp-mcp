<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Client\Response as ClientResponse;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\ClientRegistry;
use CakeDC\Mcp\Client\Http\EndpointGuard;
use CakeDC\Mcp\Client\Http\GuardedHttp;
use CakeDC\Mcp\Client\McpClients;
use CakeDC\Mcp\Client\OAuth\Discovery;
use CakeDC\Mcp\Client\OAuth\OAuthException;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use CakeDC\Mcp\Test\Support\FakeOAuthServer;
use CakeDC\Mcp\Test\Support\FixedHttpClientFactory;
use CakeDC\Mcp\Test\Support\InterceptingHttpClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `'auth' => 'client_credentials'`: the application gets its own access token from the authorization server with
 * its client id and secret (OAuth 2.1 client credentials), and keeps it until it expires.
 */
class ClientCredentialsTest extends TestCase
{
    private FakeOAuthServer $oauth;

    protected function setUp(): void
    {
        parent::setUp();
        ConnectionManager::get('test')->execute('DELETE FROM mcp_client_connections');
        DateTime::setTestNow(new DateTime('2026-11-21 10:00:00'));
        $this->oauth = new FakeOAuthServer();
        // The configured issuer's metadata is read like the configured url.
        $this->oauth->mcp->documents[FakeOAuthServer::AS_METADATA] = [
            'issuer' => FakeOAuthServer::ISSUER,
            'token_endpoint' => FakeOAuthServer::ISSUER . '/token',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ];
        McpClients::setRegistry($this->oauth->registry());
        Configure::write('Mcp.clients.robot', [
            'url' => FakeOAuthServer::MCP,
            'auth' => 'client_credentials',
            'clientId' => 'robot-id',
            'clientSecret' => 'robot-secret',
            'issuer' => FakeOAuthServer::ISSUER,
            'scopes' => ['read'],
        ]);
    }

    protected function tearDown(): void
    {
        McpClients::reset();
        DateTime::setTestNow(null);
        parent::tearDown();
    }

    public function testTheApplicationCallsWithItsOwnToken(): void
    {
        $tools = McpClients::get('robot')->listTools();

        $this->assertNotSame([], $tools);
        $this->assertCount(1, $this->oauth->tokenRequests);
        $form = $this->oauth->tokenRequests[0];
        $this->assertSame('client_credentials', $form['grant_type']);
        $this->assertSame('read', $form['scope']);
        $this->assertSame(FakeOAuthServer::MCP, $form['resource']);
        $this->assertSame('Basic ' . base64_encode('robot-id:robot-secret'), $form['_authorization']);
        $this->assertSame('Bearer at-1', end($this->oauth->mcp->requests)['headers']['authorization'] ?? null);
    }

    public function testTheStoredRowIsPlainText(): void
    {
        McpClients::get('robot')->listTools();

        // PostgreSQL refuses a NUL byte in a text column.
        $row = ConnectionManager::get('test')->execute('SELECT user_id FROM mcp_client_connections')->fetch('assoc');
        $this->assertIsArray($row);
        $this->assertStringNotContainsString("\0", (string)$row['user_id']);
        $this->assertTrue(mb_check_encoding((string)$row['user_id'], 'UTF-8'));
    }

    public function testTheTokenIsKeptUntilItExpires(): void
    {
        McpClients::get('robot')->listTools();
        // Another request of the application.
        McpClients::setRegistry($this->oauth->registry());
        McpClients::get('robot')->listTools();
        $this->assertCount(1, $this->oauth->tokenRequests);

        DateTime::setTestNow(new DateTime('2026-11-21 11:00:00'));
        McpClients::setRegistry($this->oauth->registry());
        McpClients::get('robot')->listTools();
        $this->assertCount(2, $this->oauth->tokenRequests);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function changedSettings(): array
    {
        return [
            'the scopes' => ['scopes', ['read', 'write']],
            'the client id' => ['clientId', 'robot-2'],
            'the issuer' => ['issuer', FakeOAuthServer::ISSUER . '/other'],
        ];
    }

    #[DataProvider('changedSettings')]
    public function testAStoredTokenIsNotReusedOnceTheClientChanges(string $key, mixed $value): void
    {
        McpClients::get('robot')->listTools();
        $other = FakeOAuthServer::ISSUER . '/other';
        $this->oauth->mcp->documents[Discovery::metadataUrls($other)[0]] = [
            'issuer' => $other,
            'token_endpoint' => FakeOAuthServer::ISSUER . '/token',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ];
        Configure::write('Mcp.clients.robot.' . $key, $value);
        McpClients::setRegistry($this->oauth->registry());

        McpClients::get('robot')->listTools();

        $this->assertCount(2, $this->oauth->tokenRequests);
        $this->assertSame('Bearer at-2', end($this->oauth->mcp->requests)['headers']['authorization'] ?? null);
    }

    public function testAStoredTokenIsNotReusedOnceTheTokenUrlChanges(): void
    {
        Configure::write('Mcp.clients.robot', [
            'url' => FakeOAuthServer::MCP,
            'auth' => 'client_credentials',
            'clientId' => 'robot-id',
            'clientSecret' => 'robot-secret',
            'tokenUrl' => 'https://sso.example.com/token',
        ]);
        $sent = [];
        $mcp = $this->oauth->mcp;
        $client = new InterceptingHttpClient($mcp, function ($request, array $rpc) use (&$sent, $mcp): ?ClientResponse {
            $url = (string)$request->getUri();
            if (!str_starts_with($url, 'https://sso.example.com/')) {
                return null;
            }
            $sent[] = $url;
            $mcp->tokens[] = 'machine-token-' . count($sent);

            return new ClientResponse(
                ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
                (string)json_encode([
                    'access_token' => 'machine-token-' . count($sent),
                    'token_type' => 'Bearer',
                    'expires_in' => 600,
                ]),
            );
        });
        $registry = fn(): ClientRegistry => new ClientRegistry(
            new FixedHttpClientFactory($client),
            new TableConnectionStore(),
            new GuardedHttp(new EndpointGuard($this->oauth->dns), $this->oauth->guarded, 1024 * 1024),
        );
        McpClients::setRegistry($registry());
        McpClients::get('robot')->listTools();

        Configure::write('Mcp.clients.robot.tokenUrl', 'https://sso.example.com/v2/token');
        McpClients::setRegistry($registry());
        McpClients::get('robot')->listTools();

        $this->assertSame(['https://sso.example.com/token', 'https://sso.example.com/v2/token'], $sent);
        $this->assertSame('Bearer machine-token-2', end($mcp->requests)['headers']['authorization'] ?? null);
    }

    public function testATokenTheServerRefusesIsReplacedOnce(): void
    {
        McpClients::get('robot')->listTools();
        $this->oauth->mcp->tokens = [];

        McpClients::setRegistry($this->oauth->registry());
        McpClients::get('robot')->listTools();

        $this->assertCount(2, $this->oauth->tokenRequests);
    }

    public function testAConfiguredTokenUrlSkipsDiscovery(): void
    {
        Configure::write('Mcp.clients.robot', [
            'url' => FakeOAuthServer::MCP,
            'auth' => 'client_credentials',
            'clientId' => 'robot-id',
            'clientSecret' => 'robot-secret',
            'tokenUrl' => 'https://sso.example.com/token',
        ]);
        $sent = [];
        $mcp = $this->oauth->mcp;
        $client = new InterceptingHttpClient($mcp, function ($request, array $rpc) use (&$sent, $mcp): ?ClientResponse {
            if ((string)$request->getUri() !== 'https://sso.example.com/token') {
                return null;
            }
            parse_str((string)$request->getBody(), $form);
            $sent[] = $form;
            $mcp->tokens[] = 'machine-token';

            return new ClientResponse(
                ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
                (string)json_encode(['access_token' => 'machine-token', 'token_type' => 'Bearer', 'expires_in' => 600]),
            );
        });
        McpClients::setRegistry(new ClientRegistry(
            new FixedHttpClientFactory($client),
            new TableConnectionStore(),
            new GuardedHttp(new EndpointGuard($this->oauth->dns), $this->oauth->guarded, 1024 * 1024),
        ));

        McpClients::get('robot')->listTools();

        $this->assertCount(1, $sent);
        $this->assertSame('client_credentials', $sent[0]['grant_type']);
        $this->assertSame('Bearer machine-token', end($mcp->requests)['headers']['authorization'] ?? null);
        $this->assertSame([], $this->oauth->guarded->sent, 'no discovery');
    }

    public function testMetadataThatNamesAnotherIssuerIsRefused(): void
    {
        $this->oauth->mcp->documents[FakeOAuthServer::AS_METADATA]['issuer'] = 'https://evil.example.com';

        $this->expectException(OAuthException::class);
        McpClients::get('robot')->listTools();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badConfigs(): array
    {
        $base = ['url' => FakeOAuthServer::MCP, 'auth' => 'client_credentials', 'clientId' => 'i', 'clientSecret' => 's'];

        return [
            'no secret' => [array_diff_key($base, ['clientSecret' => 1]) + ['issuer' => FakeOAuthServer::ISSUER], 'clientSecret'],
            'no client id' => [array_diff_key($base, ['clientId' => 1, 'clientSecret' => 1]) + ['issuer' => FakeOAuthServer::ISSUER], 'clientId'],
            'neither issuer nor token url' => [$base, 'issuer'],
            'a token too' => [$base + ['issuer' => FakeOAuthServer::ISSUER, 'token' => 't'], 'token'],
            'an authorization url' => [$base + ['tokenUrl' => 'https://sso.example.com/token', 'authorizationUrl' => 'https://sso.example.com/a'], 'authorizationUrl'],
            'stdio' => [['command' => 'php', 'auth' => 'client_credentials', 'clientId' => 'i', 'clientSecret' => 's', 'issuer' => FakeOAuthServer::ISSUER], 'url'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('badConfigs')]
    public function testIncompleteConfigsFail(array $config, string $named): void
    {
        Configure::write('Mcp.clients.robot', $config);

        try {
            ClientConfig::fromConfig('robot');
            $this->fail('Accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($named, $e->getMessage());
        }
    }
}
