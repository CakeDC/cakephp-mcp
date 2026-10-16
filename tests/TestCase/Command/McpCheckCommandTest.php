<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
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
}
