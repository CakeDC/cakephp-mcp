<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\TestSuite;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
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

    /**
     * A tool whose structured content has nested objects, lists, a null and an empty list.
     */
    private function report(): void
    {
        Configure::write('Mcp.discovery.scanDirs', []);
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $data = [
                'order' => [
                    'id' => 7,
                    'note' => null,
                    'tags' => ['b', 'a'],
                    'lines' => [['sku' => 'X', 'qty' => 2, 'tags' => [3, 1]], ['sku' => 'Y', 'qty' => 1, 'tags' => []]],
                ],
                'total' => 12.5,
                'counts' => [1, 1, 2],
                'empty' => [],
            ];
            $builder->addTool(fn(): CallToolResult => new CallToolResult([new TextContent('report')], false, $data), name: 'report');
        });
        $this->mcpCallTool('report');
        $this->assertMcpToolSucceeded();
    }

    public function testStructuredContentMissing(): void
    {
        $this->report();

        $this->assertMcpStructuredContentMissing('order.discount');
        $this->assertMcpStructuredContentMissing(['order.discount', 'order.lines.2', 'shipping']);
        // A key that is there with a null value is not missing (assertMcpStructuredContentHas passes on it).
        $this->expectFailure(fn() => $this->assertMcpStructuredContentMissing('order.note'));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentMissing(['shipping', 'total']));
    }

    public function testStructuredContentNotSubsetAndLacks(): void
    {
        $this->report();

        // The negation of assertMcpStructuredContentSubset, with its rule: anchored at the top.
        $this->assertMcpStructuredContentNotSubset(['total' => 13]);
        $this->assertMcpStructuredContentNotSubset(['sku' => 'X']);
        $this->expectFailure(fn() => $this->assertMcpStructuredContentNotSubset(['total' => 12.5]));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentNotSubset(['order' => ['id' => 7]]));
        // None of these pairs, at any depth.
        $this->assertMcpStructuredContentLacks(['sku' => 'Z']);
        $this->assertMcpStructuredContentLacks(['qty' => '2']);
        $this->expectFailure(fn() => $this->assertMcpStructuredContentLacks(['sku' => 'Y']));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentLacks(['sku' => 'Z', 'qty' => 2]));
    }

    public function testKeyOrderNeverDecides(): void
    {
        $this->report();

        // The tool wrote sku before qty: the same objects written in another key order are found.
        $this->expectFailure(fn() => $this->assertMcpStructuredContentLacks([
            'lines' => [['tags' => [3, 1], 'qty' => 2, 'sku' => 'X'], ['tags' => [], 'qty' => 1, 'sku' => 'Y']],
        ]));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentNotSubset(['order' => [
            'lines' => [['qty' => 2, 'tags' => [3, 1], 'sku' => 'X'], ['qty' => 1, 'sku' => 'Y', 'tags' => []]],
        ]]));
        $this->assertMcpStructuredContentSubset(['order' => [
            'lines' => [['qty' => 2, 'tags' => [3, 1], 'sku' => 'X'], ['qty' => 1, 'sku' => 'Y', 'tags' => []]],
        ]]);
        // List order still counts for these.
        $this->expectFailure(fn() => $this->assertMcpStructuredContentSubset(['order' => ['tags' => ['a', 'b']]]));
    }

    public function testStructuredContentIgnoringOrder(): void
    {
        $this->report();

        $this->assertMcpStructuredContentIgnoringOrder([
            'empty' => [],
            'counts' => [2, 1, 1],
            'total' => 12.5,
            'order' => [
                'lines' => [['sku' => 'Y', 'qty' => 1, 'tags' => []], ['tags' => [1, 3], 'sku' => 'X', 'qty' => 2]],
                'tags' => ['a', 'b'],
                'note' => null,
                'id' => 7,
            ],
        ]);
        $this->assertMcpStructuredContentIgnoringOrder(['a', 'b'], 'order.tags');
        $this->assertMcpStructuredContentIgnoringOrder([1, 3], 'order.lines.0.tags');
        // Duplicates count, types matter, and a missing path fails.
        $this->expectFailure(fn() => $this->assertMcpStructuredContentIgnoringOrder([1, 2, 2], 'counts'));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentIgnoringOrder(['1', 1, 2], 'counts'));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentIgnoringOrder(['a'], 'order.tags'));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentIgnoringOrder([], 'order.missing'));
    }

    public function testStructuredContentKeys(): void
    {
        $this->report();

        $shape = ['order' => ['id', 'lines' => ['*' => ['sku']]], 'total'];
        $this->assertMcpStructuredContentKeys($shape);
        // Exact: no other key at a described level.
        $this->expectFailure(fn() => $this->assertMcpStructuredContentKeys($shape, true));
        $this->assertMcpStructuredContentKeys([
            'order' => ['id', 'note', 'tags', 'lines' => ['*' => ['sku', 'qty', 'tags']]],
            'total',
            'counts',
            'empty',
        ], true);
        $this->expectFailure(fn() => $this->assertMcpStructuredContentKeys(['order' => ['discount']]));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentKeys(['order' => ['lines' => ['*' => ['price']]]]));
        // A wildcard over an empty list checks nothing, so it fails.
        $this->expectFailure(fn() => $this->assertMcpStructuredContentKeys(['empty' => ['*' => ['a']]]));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentKeys(['total' => ['*' => ['a']]]));
    }

    public function testStructuredContentPathWithAClosure(): void
    {
        $this->report();
        $called = false;

        $this->assertMcpStructuredContentPath('order.id', fn(mixed $id): bool => $id > 5);
        $this->assertMcpStructuredContentPath('order.lines', fn(array $lines): bool => count($lines) === 2);
        // Strictly true: 1 is not.
        $this->expectFailure(fn() => $this->assertMcpStructuredContentPath('order.id', fn(): int => 1));
        $this->expectFailure(fn() => $this->assertMcpStructuredContentPath('order.id', fn(): bool => false));
        $this->expectFailure(function () use (&$called): void {
            $this->assertMcpStructuredContentPath('order.missing', function () use (&$called): bool {
                $called = true;

                return true;
            });
        });
        $this->assertFalse($called, 'The closure was called for a path that does not exist.');
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
