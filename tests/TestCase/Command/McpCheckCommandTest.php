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
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Command\McpCheckCommand;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Server\Builder;
use Mcp\Server\Wire\CachePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use TestApp\Authenticator\ApiTokenAuthenticator;
use TestApp\ContentAuthorizer;
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

    public function testListingTheToolsPrintsNoServerLogLines(): void
    {
        $this->exec('mcp check');

        $this->assertOutputNotContains('Running server');
        $this->assertOutputNotContains('Handling request');
        $this->assertOutputNotContains('Attribute discovery finished');
    }

    public function testToolsAreListed(): void
    {
        $this->exec('mcp check');

        $this->assertOutputRegExp('/\\[PASS\\] Tools: \\d+ tools: .*echo/');
    }

    public function testToolSchemasWarnAboutUnionsAndUntypedArrays(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpSchema']);

        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('[WARN] Tool schemas: report.date_from: type union null|string');
        $this->assertOutputContains('report.statuses: array without item type');
        $this->assertOutputContains('tools.md#input-schemas');
    }

    public function testToolSchemasFollowTheNormalizer(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpSchema']);
        Configure::write('Mcp.schema.normalize', true);

        $this->exec('mcp check');

        $this->assertOutputNotContains('type union');
    }

    public function testToolSchemasWithoutToolsPass(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/NoTools']);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Tool schemas: no tools to check');
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

    public function testTheAuthorizerLineSaysWhetherResourcesAndPromptsAreCovered(): void
    {
        $this->exec('mcp check');
        $this->assertOutputContains('resources and prompts are not covered');

        Configure::write('Mcp.authorization.authorizer', ContentAuthorizer::class);
        $this->exec('mcp check');
        $this->assertOutputContains('[PASS] Authorizer: TestApp\\ContentAuthorizer; resources and prompts are covered');

        $this->rbac([
            ['role' => 'editor', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => '*'],
            ['role' => 'editor', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Prompts', 'action' => '*'],
        ]);
        $this->exec('mcp check');
        $this->assertOutputContains('[PASS] Authorizer: RbacToolAuthorizer, 1 of 2 rules for the tools; prompts are covered, '
            . 'resources are not (no rule for controller Resources)');
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

    public function testNamedExternalServerHasItsOwnMetadataRoute(): void
    {
        Cache::setConfig('mcp_check_cache', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'auth' => ['mode' => 'external', 'external' => [
            'issuer' => 'https://issuer.example',
            'cache' => 'mcp_check_cache',
        ]]]);

        $this->exec('mcp check --server admin');

        $this->assertOutputContains('[PASS] Metadata route');
        $this->assertOutputNotContains('advertised URL path');
    }

    public function testRbacRulesWithoutTheRbacAuthorizerWarn(): void
    {
        Configure::write('Mcp.authorization.rbac', ['permissions' => [['role' => 'admin']]]);

        $this->exec('mcp check');

        $this->assertOutputContains('[WARN] Authorizer: authorization.rbac has rules, but the authorizer is');
    }

    public function testANamedServersAuthorizationWithoutAnAuthorizerFails(): void
    {
        // Replaced whole: setting only the rules would leave the default AllowAllToolAuthorizer.
        Configure::write('Mcp.servers.admin', [
            'route' => '/mcp/admin',
            'authorization' => ['rbac' => ['permissions' => [['role' => 'admin']]]],
        ]);

        $this->exec('mcp check --server admin');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Server admin: "Mcp.servers.admin.authorization.authorizer" must be set');
    }

    public function testScopesInHostModeWarn(): void
    {
        Configure::write('Mcp.auth.scopes', ['default' => ['admin']]);

        $this->exec('mcp check');

        $this->assertOutputContains('[WARN] Tool scopes: auth.scopes is set, but host mode does not check scopes');
    }

    public function testANamedServersAuthWithoutAModeFails(): void
    {
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'auth' => ['scopes' => ['default' => ['admin']]]]);

        $this->exec('mcp check --server admin');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Server admin: "Mcp.servers.admin.auth.mode" must be set');
    }

    public function testSharedExternalAudienceFails(): void
    {
        Cache::setConfig('mcp_check_cache', ['className' => ArrayEngine::class]);
        $auth = ['mode' => 'external', 'external' => [
            'issuer' => 'https://issuer.example',
            'audience' => 'api://mcp',
            'cache' => 'mcp_check_cache',
        ]];
        Configure::write('Mcp.auth', $auth);
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'auth' => $auth]);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('default and admin accept the audience api://mcp');
    }

    public function testServersLineRunsWithTheServerOption(): void
    {
        // Two servers on one route: whichever was connected last would win in silence.
        Configure::write('Mcp.servers.admin', ['route' => '/mcp']);

        $this->exec('mcp check --server admin');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Servers: default and admin share the route /mcp');
    }

    public function testServersWarnsWhenAScanDirectoryHoldsAnotherServers(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpNested']);
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'discovery' => ['scanDirs' => ['src/McpNested/Admin/']]]);

        $this->exec('mcp check');

        $this->assertOutputContains('[WARN] Servers: the scan directory src/McpNested of default holds');
        $this->assertOutputContains("src/McpNested/Admin/ of admin, so default also registers admin's tools");
        $this->assertOutputContains("'excludeDirs' => ['Admin']");
    }

    public function testServersPassesWhenTheNestedDirectoryIsExcluded(): void
    {
        Configure::write('Mcp.discovery', ['scanDirs' => ['src/McpNested'], 'excludeDirs' => ['Admin']]);
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'discovery' => ['scanDirs' => ['src/McpNested/Admin']]]);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Servers:');
    }

    public function testTheCatalogLineKeepsToolsWithAnAppOut(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpApps']);
        Configure::write('Mcp.apps.enabled', true);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['*']]);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Catalog: 0 tools behind find_tools and call_tools');
        $this->assertOutputContains('kept in tools/list because they link to an app, which the host reads from '
            . 'tools/list: show_inbox');
        $this->assertDoesNotMatchRegularExpression('/Catalog: [^\n]*inbox_rows/', $this->_out->output());
    }

    public function testToolScopesKnowCataloguedTools(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'oauth', 'scopes' => ['tools' => ['create_note' => ['notes:write']]]]);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['create_note', 'admin_only']]);

        $this->exec('mcp check');

        $this->assertOutputContains('Catalog: 2 tools behind find_tools and call_tools: admin_only, create_note');
        $this->assertOutputNotContains('names tools that do not exist');
        $this->assertOutputRegExp('/tools that need no scope, so any valid token may call them: [^\n]*admin_only/');
        $this->assertDoesNotMatchRegularExpression(
            '/tools that need no scope[^\n]*(find_tools|call_tools)/',
            $this->_out->output(),
        );
    }

    public function testToolScopesAndToolsKnowAppOnlyTools(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpApps']);
        Configure::write('Mcp.apps.enabled', true);
        Configure::write('Mcp.auth', ['mode' => 'oauth', 'scopes' => ['tools' => ['inbox_rows' => ['notes:read']]]]);

        $this->exec('mcp check');

        $this->assertOutputNotContains('names tools that do not exist');
        $this->assertOutputRegExp('/Tools: 2 tools: [^\n]*inbox_rows/');
    }

    public function testAppsEnabledByTheHookPass(): void
    {
        // A 2.1 application that served apps through the hook, without Mcp.apps.
        Configure::write('Mcp.discovery.scanDirs', ['src/McpApps']);
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->enableExtension(new McpApps());
        });

        $this->exec('mcp check');

        $this->assertOutputNotContains('[FAIL] Apps');
        $this->assertOutputContains('[PASS] Apps:');
    }

    public function testAnInheritedExternalAudienceFails(): void
    {
        Cache::setConfig('mcp_check_cache', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.auth', ['mode' => 'external', 'external' => [
            'issuer' => 'https://issuer.example',
            'audience' => 'api://mcp',
            'cache' => 'mcp_check_cache',
        ]]);
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin']);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Server admin: "Mcp.servers.admin.auth" must be set');
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

    public function testOneServerOutputIsUnchanged(): void
    {
        $this->exec('mcp check');

        $this->assertOutputNotContains('Server default');
        $this->assertOutputNotContains('Servers:');
    }

    public function testChecksEveryServer(): void
    {
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'discovery' => ['scanDirs' => ['src/McpDocs']]]);

        $this->exec('mcp check');

        $this->assertExitSuccess();
        $this->assertOutputContains('Server default');
        $this->assertOutputContains('Server admin');
        $this->assertOutputContains('[PASS] Endpoint: http://localhost/mcp/admin');
        $this->assertOutputRegExp('/\[PASS\] Tools: \d+ tools: .*find_order/');
        $this->assertOutputContains('[PASS] Servers: 2 servers: default, admin');
    }

    public function testCheckOneServer(): void
    {
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'discovery' => ['scanDirs' => ['src/McpDocs']]]);

        $this->exec('mcp check --server admin');

        $this->assertExitSuccess();
        $this->assertOutputContains('Server admin');
        $this->assertOutputNotContains('Server default');
        // The line across servers still runs: what another server shares concerns this one.
        $this->assertOutputContains('[PASS] Servers:');
    }

    public function testUnknownServerFails(): void
    {
        $this->exec('mcp check --server nope');

        $this->assertExitError();
        $this->assertErrorContains('"Mcp.servers.nope" is not configured.');
    }

    public function testSharedRouteFails(): void
    {
        Configure::write('Mcp.servers.copy', ['route' => '/mcp/']);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Servers: default and copy share the route /mcp');
    }

    public function testSharedResourceFails(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        Configure::write('Mcp.servers.admin', [
            'route' => '/mcp/admin',
            'auth' => ['resource' => OAuthTestConfig::MCP] + OAuthTestConfig::MCP_AUTH,
        ]);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('share the resource ' . OAuthTestConfig::MCP);
    }

    public function testToolSchemasCoverCataloguedTools(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpSchema']);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['report']]);

        $this->exec('mcp check');

        $this->assertOutputContains('[WARN] Tool schemas: report.date_from: type union null|string');
    }

    public function testAppsLine(): void
    {
        $this->exec('mcp check');
        $this->assertOutputContains('[PASS] Apps: off');

        Configure::write('Mcp.discovery.scanDirs', ['src/McpApps']);
        $this->exec('mcp check');
        $this->assertOutputContains('[FAIL] Apps: inbox_rows, show_inbox link to apps but Mcp.apps.enabled is off');

        Configure::write('Mcp.apps.enabled', true);
        $this->exec('mcp check');
        $this->assertOutputContains('[PASS] Apps: 2 app resources: ui://test/broken, ui://test/inbox');

        Configure::write('Mcp.servers.apps', [
            'route' => '/mcp/apps',
            'discovery' => ['scanDirs' => ['src/McpApps'], 'excludeDirs' => []],
            'apps' => ['enabled' => true],
        ]);
        Configure::write('Mcp.discovery.scanDirs', ['src/Mcp']);
        $this->exec('mcp check --server default');
        $this->assertOutputContains('[PASS] Apps: on, no app resources');
    }

    public function testAppsLineFailsOnAMissingResource(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpApps']);
        Configure::write('Mcp.discovery.excludeDirs', []);
        Configure::write('Mcp.apps.enabled', true);
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->addTool(fn(): string => 'x', 'orphan', meta: ['ui' => ['resourceUri' => 'ui://test/missing']]);
        });

        $this->exec('mcp check');

        $this->assertOutputContains('[FAIL] Apps: orphan links to ui://test/missing, which is not an app resource');
    }

    public function testCatalogLine(): void
    {
        $this->exec('mcp check');
        $this->assertOutputContains('[PASS] Catalog: off');

        Configure::write('Mcp.discovery.scanDirs', ['src/McpDocs']);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['order_*', 'find_order']]);
        $this->exec('mcp check');
        $this->assertOutputContains('[PASS] Catalog: 4 tools behind find_tools and call_tools: find_order, order_numbers');

        Configure::write('Mcp.catalog.tools', ['order_*', 'nope_*']);
        $this->exec('mcp check');
        $this->assertOutputContains('[WARN] Catalog: nope_* matches no tool');
    }

    public function testClientsLine(): void
    {
        $this->exec('mcp check');
        $this->assertOutputContains('[PASS] Clients: none');

        Configure::write('Mcp.clients', [
            'github' => ['url' => 'https://api.example.com/mcp', 'token' => 't'],
            'local' => ['command' => 'php', 'args' => ['server.php']],
            'linear' => ['url' => 'https://mcp.example.com/mcp', 'auth' => 'user'],
        ]);
        $this->exec('mcp check');
        $this->assertExitSuccess();
        $this->assertOutputContains(
            '[PASS] Clients: github (app), local (stdio), linear (user, connect at /mcp-client/linear/connect)',
        );
    }

    public function testClientsLineShowsTheProxyWithoutItsPassword(): void
    {
        Configure::write('Mcp.clients', ['github' => ['url' => 'https://api.example.com/mcp', 'token' => 't']]);
        Configure::write('Mcp.client.proxy', 'http://me:secret@proxy.corp.example:3128');

        $this->exec('mcp check');
        $this->assertOutputContains('[PASS] Clients: github (app); through the proxy http://proxy.corp.example:3128');
        $this->assertOutputNotContains('secret');

        Configure::write('Mcp.client.proxy', 'proxy.corp.example:3128');
        $this->exec('mcp check');
        $this->assertOutputContains('[FAIL] Clients: "Mcp.client.proxy" must be null or a proxy URL');
    }

    public function testClientsLineNeverCallsATokenCallable(): void
    {
        $called = false;
        Configure::write('Mcp.clients', ['vault' => [
            'url' => 'https://api.example.com/mcp',
            'token' => function () use (&$called): string {
                $called = true;

                return 't';
            },
        ]]);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Clients: vault (app, token from a callable)');
        $this->assertFalse($called);
    }

    public function testClientsLineFailsOnABadClient(): void
    {
        Configure::write('Mcp.clients', ['plain' => ['url' => 'http://mcp.example.com/mcp']]);

        $this->exec('mcp check');

        $this->assertExitError();
        $this->assertOutputContains('[FAIL] Clients: "Mcp.clients.plain.url" uses plain http');
        $this->assertOutputContains('docs/mcp-client.md');
    }

    public function testClientsLineFailsWithoutTheRoutes(): void
    {
        Configure::write('TestApp.mcpRoutes', false);
        Configure::write('Mcp.clients', ['linear' => ['url' => 'https://mcp.example.com/mcp', 'auth' => 'user']]);

        $this->exec('mcp check');

        $this->assertOutputContains('[FAIL] Clients: the connect and callback routes of user mode clients are not connected');
    }

    public function testClientsLineFailsWithoutTheTables(): void
    {
        Configure::write('Mcp.clients', ['linear' => ['url' => 'https://mcp.example.com/mcp', 'auth' => 'user']]);
        $db = ConnectionManager::get('test');
        $db->execute('ALTER TABLE mcp_client_registrations RENAME TO mcp_client_registrations_away');
        try {
            $this->exec('mcp check');
        } finally {
            $db->execute('ALTER TABLE mcp_client_registrations_away RENAME TO mcp_client_registrations');
        }

        $this->assertOutputContains('[FAIL] Clients: the table mcp_client_registrations is missing');
        $this->assertOutputContains('bin/cake migrations migrate -p CakeDC/Mcp');
    }

    public function testConditionsListSkippedElements(): void
    {
        $this->exec('mcp check');
        $this->assertOutputContains('[PASS] Conditions: nothing skipped');

        Configure::write('Mcp.discovery.scanDirs', ['src/McpWhen']);
        $this->exec('mcp check');
        $this->assertOutputContains('[PASS] Conditions: skipped: tool beta_tool (');
        $this->assertOutputContains('tool monthly_report (TestApp.reports is off)');
    }

    public function testCacheHintsOffPass(): void
    {
        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Cache hints: off (private, not cached)');
    }

    public function testPublicToolListWithAnAuthorizerWarns(): void
    {
        Configure::write('Mcp.authorization.authorizer', RbacToolAuthorizer::class);
        Configure::write('Mcp.server.cache', ['ttl' => 60000, 'scope' => 'public']);

        $this->exec('mcp check');

        $this->assertOutputContains('[WARN] Cache hints: tools/list is sent private');
        $this->assertOutputContains('resources/read is public');
    }

    public function testCacheHintsSayAFilteredToolListIsSentPrivate(): void
    {
        Configure::write('Mcp.authorization.authorizer', RbacToolAuthorizer::class);
        Configure::write('Mcp.server.cache', ['ttl' => 60000, 'scope' => 'public', 'methods' => [
            'resources/read' => ['scope' => 'private'],
        ]]);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Cache hints: ttl 60000 ms; tools/list is sent private');
    }

    public function testCacheHintsReadAPolicyTheHookSet(): void
    {
        // As the factory does: the hook's policy is the one the server sends.
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->setCachePolicy(CachePolicy::default(30000, CacheScope::Public));
        });

        $this->exec('mcp check');

        $this->assertOutputContains('[WARN] Cache hints:');
        $this->assertOutputContains('resources/read is public');
    }

    public function testPublicHintsOnlyForMethodsThatAreTheSameForEveryonePass(): void
    {
        Configure::write('Mcp.server.cache', ['ttl' => 60000, 'methods' => ['prompts/list' => ['scope' => 'public']]]);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Cache hints: ttl 60000 ms');
    }

    public function testDiscoveryPassesWithFinder(): void
    {
        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Discovery: scans src/Mcp');
    }

    public function testDiscoveryIsOffWithoutScanDirs(): void
    {
        Configure::write('Mcp.discovery.scanDirs', []);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Discovery: off (no Mcp.discovery.scanDirs)');
    }

    public function testDiscoveryFailsWithoutFinder(): void
    {
        $out = new StubConsoleOutput();
        $command = new class extends McpCheckCommand {
            protected function finderAvailable(): bool
            {
                return false;
            }
        };

        $code = $command->run([], new ConsoleIo($out, new StubConsoleOutput(), new StubConsoleInput([])));

        $this->assertSame(McpCheckCommand::CODE_ERROR, $code);
        $this->assertStringContainsString(
            '[FAIL] Discovery: Mcp.discovery.scanDirs is set but symfony/finder is not installed',
            $out->output(),
        );
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
