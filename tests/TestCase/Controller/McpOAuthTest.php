<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\Http\ServerRequest;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Controller\McpController;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Server\ServerFactory;
use CakeDC\Mcp\Test\Support\McpHttpTrait;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use CakeDC\OAuthServer\TestSuite\OAuthTestTrait;
use TestApp\OAuthTestConfig;
use TestApp\TestTokens;

/**
 * The MCP endpoint with Mcp.auth.mode oauth: tokens of the oauth plugin in the same app.
 */
class McpOAuthTest extends TestCase
{
    use IntegrationTestTrait;
    use McpHttpTrait;
    use OAuthTestTrait;

    private const METADATA_URL = 'http://localhost/.well-known/oauth-protected-resource/mcp';

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

    public function testNoTokenIsChallengedWithTheMetadataUrl(): void
    {
        $this->listTools();

        $this->assertResponseCode(401);
        $challenge = $this->challenge();
        $this->assertSame(self::METADATA_URL, $challenge['resource_metadata'] ?? null);
        $this->assertSame('notes:read notes:write admin', $challenge['scope'] ?? null);
        $this->assertArrayNotHasKey('error', $challenge);
    }

    public function testValidTokenListsAndCallsTools(): void
    {
        $token = $this->oauthTokenFor(1, ['notes:read'], OAuthTestConfig::MCP);

        $this->listTools($token);
        $this->assertResponseOk();
        $this->assertContains('whoami', array_column($this->responseJson()['result']['tools'] ?? [], 'name'));

        $this->callTool('whoami', [], $token);
        $this->assertResponseOk();
        $whoami = json_decode((string)($this->responseJson()['result']['content'][0]['text'] ?? ''), true);
        $this->assertSame(['identifier' => 1, 'subject' => '1'], $whoami);
    }

    public function testToolNeedingAnotherScopeAnswersWithTheScopesToStepUpTo(): void
    {
        $token = $this->oauthTokenFor(1, ['notes:read'], OAuthTestConfig::MCP);

        $this->callTool('create_note', ['title' => 'Groceries'], $token);

        $this->assertResponseCode(403);
        $challenge = $this->challenge();
        $this->assertSame('insufficient_scope', $challenge['error'] ?? null);
        $this->assertEqualsCanonicalizing(['notes:read', 'notes:write'], explode(' ', $challenge['scope'] ?? ''));
    }

    public function testImpliedScopesSatisfyThePolicy(): void
    {
        $token = $this->oauthTokenFor(1, ['notes:write'], OAuthTestConfig::MCP);

        $this->callTool('create_note', ['title' => 'Groceries'], $token);

        $this->assertResponseOk();
        $this->assertArrayHasKey('result', $this->responseJson());
    }

    public function testTokenForAnotherResourceIsRejected(): void
    {
        $token = $this->oauthTokenFor(1, ['api'], OAuthTestConfig::API);

        $this->callTool('whoami', [], $token);

        $this->assertResponseCode(401);
        $this->assertSame('invalid_token', $this->challenge()['error'] ?? null);
    }

    public function testSessionCookieAloneIsChallenged(): void
    {
        $this->session(['Auth' => ['id' => 1]]);

        $this->callTool('whoami');

        $this->assertResponseCode(401);
    }

    public function testHostTokensAreNotAcceptedInOAuthMode(): void
    {
        $this->callTool('whoami', [], TestTokens::EDITOR);

        $this->assertResponseCode(401);
        $this->assertSame('invalid_token', $this->challenge()['error'] ?? null);
    }

    public function testDeactivatedOwnerLosesAccessOnTheNextCall(): void
    {
        $token = $this->oauthTokenFor(2, ['notes:read'], OAuthTestConfig::MCP);
        $this->callTool('whoami', [], $token);
        $this->assertResponseOk();

        $this->fetchTable('Users')->updateAll(['active' => false], ['id' => 2]);
        $this->callTool('whoami', [], $token);

        $this->assertResponseCode(401);
    }

    public function testCorsPreflightIsNotChallenged(): void
    {
        $this->_request['headers'] = ['Host' => 'localhost'];
        $this->options('/mcp');

        $this->assertResponseCode(204);
    }

    public function testMisconfigurationFailsClosedAndNamesTheSetting(): void
    {
        Log::setConfig('mcp_oauth_test', ['className' => ArrayLog::class, 'scopes' => ['mcp'], 'levels' => ['error']]);
        try {
            Configure::write('Mcp.auth.scope', ['default' => ['notes:read']]);
            $token = $this->oauthTokenFor(1, ['notes:read'], OAuthTestConfig::MCP);

            $this->callTool('whoami', [], $token);

            $this->assertResponseCode(500);
            $this->assertArrayNotHasKey('result', $this->responseJson());
            $engine = Log::engine('mcp_oauth_test');
            $this->assertInstanceOf(ArrayLog::class, $engine);
            $this->assertStringContainsString('Unknown key "Mcp.auth.scope"', implode("\n", $engine->read()));
        } finally {
            Log::drop('mcp_oauth_test');
        }
    }

    public function testUndeclaredScopeFailsClosed(): void
    {
        Configure::write('Mcp.auth.scopes.tools', ['create_note' => ['notes:wirte']]);
        $token = $this->oauthTokenFor(1, ['notes:write'], OAuthTestConfig::MCP);

        $this->callTool('create_note', ['title' => 'Groceries'], $token);

        $this->assertResponseCode(500);
        $this->assertArrayNotHasKey('result', $this->responseJson());
    }

    public function testEveryRequestStartsWithoutAnIdentity(): void
    {
        $identity = new CurrentIdentity();
        $identity->set(new ArrayObject(['id' => 99]));
        $request = new ServerRequest([
            'url' => '/mcp',
            'environment' => [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'localhost',
                'HTTP_ACCEPT' => 'application/json, text/event-stream',
                'CONTENT_TYPE' => 'application/json',
            ],
            'input' => (string)json_encode($this->mcpMessage(1, 'tools/list')),
        ]);

        $response = (new McpController($request))->handle(new ServerFactory(new Container(), $identity), $identity);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertNull($identity->get());
    }

    public function testMetadataDocument(): void
    {
        $this->get('/.well-known/oauth-protected-resource/mcp');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $this->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertHeaderContains('Cache-Control', 'public');
        $document = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(OAuthTestConfig::MCP, $document['resource'] ?? null);
        $this->assertSame(['http://localhost'], $document['authorization_servers'] ?? null);
        $this->assertSame(['notes:read', 'notes:write', 'admin'], $document['scopes_supported'] ?? null);
    }

    public function testMetadataPreflight(): void
    {
        $this->options('/.well-known/oauth-protected-resource/mcp');

        $this->assertResponseCode(204);
        $this->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertHeaderContains('Access-Control-Allow-Methods', 'GET');
    }

    public function testMetadataIsNotFoundInHostMode(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'host']);

        $this->get('/.well-known/oauth-protected-resource/mcp');

        $this->assertResponseCode(404);
    }

    public function testMetadataMisconfigurationAnswersAServerError(): void
    {
        Configure::write('Mcp.auth.resource', 'http://localhost/other');

        $this->get('/.well-known/oauth-protected-resource/mcp');

        $this->assertResponseCode(500);
        $this->assertSame(['error' => 'server_error'], json_decode((string)$this->_response->getBody(), true));
    }
}
