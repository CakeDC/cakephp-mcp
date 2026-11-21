<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\OAuth;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\Http\BlockedUrlException;
use CakeDC\Mcp\Client\OAuth\AuthServer;
use CakeDC\Mcp\Client\OAuth\Discovery;
use CakeDC\Mcp\Client\OAuth\OAuthException;
use CakeDC\Mcp\Test\Support\FakeOAuthServer;

class DiscoveryTest extends TestCase
{
    private FakeOAuthServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = new FakeOAuthServer();
        Configure::write('Mcp.clients.linear', ['url' => FakeOAuthServer::MCP, 'auth' => 'user']);
    }

    private function discover(string $client = 'linear'): AuthServer
    {
        $config = ClientConfig::fromConfig($client);

        return (new Discovery($this->server->registry()->outbound($config), $config))->discover();
    }

    public function testDiscoversThroughTheChallenge(): void
    {
        $server = $this->discover();

        $this->assertSame(FakeOAuthServer::ISSUER, $server->issuer);
        $this->assertSame(FakeOAuthServer::ISSUER . '/authorize', $server->authorizationUrl);
        $this->assertSame(FakeOAuthServer::ISSUER . '/token', $server->tokenUrl);
        $this->assertSame(FakeOAuthServer::ISSUER . '/register', $server->registrationUrl);
        $this->assertSame(FakeOAuthServer::ISSUER . '/revoke', $server->revocationUrl);
        $this->assertSame(FakeOAuthServer::MCP, $server->resource);
        $this->assertSame(['read', 'write'], $server->scopes);
        $this->assertTrue($server->issParameter);
        $this->assertFalse($server->trusted);
        $this->assertSame(['none', 'client_secret_basic'], $server->authMethods);
        // The probe went to the configured server; the metadata through the guard.
        $this->assertSame('POST', $this->server->mcp->requests[0]['method']);
        $this->assertSame(
            ['GET ' . FakeOAuthServer::RESOURCE_METADATA, 'GET ' . FakeOAuthServer::AS_METADATA],
            $this->server->guarded->urls(),
        );
    }

    public function testFallsBackToWellKnownUrls(): void
    {
        $this->server->mcp->challenge = 'Bearer';
        $this->server->mcp->documents[FakeOAuthServer::RESOURCE_METADATA] = [
            'resource' => 'https://mcp.example.com',
            'authorization_servers' => [FakeOAuthServer::ISSUER],
            'scopes_supported' => ['read'],
        ];

        $server = $this->discover();

        $this->assertSame('https://mcp.example.com', $server->resource);
        $this->assertSame(['read'], $server->scopes);
        // The well-known URL of the configured server is trusted: it never went through the guard.
        $this->assertSame(['GET ' . FakeOAuthServer::AS_METADATA], $this->server->guarded->urls());
    }

    public function testConfiguredScopesWin(): void
    {
        Configure::write('Mcp.clients.linear.scopes', ['issues:read']);

        $this->assertSame(['issues:read'], $this->discover()->scopes);
    }

    public function testRefusesAnIssuerMismatch(): void
    {
        $this->server->authorizationServer(['issuer' => 'https://evil.example.com']);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('names a different issuer');

        $this->discover();
    }

    public function testAConfiguredIssuerMustBeTheOneTheServerNames(): void
    {
        Configure::write('Mcp.clients.linear', [
            'url' => FakeOAuthServer::MCP,
            'auth' => 'user',
            'clientId' => 'given-id',
            'clientSecret' => 'given-secret',
            'issuer' => 'https://login.linear.example',
        ]);

        try {
            $this->discover();
            $this->fail('Discovered an authorization server that is not the configured issuer.');
        } catch (OAuthException $e) {
            $this->assertStringContainsString('https://login.linear.example', $e->getMessage());
            $this->assertStringContainsString(FakeOAuthServer::ISSUER, $e->getMessage());
        }
        // Nothing was asked of the authorization server the MCP server named.
        $this->assertSame(['GET ' . FakeOAuthServer::RESOURCE_METADATA], $this->server->guarded->urls());
    }

    public function testAMatchingConfiguredIssuerIsDiscovered(): void
    {
        Configure::write('Mcp.clients.linear', [
            'url' => FakeOAuthServer::MCP,
            'auth' => 'user',
            'clientId' => 'given-id',
            'clientSecret' => 'given-secret',
            'issuer' => FakeOAuthServer::ISSUER,
        ]);

        $server = $this->discover();

        $this->assertSame(FakeOAuthServer::ISSUER, $server->issuer);
        $this->assertSame(FakeOAuthServer::ISSUER . '/token', $server->tokenUrl);
    }

    public function testRefusesAnotherServersResource(): void
    {
        $this->server->guarded->json(FakeOAuthServer::RESOURCE_METADATA, [
            'resource' => 'https://other.example.com/mcp',
            'authorization_servers' => [FakeOAuthServer::ISSUER],
        ]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('belong to another server');

        $this->discover();
    }

    public function testAPrefixResourceOnlyFromAWellKnownUrl(): void
    {
        $this->server->guarded->json(FakeOAuthServer::RESOURCE_METADATA, [
            'resource' => 'https://mcp.example.com',
            'authorization_servers' => [FakeOAuthServer::ISSUER],
        ]);

        $this->expectException(OAuthException::class);

        $this->discover();
    }

    public function testRequiresS256(): void
    {
        $this->server->authorizationServer(['code_challenge_methods_supported' => ['plain']]);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('S256');

        $this->discover();
    }

    public function testAServerThatAsksForNoSignInIsReported(): void
    {
        $this->server->mcp->tokens = null;

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('does not ask for a sign-in');

        $this->discover();
    }

    public function testChallengeMetadataOnAPrivateHostIsRefused(): void
    {
        $this->server->mcp->challenge = 'Bearer resource_metadata="https://internal.example.com/meta"';

        try {
            $this->discover();
            $this->fail('Not refused');
        } catch (BlockedUrlException) {
            $this->assertSame([], $this->server->guarded->sent);
        }
    }

    public function testAnIssuerOnAPrivateHostIsRefused(): void
    {
        $this->server->guarded->json(FakeOAuthServer::RESOURCE_METADATA, [
            'resource' => FakeOAuthServer::MCP,
            'authorization_servers' => ['https://internal.example.com'],
        ]);

        $this->expectException(BlockedUrlException::class);

        $this->discover();
    }

    public function testTokenEndpointOnAPrivateHostIsRefused(): void
    {
        $this->server->authorizationServer(['token_endpoint' => 'https://internal.example.com/token']);

        $this->expectException(BlockedUrlException::class);

        $this->discover();
    }

    public function testAnIpLiteralEndpointIsRefused(): void
    {
        $this->server->authorizationServer(['registration_endpoint' => 'https://10.0.0.1/register']);

        $this->expectException(BlockedUrlException::class);

        $this->discover();
    }

    public function testConfiguredEndpointsSkipDiscovery(): void
    {
        Configure::write('Mcp.clients.internal', [
            'url' => 'https://mcp.internal/mcp',
            'auth' => 'user',
            'authorizationUrl' => 'https://sso.internal/authorize',
            'tokenUrl' => 'https://sso.internal/token',
            'revocationUrl' => 'https://sso.internal/revoke',
            'clientId' => 'cake',
            'scopes' => ['mcp'],
        ]);

        $server = $this->discover('internal');

        $this->assertTrue($server->trusted);
        $this->assertSame('https://sso.internal/token', $server->tokenUrl);
        $this->assertSame('https://sso.internal', $server->issuer);
        $this->assertSame('https://mcp.internal/mcp', $server->resource);
        $this->assertSame(['mcp'], $server->scopes);
        $this->assertSame([], $this->server->mcp->requests);
        $this->assertSame([], $this->server->guarded->sent);
    }
}
