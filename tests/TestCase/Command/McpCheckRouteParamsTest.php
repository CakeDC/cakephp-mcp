<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Command;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\Mcp\Test\Support\TestJwt;
use CakeDC\OAuthServer\OAuthServer;
use TestApp\OAuthTestConfig;

/**
 * `mcp check` on a server whose route has parameters probes a real value of it, never the pattern: in oauth mode
 * one whose resource is declared, otherwise a sample slug.
 */
class McpCheckRouteParamsTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = OAuthFixtures::ALL;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('check_params', ['className' => ArrayEngine::class]);
        OAuthServer::reset();
        Configure::write('Mcp.servers.tenants', [
            'route' => '/mcp/t/{tenant}',
            'discovery' => ['scanDirs' => ['src/McpTenant']],
        ]);
    }

    protected function tearDown(): void
    {
        OAuthServer::reset();
        Cache::drop('check_params');
        Router::reload();
        parent::tearDown();
    }

    /**
     * @param list<string> $values The tenants whose resource is declared.
     * @return void
     */
    private function oauth(array $values): void
    {
        $resources = (array)Configure::read('OAuthServer.resources');
        foreach ($values as $value) {
            $resources['http://localhost/mcp/t/' . $value] = $resources[OAuthTestConfig::MCP];
        }
        Configure::write('OAuthServer.resources', $resources);
        OAuthServer::reset();
        Configure::write('Mcp.servers.tenants.auth', ['mode' => 'oauth']);
    }

    public function testHostModePasses(): void
    {
        $this->exec('mcp check --server tenants');

        $this->assertOutputNotContains('[FAIL]');
        $this->assertOutputContains('[PASS] CSRF');
        $this->assertExitSuccess();
    }

    public function testExternalModeChecksTheMetadataRouteOfAValue(): void
    {
        Configure::write('Mcp.servers.tenants.auth', [
            'mode' => 'external',
            'external' => [
                'issuer' => TestJwt::ISSUER,
                'cache' => 'check_params',
                'httpClient' => TestJwt::client(),
                'audience' => 'api://mcp/{tenant}',
            ],
        ]);

        $this->exec('mcp check --server tenants');

        $this->assertOutputNotContains('[FAIL]');
        $this->assertOutputNotContains('subdirectory install');
        $this->assertOutputContains('[PASS] Metadata route: /.well-known/oauth-protected-resource/mcp/t/sample');
        $this->assertExitSuccess();
    }

    public function testOAuthModeChecksADeclaredValue(): void
    {
        $this->oauth(['acme', 'globex']);

        $this->exec('mcp check --server tenants');

        $this->assertOutputNotContains('[FAIL]');
        $this->assertOutputContains('[PASS] Resource: http://localhost/mcp/t/acme');
        $this->assertOutputContains('2 declared resources fit http://localhost/mcp/t/{tenant}');
        $this->assertOutputContains('[PASS] Metadata route: /.well-known/oauth-protected-resource/mcp/t/acme');
        $this->assertOutputContains('[PASS] Authenticators');
        $this->assertExitSuccess();
    }

    public function testTwoServersOnTheSamePatternFail(): void
    {
        Configure::write('Mcp.servers.partners', [
            'route' => '/mcp/t/{partner}',
            'discovery' => ['scanDirs' => ['src/McpTenant']],
        ]);

        $this->exec('mcp check');

        $this->assertOutputContains('[FAIL] Servers: tenants and partners share the route /mcp/t/{tenant}');
        $this->assertExitError();
    }

    public function testAFixedRouteThatFitsAPatternFails(): void
    {
        Configure::write('Mcp.servers.admin', [
            'route' => '/mcp/t/admin',
            'discovery' => ['scanDirs' => ['src/McpAdmin']],
        ]);

        $this->exec('mcp check');

        $this->assertOutputContains(
            '[FAIL] Servers: the route /mcp/t/admin of admin fits /mcp/t/{tenant} of tenants: the router sends it '
            . 'to admin, so tenants never serves the value admin',
        );
        $this->assertExitError();
    }

    public function testOAuthModeFailsWhenNoValueIsDeclared(): void
    {
        $this->oauth([]);

        $this->exec('mcp check --server tenants');

        $this->assertOutputContains(
            '[FAIL] Resource: no resource in OAuthServer.resources fits http://localhost/mcp/t/{tenant}',
        );
        $this->assertExitError();
    }
}
