<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\Mcp\Test\Support\TestJwt;
use CakeDC\OAuthServer\OAuthServer;
use CakeDC\OAuthServer\TestSuite\OAuthTestTrait;
use stdClass;
use TestApp\OAuthTestConfig;
use TestApp\TestTokens;

/**
 * What a named server takes from the default server is never more open than either one, and never makes it
 * accept the default server's tokens.
 */
class McpServersInheritanceTest extends TestCase
{
    use IntegrationTestTrait;
    use OAuthTestTrait;

    private const ADMIN = 'http://localhost/mcp/admin';
    private const ADMIN_METADATA = 'http://localhost/.well-known/oauth-protected-resource/mcp/admin';

    protected array $fixtures = OAuthFixtures::ALL;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('mcp_oauth_test', ['className' => ArrayEngine::class]);
        OAuthServer::reset();
        Configure::write('Mcp.servers.admin', [
            'route' => '/mcp/admin',
            'discovery' => ['scanDirs' => ['src/McpDocs']],
        ]);
    }

    protected function tearDown(): void
    {
        OAuthServer::reset();
        Cache::drop('mcp_oauth_test');
        Router::reload();
        parent::tearDown();
    }

    public function testOAuthModeATokenForTheDefaultServerIsRefusedByANamedServerThatInheritsAuth(): void
    {
        $this->declareTheAdminResource();
        Configure::write('Mcp.auth', ['resource' => OAuthTestConfig::MCP] + OAuthTestConfig::MCP_AUTH);
        $token = $this->oauthTokenFor(1, ['notes:read'], OAuthTestConfig::MCP);

        $this->modern('/mcp', 'tools/list', $token);
        $this->assertResponseOk();

        $this->modern('/mcp/admin', 'tools/list', $token);
        $this->assertResponseCode(401);
        $this->assertStringContainsString(
            'resource_metadata="' . self::ADMIN_METADATA . '"',
            $this->_response->getHeaderLine('WWW-Authenticate'),
        );

        $this->modern('/mcp/admin', 'tools/list', $this->oauthTokenFor(1, ['notes:read'], self::ADMIN));
        $this->assertResponseOk();
    }

    public function testANamedServerThatInheritsAnExplicitResourceNamesItsOwn(): void
    {
        $this->declareTheAdminResource();
        Configure::write('Mcp.auth', ['resource' => OAuthTestConfig::MCP] + OAuthTestConfig::MCP_AUTH);

        $this->get('/.well-known/oauth-protected-resource/mcp/admin');

        $this->assertResponseOk();
        $document = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(self::ADMIN, $document['resource'] ?? null);
    }

    public function testExternalModeATokenForTheDefaultServerIsRefusedByANamedServerThatInheritsAuth(): void
    {
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            'resource' => OAuthTestConfig::MCP,
            'external' => ['issuer' => TestJwt::ISSUER, 'cache' => 'mcp_oauth_test', 'httpClient' => TestJwt::client()],
        ]);

        $this->modern('/mcp', 'tools/list', TestJwt::token(['aud' => OAuthTestConfig::MCP]));
        $this->assertResponseOk();

        $this->modern('/mcp/admin', 'tools/list', TestJwt::token(['aud' => OAuthTestConfig::MCP]));
        $this->assertResponseCode(401);

        $this->modern('/mcp/admin', 'tools/list', TestJwt::token(['aud' => self::ADMIN]));
        $this->assertResponseOk();
    }

    public function testAuthWithoutAModeIsRefused(): void
    {
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            'external' => ['issuer' => TestJwt::ISSUER, 'cache' => 'mcp_oauth_test', 'httpClient' => TestJwt::client()],
        ]);
        // "The admin server also needs the admin scope", without `mode`: that would be host mode.
        Configure::write('Mcp.servers.admin.auth', ['scopes' => ['default' => ['admin']]]);

        $this->modern('/mcp/admin', 'tools/list', TestTokens::EDITOR);

        $this->assertResponseError();
        $this->assertResponseNotContains('list_orders');
    }

    public function testAMisspelledAuthorizerKeyIsRefused(): void
    {
        $denyAll = new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return false;
            }
        };
        Configure::write('Mcp.servers.admin.authorization', ['authoriser' => $denyAll]);

        $this->modern('/mcp/admin', 'tools/list', TestTokens::EDITOR);

        $this->assertResponseError();
        $this->assertResponseNotContains('list_orders');
    }

    public function testAMisspelledSectionIsRefused(): void
    {
        Configure::write('Mcp.servers.admin.authorisation', ['authorizer' => new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return false;
            }
        }]);

        $this->modern('/mcp/admin', 'tools/list', TestTokens::EDITOR);

        $this->assertResponseError();
        $this->assertResponseNotContains('list_orders');
    }

    public function testANamedServerNextToAPublicDefaultServerStillNeedsAnIdentity(): void
    {
        Configure::write('Mcp.identity.required', false);

        $this->modern('/mcp', 'tools/list', null);
        $this->assertResponseOk();

        $this->modern('/mcp/admin', 'tools/list', null);
        $this->assertResponseCode(401);
    }

    private function declareTheAdminResource(): void
    {
        $resources = (array)Configure::read('OAuthServer.resources');
        $resources[self::ADMIN] = $resources[OAuthTestConfig::MCP];
        Configure::write('OAuthServer.resources', $resources);
        OAuthServer::reset();
    }

    private function modern(string $path, string $method, ?string $token): void
    {
        $headers = [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2026-07-28',
            'Mcp-Method' => $method,
        ];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $this->configRequest(['headers' => $headers]);
        $this->post($path, (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            ],
        ]]));
    }
}
