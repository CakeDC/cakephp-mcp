<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\OAuth;

use Authentication\IdentityInterface;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\OAuth\OAuthServerTokenValidator;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use CakeDC\OAuthServer\TestSuite\OAuthTestTrait;
use Mcp\Server\Authorization\AccessToken;
use TestApp\OAuthTestConfig;

class OAuthServerTokenValidatorTest extends TestCase
{
    use OAuthTestTrait;

    protected array $fixtures = OAuthFixtures::ALL;

    private CurrentIdentity $current;

    protected function setUp(): void
    {
        parent::setUp();
        OAuthServer::reset();
        $this->current = new CurrentIdentity();
    }

    protected function tearDown(): void
    {
        OAuthServer::reset();
        parent::tearDown();
    }

    private function validator(?callable $identity = null): OAuthServerTokenValidator
    {
        return new OAuthServerTokenValidator(
            OAuthServer::server()->tokenValidator(),
            OAuthTestConfig::MCP,
            $this->current,
            $identity === null ? null : $identity(...),
        );
    }

    public function testValidTokenIsAllowedWithItsScopesAndOwner(): void
    {
        $token = $this->oauthTokenFor(1, ['notes:write'], OAuthTestConfig::MCP);

        $result = $this->validator()->validate($token);

        $this->assertTrue($result->isAllowed());
        $access = $result->getAccessToken();
        $this->assertNotNull($access);
        // The core expands what notes:write implies.
        $this->assertEqualsCanonicalizing(['notes:write', 'notes:read'], $access->getScopes());
        $this->assertSame('1', $access->getSubject());
        $this->assertSame('user', $access->getClaim('subject_type'));
        $this->assertSame(OAuthTestConfig::MCP, $access->getClaim('aud'));
        $this->assertSame('Ada', $access->getClaim('name'));
        $identity = $this->current->get();
        $this->assertInstanceOf(IdentityInterface::class, $identity);
        $this->assertSame(1, $this->current->getIdentifier());
    }

    public function testTokenForAnotherResourceIsRejected(): void
    {
        $token = $this->oauthTokenFor(1, ['api'], OAuthTestConfig::API);

        $result = $this->validator()->validate($token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertStringContainsString('another resource', (string)$result->getErrorDescription());
        $this->assertNull($this->current->get());
    }

    public function testUnknownTokenIsRejected(): void
    {
        $result = $this->validator()->validate('test_at_' . str_repeat('A', 43));

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
    }

    public function testDeactivatedOwnerIsRejected(): void
    {
        $token = $this->oauthTokenFor(2, ['notes:read'], OAuthTestConfig::MCP);
        $this->fetchTable('Users')->updateAll(['active' => false], ['id' => 2]);

        $result = $this->validator()->validate($token);

        $this->assertSame(401, $result->getStatusCode());
        $this->assertNull($this->current->get());
    }

    public function testIdentityResolverReceivesTheTokenAndTheOwnerIdentity(): void
    {
        $token = $this->oauthTokenFor(1, ['notes:read'], OAuthTestConfig::MCP);
        $seen = [];

        $result = $this->validator(function (AccessToken $access, mixed $identity) use (&$seen): array {
            $seen = [$access->getSubject(), $identity instanceof IdentityInterface];

            return ['id' => 'mapped'];
        })->validate($token);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(['1', true], $seen);
        $this->assertSame(['id' => 'mapped'], $this->current->get());
    }

    public function testIdentityResolverReturningNullRejectsTheToken(): void
    {
        $token = $this->oauthTokenFor(1, ['notes:read'], OAuthTestConfig::MCP);

        $result = $this->validator(fn(): mixed => null)->validate($token);

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertNull($this->current->get());
    }
}
