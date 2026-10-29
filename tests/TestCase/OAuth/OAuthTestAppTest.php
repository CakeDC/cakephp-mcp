<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\OAuth;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use CakeDC\OAuthServer\TestSuite\OAuthTestTrait;
use TestApp\OAuthTestConfig;

/**
 * The test app runs cakedc/cakephp-oauth-server, which the OAuth tests build on.
 */
class OAuthTestAppTest extends TestCase
{
    use IntegrationTestTrait;
    use OAuthTestTrait;

    protected array $fixtures = OAuthFixtures::ALL;

    protected function setUp(): void
    {
        parent::setUp();
        OAuthServer::reset();
    }

    protected function tearDown(): void
    {
        OAuthServer::reset();
        parent::tearDown();
    }

    public function testTheTestAppServesAuthorizationServerMetadata(): void
    {
        $this->get('/.well-known/oauth-authorization-server');

        $this->assertResponseOk();
        $metadata = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('http://localhost', $metadata['issuer'] ?? null);
    }

    public function testTokensCanBeIssuedForActiveTestUsers(): void
    {
        $token = $this->oauthTokenFor(1, ['notes:read'], OAuthTestConfig::MCP);

        $this->assertStringStartsWith('test_', $token);
    }
}
