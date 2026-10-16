<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Command;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use TestApp\OAuthTestConfig;

class McpCheckCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

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

    public function testHostMode(): void
    {
        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('[PASS] Endpoint: http://localhost/mcp');
        $this->assertOutputContains('[PASS] Authentication: host mode');
        $this->assertOutputContains('[PASS] CSRF');
    }

    public function testOAuthModeReportsTheResourceAndItsMetadata(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);

        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('[PASS] Authentication: oauth mode');
        $this->assertOutputContains(
            '[PASS] Resource: http://localhost/mcp, metadata at '
            . 'http://localhost/.well-known/oauth-protected-resource/mcp',
        );
        $this->assertOutputContains('[PASS] Authenticators');
    }

    public function testUndeclaredResourceFails(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'oauth', 'resource' => 'http://localhost/other']);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Resource');
        $this->assertOutputContains('OAuthServer.resources');
    }

    public function testInvalidSettingsFail(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'oauth', 'scope' => []]);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Authentication: Unknown key "Mcp.auth.scope"');
    }

    public function testResourceHostOutsideTheAllowedHostsFails(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'external', 'resource' => 'https://mcp.example.com/mcp', 'external' => [
            'issuer' => 'https://issuer.example',
        ]]);
        Configure::write('Mcp.http.allowedHosts', ['localhost']);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Allowed hosts');
        $this->assertOutputContains('mcp.example.com');
    }

    public function testATokenAuthenticatorOnTheMcpRouteFails(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        Configure::write('TestApp.oauthTokenAuthenticator', true);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Authenticators');
        $this->assertOutputContains('McpConfig::isEndpoint()');
    }

    public function testOAuthModeConnectsTheMetadataRoute(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Metadata route: /.well-known/oauth-protected-resource/mcp');
    }

    public function testHostModeDoesNotUseTheMetadataRoute(): void
    {
        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Metadata route: not used in host mode');
    }

    public function testSubdirectoryInstallWarnsAboutTheWellKnownRewrite(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'external', 'resource' => 'http://localhost/mcp', 'external' => [
            'issuer' => 'https://issuer.example',
            'cache' => 'mcp_check_cache',
        ]]);
        Cache::setConfig('mcp_check_cache', ['className' => ArrayEngine::class]);
        Configure::write('App.base', '/app');
        Router::reload();
        Router::setRequest(new ServerRequest(['base' => '/app']));

        $this->exec('mcp check');
        Cache::drop('mcp_check_cache');

        $this->assertOutputContains('[WARN] Metadata route');
        $this->assertOutputContains('rewrite');
    }

    public function testExternalModeReportsTheAudiences(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'external', 'resource' => 'http://localhost/mcp', 'external' => [
            'issuer' => 'https://issuer.example',
            'audience' => ['http://localhost/mcp', 'api://other'],
            'cache' => 'mcp_check_cache',
        ]]);
        Cache::setConfig('mcp_check_cache', ['className' => ArrayEngine::class]);

        $this->exec('mcp check');
        Cache::drop('mcp_check_cache');

        $this->assertOutputContains('[PASS] Resource: http://localhost/mcp');
        $this->assertOutputContains('accepted audiences: http://localhost/mcp, api://other');
    }

    public function testAnAudienceThatIsNotTheResourceWarns(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'external', 'resource' => 'http://localhost/mcp', 'external' => [
            'issuer' => 'https://issuer.example',
            'audience' => 'https://api.example.com',
            'cache' => 'mcp_check_cache',
        ]]);
        Cache::setConfig('mcp_check_cache', ['className' => ArrayEngine::class]);

        $this->exec('mcp check');
        Cache::drop('mcp_check_cache');

        $this->assertOutputContains('[WARN] Resource');
        $this->assertOutputContains('must issue tokens');
    }
}
