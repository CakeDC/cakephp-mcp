<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\OAuth;

use Cake\Core\Configure;
use Cake\Http\ResponseFactory;
use Cake\Http\StreamFactory;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\OAuth\AuthSettings;
use CakeDC\Mcp\OAuth\OAuthServerTokenValidator;
use CakeDC\Mcp\OAuth\ResourceServer;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use LogicException;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use ReflectionProperty;
use RuntimeException;
use TestApp\OAuthTestConfig;

class ResourceServerTest extends TestCase
{
    protected array $fixtures = OAuthFixtures::ALL;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        OAuthServer::reset();
    }

    protected function tearDown(): void
    {
        OAuthServer::reset();
        parent::tearDown();
    }

    private function server(): ResourceServer
    {
        return new ResourceServer(AuthSettings::fromConfig());
    }

    public function testMetadataNamesTheResourceTheIssuerAndTheScopes(): void
    {
        Configure::write('Mcp.auth.resourceName', 'Notes');
        $metadata = $this->server()->metadata();

        $this->assertSame(OAuthTestConfig::MCP, $metadata->getResource());
        $this->assertSame('http://localhost/.well-known/oauth-protected-resource/mcp', $metadata->getMetadataUrl());
        $document = $metadata->jsonSerialize();
        $this->assertSame(['http://localhost'], $document['authorization_servers']);
        $this->assertSame(['notes:read', 'notes:write', 'admin'], $document['scopes_supported']);
        $this->assertSame('Notes', $document['resource_name']);
    }

    public function testUndeclaredResourceNamesBothSettings(): void
    {
        Configure::write('Mcp.auth.resource', 'http://localhost/other');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Mcp\.auth\.resource.*OAuthServer\.resources/');
        $this->server()->metadata();
    }

    public function testUndeclaredScopeIsReported(): void
    {
        Configure::write('Mcp.auth.scopes.tools', ['create_note' => ['notes:wirte']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('"notes:wirte"');
        $this->server()->validator(new CurrentIdentity());
    }

    public function testOAuthModeValidatesWithTheAuthorizationServer(): void
    {
        $this->assertInstanceOf(OAuthServerTokenValidator::class, $this->server()->validator(new CurrentIdentity()));
        $this->assertInstanceOf(
            AuthorizationMiddleware::class,
            $this->server()->middleware(new CurrentIdentity(), new ResponseFactory(), new StreamFactory()),
        );
    }

    public function testHostModeHasNoValidator(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'host']);

        $this->expectException(LogicException::class);
        $this->server()->validator(new CurrentIdentity());
    }

    public function testOAuthModeGrantsWhatTheDeclaredResourceImplies(): void
    {
        $this->assertEqualsCanonicalizing(['notes:write', 'notes:read'], $this->server()->grantedScopes(['notes:write']));
        $this->assertSame(['notes:read'], $this->server()->grantedScopes(['notes:read']));
    }

    public function testExternalModeGrantsTheScopesUnchanged(): void
    {
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            'external' => ['issuer' => 'https://issuer.example'],
            'scopes' => ['implies' => ['notes:write' => ['notes:read']]],
        ]);

        $this->assertSame(['notes:write'], $this->server()->grantedScopes(['notes:write']));
    }

    public function testTheAuthorizationMiddlewareReadsBodiesUpToTheGivenLimit(): void
    {
        $middleware = $this->server()->middleware(
            new CurrentIdentity(),
            new ResponseFactory(),
            new StreamFactory(),
            2048,
        );

        $limit = new ReflectionProperty(AuthorizationMiddleware::class, 'maxBodyBytes');
        $this->assertSame(2048, $limit->getValue($middleware));
    }
}
