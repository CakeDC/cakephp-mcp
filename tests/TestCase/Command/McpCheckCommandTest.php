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
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Command\McpCheckCommand;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
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
        foreach (['skeleton_debug', 'skeleton_error', 'mcp_test', 'audit_check', 'object_log', 'psr_log'] as $name) {
            if (in_array($name, Log::configured(), true)) {
                Log::drop($name);
            }
        }
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

    public function testAuditOff(): void
    {
        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Audit: off');
    }

    public function testAuditOnWithItsLogScope(): void
    {
        Configure::write('Mcp.audit.enabled', true);
        Log::setConfig('audit_check', ['className' => ArrayLog::class, 'scopes' => ['mcp_audit']]);
        try {
            $this->exec('mcp check');
        } finally {
            Log::drop('audit_check');
        }

        $this->assertOutputContains('[PASS] Audit: tools/call to the mcp_audit log scope');
    }

    /**
     * The debug and error engines of the current cakephp/app skeleton.
     */
    private function skeletonLogs(): void
    {
        Log::setConfig('skeleton_debug', ['className' => ArrayLog::class, 'scopes' => null, 'levels' => ['notice', 'info', 'debug']]);
        Log::setConfig('skeleton_error', ['className' => ArrayLog::class, 'scopes' => null, 'levels' => ['warning', 'error', 'critical', 'alert', 'emergency']]);
    }

    public function testLoggingWarnsWhenNoEngineTakesTheMcpScope(): void
    {
        $this->skeletonLogs();

        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('[WARN] Logging: no Log engine takes the errors of the mcp scope');
        $this->assertOutputContains("'scopes' => ['mcp']");
        $this->assertOutputContains('docs/configuration.md#logging');
    }

    public function testLoggingPassesWithAnEngineForTheMcpScope(): void
    {
        $this->skeletonLogs();
        Log::setConfig('mcp_test', ['className' => ArrayLog::class, 'scopes' => ['mcp']]);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Logging: the mcp scope goes to mcp_test');
    }

    public function testAnEngineWithEmptyScopesTakesEverything(): void
    {
        Log::setConfig('mcp_test', ['className' => ArrayLog::class, 'scopes' => []]);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Logging: the mcp scope goes to mcp_test');
    }

    public function testAnEngineWithoutTheErrorLevelDoesNotCount(): void
    {
        Log::setConfig('mcp_test', ['className' => ArrayLog::class, 'scopes' => ['mcp'], 'levels' => ['info']]);

        $this->exec('mcp check');

        $this->assertOutputContains('[WARN] Logging');
    }

    public function testEnginesGivenAsObjectsCount(): void
    {
        Log::setConfig('object_log', new ArrayLog(['scopes' => ['mcp']]));

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Logging: the mcp scope goes to object_log');
    }

    public function testAPlainPsrLoggerTakesEverything(): void
    {
        Log::setConfig('psr_log', new NullLogger());

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Logging: the mcp scope goes to psr_log');
    }

    public function testAuditLinesReachAnEngineOfTheMcpScope(): void
    {
        Configure::write('Mcp.audit.enabled', true);
        Log::setConfig('mcp_test', ['className' => ArrayLog::class, 'scopes' => ['mcp']]);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Audit: tools/call to the mcp_audit log scope');
    }

    public function testAuditOnWithoutAnEngineForItsScope(): void
    {
        Configure::write('Mcp.audit.enabled', true);

        $this->exec('mcp check');

        $this->assertOutputContains('[WARN] Audit: on, but no Log engine takes its lines');
        $this->assertOutputContains("'scopes' => ['mcp_audit']");
    }

    public function testInvalidAuditSettingFails(): void
    {
        Configure::write('Mcp.audit.maxLength', 0);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Audit: "Mcp.audit.maxLength"');
    }

    public function testToolsAreListed(): void
    {
        $this->exec('mcp check');

        $this->assertOutputRegExp('/\\[PASS\\] Tools: \\d+ tools: .*echo/');
    }

    public function testNoToolFoundWarns(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/NoTools']);

        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('[WARN] Tools: no tool found in src/NoTools');
        $this->assertOutputContains('namespace');
    }

    public function testTheDefaultAuthorizerPasses(): void
    {
        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Authorizer: AllowAllToolAuthorizer');
    }

    /**
     * @param list<array<string, mixed>> $permissions
     */
    private function rbac(array $permissions): void
    {
        Configure::write('Mcp.authorization', [
            'authorizer' => RbacToolAuthorizer::class,
            'rbac' => ['log' => false, 'permissions' => $permissions],
        ]);
    }

    public function testRbacWithoutRulesWarns(): void
    {
        $this->rbac([]);

        $this->exec('mcp check');

        $this->assertOutputContains('[WARN] Authorizer: RbacToolAuthorizer has no rules, so no one can call a tool');
    }

    public function testRbacWithoutRulesForTheToolsWarns(): void
    {
        $this->rbac([
            ['role' => 'admin', 'plugin' => null, 'controller' => 'Articles', 'action' => '*'],
            ['role' => 'admin', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => '*', 'allowed' => false],
        ]);

        $this->exec('mcp check');

        $this->assertOutputContains("[WARN] Authorizer: none of the 2 rules of RbacToolAuthorizer allows plugin 'CakeDC/Mcp', controller 'Tools'");
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function toolRules(): array
    {
        return [
            'exact' => [['role' => 'editor', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => ['echo']]],
            'wildcards' => [['role' => 'admin', 'plugin' => '*', 'controller' => '*', 'action' => '*']],
            'no plugin key' => [['role' => 'admin', 'controller' => ['Tools', 'Articles'], 'action' => '*']],
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    #[DataProvider('toolRules')]
    public function testRbacWithARuleForTheToolsPasses(array $rule): void
    {
        $this->rbac([$rule]);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Authorizer: RbacToolAuthorizer, 1 of 1 rules for the tools');
    }

    public function testToolScopesPassWhenEveryToolNeedsOne(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Tool scopes: every tool needs a scope');
    }

    public function testToolScopesWarnAboutFreeToolsAndUnknownNames(): void
    {
        Configure::write('Mcp.auth', [
            'mode' => 'oauth',
            'scopes' => ['tools' => ['create_note' => ['notes:write'], 'creat_note' => ['notes:write']]],
        ]);

        $this->exec('mcp check');

        $this->assertOutputContains('[WARN] Tool scopes: ');
        $this->assertOutputRegExp('/tools that need no scope, so any valid token may call them: [^.]*echo/');
        $this->assertOutputContains('Mcp.auth.scopes.tools names tools that do not exist: creat_note');
    }

    public function testToolScopesDoNotApplyInHostMode(): void
    {
        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Tool scopes: host mode, access tokens and their scopes do not apply');
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

    /**
     * With cakedc/cakephp-oauth-server 1.0.0 one Token authenticator can protect the API and the MCP endpoint.
     */
    public function testCakeDcUsersTokenAuthenticatorForSeveralResourcesIncludingTheMcpOnePasses(): void
    {
        $this->usersHost();
        Configure::write('OAuthServer.users', ['resource' => [OAuthTestConfig::API, 'http://localhost/mcp']]);
        Configure::write('Auth.Authenticators.OAuthServerToken', [
            'className' => 'CakeDC/OAuthServer.OAuthServerToken',
            'skipTwoFactorVerify' => true,
            'resource' => [OAuthTestConfig::API, OAuthTestConfig::MCP],
            'resourceMetadataUrl' => 'http://localhost/.well-known/oauth-protected-resource/mcp',
        ]);

        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('[PASS] Authenticators');
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
