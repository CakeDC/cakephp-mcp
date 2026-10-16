<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\TestSuite;

use ArrayObject;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use CakeDC\OAuthServer\OAuthServer;
use PHPUnit\Framework\AssertionFailedError;
use TestApp\OAuthTestConfig;

class McpTestTraitScopesTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        OAuthServer::reset();
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
    }

    protected function tearDown(): void
    {
        OAuthServer::reset();
        parent::tearDown();
    }

    public function testEnoughScopesCallTheTool(): void
    {
        $this->mcpActingAs(new ArrayObject(['id' => 1]), ['notes:read', 'notes:write']);

        $this->mcpCallTool('create_note', ['title' => 'Groceries']);

        $this->assertMcpToolSucceeded();
    }

    public function testTheResourceImpliesCountInOAuthMode(): void
    {
        // Mcp.auth.scopes.implies is empty: notes:write implies notes:read in the OAuthServer resource, as the
        // authorization server expands it for the HTTP endpoint.
        $this->assertSame([], Configure::read('Mcp.auth.scopes.implies') ?? []);
        $this->mcpActingAs(new ArrayObject(['id' => 1]), ['notes:write']);

        $this->mcpCallTool('create_note', ['title' => 'Groceries']);

        $this->assertMcpToolSucceeded();
    }

    public function testExternalModeUsesTheConfiguredImplies(): void
    {
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            'external' => ['issuer' => 'https://issuer.example'],
            'scopes' => OAuthTestConfig::MCP_AUTH['scopes'],
        ]);
        $this->mcpActingAs(new ArrayObject(['id' => 1]), ['notes:write']);

        $this->mcpCallTool('create_note', ['title' => 'Groceries']);
        $this->assertMcpInsufficientScope(['notes:read', 'notes:write']);

        Configure::write('Mcp.auth.scopes.implies', ['notes:write' => ['notes:read']]);
        $this->mcpCallTool('create_note', ['title' => 'Groceries']);
        $this->assertMcpToolSucceeded();
    }

    public function testARefusedRequestOtherThanAToolCallFailsTheTest(): void
    {
        Configure::write('Mcp.auth.scopes.methods', ['tools/list' => ['admin']]);
        $this->mcpActingAs(new ArrayObject(['id' => 1]), ['notes:read']);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The access token lacks scopes for `tools/list`');
        $this->mcpListTools();
    }

    public function testMissingScopesAreReportedLikeTheEndpointWould(): void
    {
        $this->mcpActingAs(new ArrayObject(['id' => 1]), ['notes:read']);

        $this->mcpCallTool('create_note', ['title' => 'Groceries']);

        $this->assertMcpInsufficientScope(['notes:read', 'notes:write']);
    }

    public function testInsufficientScopeAssertionFailsOnSuccess(): void
    {
        $this->mcpActingAs(new ArrayObject(['id' => 1]), ['notes:read', 'notes:write']);
        $this->mcpCallTool('create_note', ['title' => 'Groceries']);

        $this->expectException(AssertionFailedError::class);
        $this->assertMcpInsufficientScope();
    }

    public function testWithoutScopesThePolicyIsNotApplied(): void
    {
        $this->mcpActingAs(new ArrayObject(['id' => 1]));

        $this->mcpCallTool('create_note', ['title' => 'Groceries']);

        $this->assertMcpToolSucceeded();
    }

    public function testHostModeIgnoresThePolicy(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'host']);
        $this->mcpActingAs(new ArrayObject(['id' => 1]), []);

        $this->mcpCallTool('create_note', ['title' => 'Groceries']);

        $this->assertMcpToolSucceeded();
    }

    public function testClaimsReachTheAccessToken(): void
    {
        $this->mcpActingAs(new ArrayObject(['id' => 1]), ['notes:read'], ['sub' => 'from-claims']);

        $this->mcpCallTool('whoami');

        $this->assertMcpResultContains('from-claims');
    }
}
