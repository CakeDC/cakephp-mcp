<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use InvalidArgumentException;
use Psr\Log\NullLogger;

class McpCheckCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // CakePHP 4 runs exec() through the legacy shell dispatcher unless told otherwise.
        $this->useCommandRunner();
    }

    protected function tearDown(): void
    {
        Router::reload();
        foreach (['skeleton_debug', 'skeleton_error', 'mcp_test', 'audit_check', 'object_log', 'psr_log'] as $name) {
            if (in_array($name, Log::configured(), true)) {
                Log::drop($name);
            }
        }
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
        // The CakePHP 4 skeleton: `false` takes only messages without a scope.
        Log::setConfig('skeleton_debug', ['className' => ArrayLog::class, 'scopes' => false, 'levels' => ['notice', 'info', 'debug']]);
        Log::setConfig('skeleton_error', ['className' => ArrayLog::class, 'scopes' => false, 'levels' => ['warning', 'error', 'critical', 'alert', 'emergency']]);
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
     * @dataProvider toolRules
     */
    public function testRbacWithARuleForTheToolsPasses(array $rule): void
    {
        $this->rbac([$rule]);

        $this->exec('mcp check');

        $this->assertOutputContains('[PASS] Authorizer: RbacToolAuthorizer, 1 of 1 rules for the tools');
    }

    /**
     * The oauth and external modes of 2.x are not in the 1.x line: the plugin bootstrap refuses them,
     * so `mcp check` (as every command) stops with the reason instead of reporting an endpoint that
     * would run without the authentication they promise.
     *
     * @return array<string, array{string}>
     */
    public static function unavailableModes(): array
    {
        return ['oauth' => ['oauth'], 'external' => ['external']];
    }

    /**
     * @dataProvider unavailableModes
     */
    public function testUnavailableAuthModeStopsTheCommand(string $mode): void
    {
        Configure::write('Mcp.auth.mode', $mode);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"Mcp.auth.mode" "%s" is not available', $mode));
        $this->exec('mcp check');
    }
}
