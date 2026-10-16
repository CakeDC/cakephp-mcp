<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\OAuth;

use ArrayObject;
use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\OAuth\AuthSettings;
use CakeDC\Mcp\OAuth\ExternalTokenValidator;
use CakeDC\Mcp\OAuth\ResourceServer;
use CakeDC\Mcp\Test\Support\FakeHttpClient;
use CakeDC\Mcp\Test\Support\TestJwt;
use Mcp\Server\Authorization\AccessToken;
use PHPUnit\Framework\Attributes\DataProvider;

class ExternalTokenValidatorTest extends TestCase
{
    private CurrentIdentity $current;

    private FakeHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('mcp_oauth_test', ['className' => ArrayEngine::class]);
        $this->client = TestJwt::client();
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            'scopes' => ['methods' => ['tools/call' => ['notes:read']], 'tools' => ['create_note' => ['notes:write']]],
            'external' => ['issuer' => TestJwt::ISSUER, 'cache' => 'mcp_oauth_test', 'httpClient' => $this->client],
        ]);
        $this->current = new CurrentIdentity();
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_oauth_test');
        parent::tearDown();
    }

    private function server(): ResourceServer
    {
        return new ResourceServer(AuthSettings::fromConfig());
    }

    public function testValidTokenIsAllowedWithAClaimsIdentity(): void
    {
        $result = $this->server()->validator($this->current)->validate(TestJwt::token());

        $this->assertTrue($result->isAllowed());
        $this->assertSame(['notes:read'], $result->getAccessToken()?->getScopes());
        $identity = $this->current->get();
        $this->assertInstanceOf(ArrayObject::class, $identity);
        $this->assertSame('user-1', $this->current->getIdentifier());
        $this->assertSame(TestJwt::ISSUER, $identity['claims']['iss']);
    }

    public function testKeysAreFetchedOnceAndCached(): void
    {
        $this->server()->validator($this->current)->validate(TestJwt::token());
        $this->server()->validator(new CurrentIdentity())->validate(TestJwt::token());

        $this->assertSame(1, count(array_keys($this->client->requested, TestJwt::ISSUER . '/jwks', true)));
    }

    public function testRejectedTokens(): void
    {
        foreach (
            [
                'audience' => ['aud' => 'https://other.example/mcp'],
                'issuer' => ['iss' => 'https://evil.example'],
                'expired' => ['exp' => time() - 60, 'iat' => time() - 600],
            ] as $case => $claims
        ) {
            $result = $this->server()->validator($this->current)->validate(TestJwt::token($claims));

            $this->assertSame(401, $result->getStatusCode(), $case);
            $this->assertNull($this->current->get(), $case);
        }
    }

    public function testTokenWithoutSubjectHasNoDefaultIdentity(): void
    {
        $result = $this->server()->validator($this->current)->validate(TestJwt::token(['sub' => null]));

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
    }

    public function testIdentityResolverMapsTheToken(): void
    {
        Configure::write(
            'Mcp.auth.identity',
            fn(AccessToken $token, mixed $identity): ?array => $token->getSubject() === 'user-1' ? ['id' => 7] : null,
        );

        $this->assertTrue($this->server()->validator($this->current)->validate(TestJwt::token())->isAllowed());
        $this->assertSame(['id' => 7], $this->current->get());

        $result = $this->server()->validator(new CurrentIdentity())->validate(TestJwt::token(['sub' => 'user-2']));
        $this->assertSame(401, $result->getStatusCode());
    }

    public function testMetadataNamesTheExternalIssuerAndTheConfiguredScopes(): void
    {
        $document = $this->server()->metadata()->jsonSerialize();

        $this->assertSame([TestJwt::ISSUER], $document['authorization_servers']);
        $this->assertEqualsCanonicalizing(['notes:read', 'notes:write'], $document['scopes_supported']);
    }

    public function testValidatorIsAnExternalTokenValidator(): void
    {
        $this->assertInstanceOf(ExternalTokenValidator::class, $this->server()->validator($this->current));
    }

    public function testTokenClaimsNeverBecomeTopLevelIdentityFields(): void
    {
        $this->assertTrue($this->server()->validator($this->current)->validate(
            TestJwt::token(['role' => 'admin']),
        )->isAllowed());
        $identity = $this->current->get();
        $this->assertInstanceOf(ArrayObject::class, $identity);
        $this->assertFalse(isset($identity['role']));
        $this->assertSame('admin', $identity['claims']['role']);

        $authorizer = new RbacToolAuthorizer(['log' => false, 'permissions' => [
            ['role' => 'admin', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => '*'],
        ]]);
        $this->assertFalse($authorizer->canCall($identity, 'create_note'));
        $this->assertTrue($authorizer->canCall(['id' => 1, 'role' => 'admin'], 'create_note'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forgedTokens(): array
    {
        return [
            'other key same kid' => ['forged'],
            'hs256' => ['hmac'],
            'alg none' => ['unsigned'],
            'unknown kid' => ['unknownKid'],
        ];
    }

    #[DataProvider('forgedTokens')]
    public function testForgedOrDisallowedTokensAreRejected(string $builder): void
    {
        $token = $builder === 'hmac' ? TestJwt::hmac('a-shared-secret-of-sufficient-length-32') : TestJwt::$builder();

        $result = $this->server()->validator($this->current)->validate($token);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertNull($this->current->get());
    }

    public function testFailedDiscoveryIs401AndBacksOff(): void
    {
        $client = new FakeHttpClient();
        Configure::write('Mcp.auth.external.httpClient', $client);

        $first = $this->server()->validator($this->current)->validate(TestJwt::token());
        $this->assertSame(401, $first->getStatusCode());
        $this->assertSame('invalid_token', $first->getError());
        $requests = count($client->requested);
        $this->assertGreaterThan(0, $requests);

        $second = $this->server()->validator(new CurrentIdentity())->validate(TestJwt::token());
        $this->assertSame(401, $second->getStatusCode());
        $this->assertCount($requests, $client->requested);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notAnIdentity(): array
    {
        return [
            'false' => [false],
            'true' => [true],
            'zero' => [0],
            'integer' => [7],
            'empty string' => [''],
            'string' => ['user-1'],
            'float' => [1.5],
        ];
    }

    #[DataProvider('notAnIdentity')]
    public function testIdentityResolverReturningAScalarRejectsTheToken(mixed $returned): void
    {
        Configure::write('Mcp.auth.identity', fn(): mixed => $returned);

        $result = $this->server()->validator($this->current)->validate(TestJwt::token());

        $this->assertFalse($result->isAllowed());
        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('invalid_token', $result->getError());
        $this->assertFalse($this->current->has());
    }
}
