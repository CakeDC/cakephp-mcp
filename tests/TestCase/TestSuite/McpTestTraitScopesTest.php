<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\TestSuite;

use ArrayObject;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;

/**
 * The scopes and claims given to mcpActingAs(). The 1.x line has no scope policy (it comes with the
 * oauth and external modes of 2.x), so they only reach the access token the tools see.
 */
class McpTestTraitScopesTest extends TestCase
{
    use McpTestTrait;

    public function testWithoutScopesTheToolIsCalled(): void
    {
        $this->mcpActingAs(new ArrayObject(['id' => 1]));

        $this->mcpCallTool('create_note', ['title' => 'Groceries']);

        $this->assertMcpToolSucceeded();
    }

    public function testScopesDoNotRefuseACall(): void
    {
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
