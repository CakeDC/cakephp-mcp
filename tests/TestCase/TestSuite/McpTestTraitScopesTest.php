<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\TestSuite;

use ArrayObject;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use PHPUnit\Framework\AssertionFailedError;
use TestApp\OAuthTestConfig;

class McpTestTraitScopesTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
    }

    public function testEnoughScopesCallTheTool(): void
    {
        $this->mcpActingAs(new ArrayObject(['id' => 1]), ['notes:read', 'notes:write']);

        $this->mcpCallTool('create_note', ['title' => 'Groceries']);

        $this->assertMcpToolSucceeded();
    }

    public function testImpliedScopesCount(): void
    {
        Configure::write('Mcp.auth.scopes.implies', ['notes:write' => ['notes:read']]);
        $this->mcpActingAs(new ArrayObject(['id' => 1]), ['notes:write']);

        $this->mcpCallTool('create_note', ['title' => 'Groceries']);

        $this->assertMcpToolSucceeded();
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
