<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\TestSuite;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use PHPUnit\Framework\AssertionFailedError;
use TestApp\ContentAuthorizer;

/**
 * More assertions: on structuredContent (subset, count, type at a path), on completions, and on what another
 * identity lists.
 */
class McpTestTraitAssertionsTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpDocs', 'src/McpContent']);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    private function expectFailure(callable $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The assertion passed.');
    }

    public function testStructuredContentSubset(): void
    {
        $this->mcpCallTool('find_order', ['number' => 'A-1']);

        $this->assertMcpStructuredContentSubset(['status' => 'open']);
        $this->assertMcpStructuredContentSubset(['number' => 'A-1', 'total' => 12.5]);
        $this->expectFailure(fn() => $this->assertMcpStructuredContentSubset(['status' => 'closed']));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentSubset(['missing' => null]));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentSubset(['total' => '12.5']));
    }

    public function testStructuredContentCount(): void
    {
        $this->mcpCallTool('find_order', ['number' => 'A-1']);

        $this->assertMcpStructuredContentCount('', 3);
        $this->expectFailure(fn() => $this->assertMcpStructuredContentCount('', 2));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentCount('status', 1));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentCount('nope', 0));
    }

    public function testStructuredContentType(): void
    {
        $this->mcpCallTool('find_order', ['number' => 'A-1']);

        $this->assertMcpStructuredContentType('number', 'string');
        $this->assertMcpStructuredContentType('total', 'number');
        $this->assertMcpStructuredContentType('', 'object');
        $this->expectFailure(fn() => $this->assertMcpStructuredContentType('total', 'integer'));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentType('nope', 'null'));
    }

    public function testCompletionContains(): void
    {
        $this->mcpComplete(['type' => 'ref/prompt', 'name' => 'greet'], 'name', 'A');

        $this->assertMcpCompletionContains('Ada');
        $this->expectFailure(fn() => $this->assertMcpCompletionContains('Grace'));
    }

    public function testListedForAnotherIdentity(): void
    {
        Configure::write('Mcp.authorization.authorizer', ContentAuthorizer::class);
        $this->mcpActingAs(['id' => 1, 'role' => 'user']);

        $this->assertMcpPromptListedFor(['id' => 1, 'role' => 'user'], 'greet');
        $this->assertMcpPromptNotListedFor(['id' => 2, 'role' => 'guest'], 'greet');
        $this->assertMcpResourceListedFor(['id' => 1, 'role' => 'user'], 'app://rules');
        $this->assertMcpResourceNotListedFor(['id' => 1, 'role' => 'user'], 'app://salaries');
        $this->expectFailure(fn() => $this->assertMcpPromptListedFor(['id' => 2, 'role' => 'guest'], 'greet'));
        // The test's own identity stays.
        $this->assertSame(['id' => 1, 'role' => 'user'], $this->_mcpIdentity);
    }
}
