<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\OAuth;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\OAuth\OAuthException;
use CakeDC\Mcp\Client\OAuth\OAuthFlow;
use CakeDC\Mcp\Client\OAuth\PendingAuthorization;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use CakeDC\Mcp\Test\Support\FakeOAuthServer;
use Throwable;

class OAuthFlowTest extends TestCase
{
    private const CALLBACK = 'http://localhost/mcp-client/linear/callback';

    private FakeOAuthServer $server;

    private TableConnectionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM mcp_client_connections');
        $db->execute('DELETE FROM mcp_client_registrations');
        $this->server = new FakeOAuthServer();
        $this->store = new TableConnectionStore();
        Configure::write('Mcp.clients.linear', ['url' => FakeOAuthServer::MCP, 'auth' => 'user']);
    }

    protected function tearDown(): void
    {
        DateTime::setTestNow(null);
        parent::tearDown();
    }

    private function flow(): OAuthFlow
    {
        return $this->server->registry($this->store)->flow('linear');
    }

    /**
     * @return array{0: \CakeDC\Mcp\Client\OAuth\PendingAuthorization, 1: array<string, string>}
     */
    private function start(): array
    {
        [$pending, $url] = $this->flow()->start('7', self::CALLBACK, '/settings');
        $this->assertStringStartsWith(FakeOAuthServer::ISSUER . '/authorize?', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        return [$pending, array_map('strval', $query)];
    }

    public function testTheAuthorizationUrlCarriesPkceStateAndResource(): void
    {
        [$pending, $query] = $this->start();

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('dyn-client-1', $query['client_id']);
        $this->assertSame(self::CALLBACK, $query['redirect_uri']);
        $this->assertSame('read write', $query['scope']);
        $this->assertSame(FakeOAuthServer::MCP, $query['resource']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($pending->state, $query['state']);
        $this->assertGreaterThanOrEqual(43, strlen($pending->verifier));
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pending->verifier, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $query['code_challenge']);
        $this->assertGreaterThanOrEqual(32, strlen($pending->state));
        $this->assertSame('7', $pending->userId);
        $this->assertSame('/settings', $pending->returnTo);
    }

    public function testPendingSurvivesTheSession(): void
    {
        [$pending] = $this->start();

        $this->assertEquals($pending, PendingAuthorization::fromArray($pending->toArray()));
        $this->assertNull(PendingAuthorization::fromArray(['state' => 'x']));
        $this->assertStringNotContainsString('secret', (string)json_encode($pending->toArray()));
    }

    public function testCompleteExchangesTheCodeAndStoresTheTokens(): void
    {
        DateTime::setTestNow(new DateTime('2026-11-20 10:00:00'));
        [$pending, $query] = $this->start();

        $connection = $this->flow()->complete($pending, [
            'code' => 'good-code',
            'state' => $query['state'],
            'iss' => FakeOAuthServer::ISSUER,
        ]);

        $sent = $this->server->tokenRequests[0];
        $this->assertSame('authorization_code', $sent['grant_type']);
        $this->assertSame('good-code', $sent['code']);
        $this->assertSame($pending->verifier, $sent['code_verifier']);
        $this->assertSame(self::CALLBACK, $sent['redirect_uri']);
        $this->assertSame('dyn-client-1', $sent['client_id']);
        $this->assertSame(FakeOAuthServer::MCP, $sent['resource']);
        $this->assertSame('', $sent['_authorization']);

        $this->assertSame('at-1', $connection->accessToken);
        $this->assertSame('rt-1', $connection->refreshToken);
        $this->assertSame((new DateTime('2026-11-20 11:00:00'))->getTimestamp(), $connection->expiresAt);
        $this->assertEquals($connection, $this->store->get('linear', '7'));
        $this->assertSame(FakeOAuthServer::ISSUER . '/revoke', $connection->revocationUrl);
    }

    public function testAnIssMismatchIsRefused(): void
    {
        [$pending, $query] = $this->start();

        try {
            $this->flow()->complete($pending, ['code' => 'good-code', 'state' => $query['state'], 'iss' => 'https://evil.example.com']);
            $this->fail('Not refused');
        } catch (OAuthException) {
            $this->assertSame([], $this->server->tokenRequests);
            $this->assertNull($this->store->get('linear', '7'));
        }
    }

    public function testAMissingIssIsRefusedWhenTheServerAdvertisesIt(): void
    {
        [$pending, $query] = $this->start();

        $this->expectException(OAuthException::class);

        $this->flow()->complete($pending, ['code' => 'good-code', 'state' => $query['state']]);
    }

    public function testConfiguredEndpointsAcceptTheIssOfAServerWithAPath(): void
    {
        $this->configureEndpoints();
        [$pending, $query] = $this->start();

        try {
            $this->flow()->complete($pending, [
                'code' => 'good-code',
                'state' => $query['state'],
                'iss' => FakeOAuthServer::ISSUER . '/realms/corp',
            ]);
        } catch (Throwable $e) {
            // The test's trusted double does not serve the token endpoint; only the iss check matters here.
            $this->assertStringNotContainsString('another authorization server', $e->getMessage());
        }
    }

    public function testAConfiguredIssuerIsCompared(): void
    {
        $this->configureEndpoints();
        Configure::write('Mcp.clients.linear.issuer', FakeOAuthServer::ISSUER . '/realms/corp');
        [$pending, $query] = $this->start();

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('another authorization server');

        $this->flow()->complete($pending, ['code' => 'good-code', 'state' => $query['state'], 'iss' => 'https://evil.example.com']);
    }

    private function configureEndpoints(): void
    {
        Configure::write('Mcp.clients.linear.authorizationUrl', FakeOAuthServer::ISSUER . '/authorize');
        Configure::write('Mcp.clients.linear.tokenUrl', FakeOAuthServer::ISSUER . '/token');
        Configure::write('Mcp.clients.linear.clientId', 'given id');
    }

    public function testAWrongStateIsRefused(): void
    {
        [$pending] = $this->start();

        $this->expectException(OAuthException::class);

        $this->flow()->complete($pending, ['code' => 'good-code', 'state' => 'forged', 'iss' => FakeOAuthServer::ISSUER]);
    }

    public function testAnExpiredFlowIsRefused(): void
    {
        DateTime::setTestNow(new DateTime('2026-11-20 10:00:00'));
        [$pending, $query] = $this->start();
        DateTime::setTestNow(new DateTime('2026-11-20 10:11:00'));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('took too long');

        $this->flow()->complete($pending, ['code' => 'good-code', 'state' => $query['state'], 'iss' => FakeOAuthServer::ISSUER]);
    }

    public function testARejectedCodeStoresNothingAndQuotesNothing(): void
    {
        [$pending, $query] = $this->start();

        try {
            $this->flow()->complete($pending, ['code' => 'bad', 'state' => $query['state'], 'iss' => FakeOAuthServer::ISSUER]);
            $this->fail('Not refused');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_grant', $e->error);
            $this->assertStringNotContainsString('<script>', $e->getMessage());
            $this->assertNull($this->store->get('linear', '7'));
        }
    }

    public function testTheUserDeniedAccess(): void
    {
        [$pending, $query] = $this->start();

        try {
            $this->flow()->complete($pending, ['error' => 'access_denied', 'state' => $query['state']]);
            $this->fail('Not refused');
        } catch (OAuthException $e) {
            $this->assertSame('access_denied', $e->error);
            $this->assertSame([], $this->server->tokenRequests);
        }
    }

    public function testAConfiguredClientWithASecretUsesBasicAuth(): void
    {
        Configure::write('Mcp.clients.linear.clientId', 'given id');
        Configure::write('Mcp.clients.linear.clientSecret', 'given:secret');
        [$pending, $query] = $this->start();

        $this->flow()->complete($pending, ['code' => 'good-code', 'state' => $query['state'], 'iss' => FakeOAuthServer::ISSUER]);

        $sent = $this->server->tokenRequests[0];
        $this->assertSame('Basic ' . base64_encode('given+id:given%3Asecret'), $sent['_authorization']);
        $this->assertArrayNotHasKey('client_secret', $sent);
        $this->assertStringNotContainsString('given:secret', (string)json_encode($pending->toArray()));
    }
}
