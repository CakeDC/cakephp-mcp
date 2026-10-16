<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Command;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Command\McpCheckCommand;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use TestApp\Authenticator\ApiTokenAuthenticator;
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
        Cache::drop('mcp_check_cache');
        Router::reload();
        // The stand-in for cakedc/users must not reach the next test.
        Plugin::getCollection()->remove('CakeDC/Users');
        parent::tearDown();
    }

    /**
     * The cakedc/users host of the test app, in oauth mode.
     */
    private function usersHost(): void
    {
        Configure::write('App.namespace', 'TestApp\\UsersHost');
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
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
        Configure::write('TestApp.oauthTokenAuthenticator', 'everywhere');

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

        $this->assertOutputContains('[WARN] Resource');
        $this->assertOutputContains('must issue tokens');
    }

    public function testUnconnectedMetadataRouteFails(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        Configure::write('TestApp.mcpRoutes', false);
        Router::reload();

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Metadata route');
    }

    public function testDisabledRouteSkipsTheMetadataRoute(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        Configure::write('Mcp.route', false);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Metadata route: no HTTP endpoint');
    }

    public function testTheOAuthPluginCheckPassesWithTheDocumentedSetup(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);

        $this->exec('oauth check');

        $this->assertExitSuccess();
        $this->assertOutputNotContains('[FAIL]');
        $this->assertOutputContains('[PASS] Authenticators: Token comes before Session');
    }

    public function testTheOAuthPluginCheckFailsWhenTheSessionComesFirst(): void
    {
        // The order docs/oauth.md showed before: Session, then the Token authenticator.
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        Configure::write('TestApp.sessionFirst', true);

        $this->exec('oauth check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Authenticators: the Session authenticator comes before the Token');
    }

    public function testAnAuthenticatorThatLeavesTheMcpEndpointAlonePasses(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        Configure::write('TestApp.oauthTokenAuthenticator', 'skipsMcp');

        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('[PASS] Authenticators');
    }

    public function testMissingOAuthPluginFailsWithTheInstallHint(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        $out = new StubConsoleOutput();
        $command = new class extends McpCheckCommand {
            protected function oauthServerInstalled(): bool
            {
                return false;
            }
        };

        $code = $command->run([], new ConsoleIo($out, new StubConsoleOutput(), new StubConsoleInput([])));

        $this->assertSame(McpCheckCommand::CODE_ERROR, $code);
        $this->assertStringContainsString(
            '[FAIL] Authenticators: "Mcp.auth.mode" oauth needs cakedc/cakephp-oauth-server',
            $out->output(),
        );
    }

    public function testCakeDcUsersTokenAuthenticatorForTheMcpResourceWarnsWithoutTheMetadataUrl(): void
    {
        // The oauth plugin's integration registers its Token authenticator for the default resource, the MCP one.
        $this->usersHost();

        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('[WARN] Authenticators: the Token authenticator of cakedc/users');
        $this->assertOutputContains('Auth.Authenticators.OAuthServerToken');
        $this->assertOutputContains('resourceMetadataUrl');
        $this->assertOutputContains('http://localhost/.well-known/oauth-protected-resource/mcp');
    }

    public function testCakeDcUsersTokenAuthenticatorWithTheMetadataUrlPasses(): void
    {
        $this->usersHost();
        Configure::write('Auth.Authenticators.OAuthServerToken', [
            'className' => 'CakeDC/OAuthServer.OAuthServerToken',
            'skipTwoFactorVerify' => true,
            'resourceMetadataUrl' => 'http://localhost/.well-known/oauth-protected-resource/mcp',
        ]);

        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('[PASS] Authenticators');
    }

    public function testCakeDcUsersTokenAuthenticatorForAnotherResourceFails(): void
    {
        $this->usersHost();
        Configure::write('OAuthServer.users', ['resource' => OAuthTestConfig::API]);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Authenticators: the Token authenticator of cakedc/users');
        $this->assertOutputContains('refuses MCP access tokens at /mcp');
    }

    public function testCakeDcUsersTokenAuthenticatorThatLeavesTheMcpEndpointAlonePasses(): void
    {
        $this->usersHost();
        Configure::write('OAuthServer.users', ['resource' => OAuthTestConfig::API]);
        Configure::write('Auth.Authenticators.OAuthServerToken', [
            'className' => ApiTokenAuthenticator::class,
            'resource' => OAuthTestConfig::API,
        ]);

        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('[PASS] Authenticators');
    }
}
