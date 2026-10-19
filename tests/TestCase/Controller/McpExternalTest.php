<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\McpHttpTrait;
use CakeDC\Mcp\Test\Support\TestJwt;

class McpExternalTest extends TestCase
{
    use IntegrationTestTrait;
    use McpHttpTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('mcp_oauth_test', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            'scopes' => ['methods' => ['tools/call' => ['notes:read']], 'tools' => ['create_note' => ['notes:write']]],
            'external' => ['issuer' => TestJwt::ISSUER, 'cache' => 'mcp_oauth_test', 'httpClient' => TestJwt::client()],
        ]);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_oauth_test');
        parent::tearDown();
    }

    public function testJwtFromTheExternalIssuerCallsTools(): void
    {
        $this->callTool('whoami', [], TestJwt::token());

        $this->assertResponseOk();
        $whoami = json_decode((string)($this->responseJson()['result']['content'][0]['text'] ?? ''), true);
        $this->assertSame(['identifier' => 'user-1', 'subject' => 'user-1'], $whoami);
    }

    public function testMissingScopeIs403(): void
    {
        $this->callTool('create_note', ['title' => 'Groceries'], TestJwt::token());

        $this->assertResponseCode(403);
        $this->assertSame('insufficient_scope', $this->challenge()['error'] ?? null);
    }

    public function testChallengePointsAtTheMetadataNamingTheIssuer(): void
    {
        $this->listTools();
        $this->assertResponseCode(401);
        $this->assertSame(
            'http://localhost/.well-known/oauth-protected-resource/mcp',
            $this->challenge()['resource_metadata'] ?? null,
        );

        $this->get('/.well-known/oauth-protected-resource/mcp');
        $document = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame([TestJwt::ISSUER], $document['authorization_servers'] ?? null);
    }
}
