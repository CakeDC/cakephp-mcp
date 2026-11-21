<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\TestSuite;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The McpTestTrait helpers for prompts, resources, templates and completions.
 */
class McpTestTraitContentTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpContent']);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testGetsAPromptAndAssertsItsMessages(): void
    {
        $response = $this->mcpGetPrompt('greet', ['name' => 'Ada']);

        $this->assertArrayHasKey('result', $response);
        $this->assertMcpPromptMessages([
            ['role' => 'user', 'text' => 'Say hello to Ada.'],
            ['role' => 'assistant', 'text' => 'Hello, Ada!'],
        ]);
        $this->assertMcpPromptContains('Hello, Ada');
    }

    public function testPromptMessagesFailOnADifferentText(): void
    {
        $this->mcpGetPrompt('greet', ['name' => 'Ada']);

        $this->expectException(AssertionFailedError::class);
        $this->assertMcpPromptMessages([['role' => 'user', 'text' => 'Say hello to Ada.']]);
    }

    public function testAnUnknownPromptIsAPromptError(): void
    {
        $this->mcpGetPrompt('nope');

        $this->assertMcpPromptError('nope');
    }

    public function testPromptErrorFailsOnASuccess(): void
    {
        $this->mcpGetPrompt('greet', ['name' => 'Ada']);

        $this->expectException(AssertionFailedError::class);
        $this->assertMcpPromptError();
    }

    public function testReadsAResourceWithItsMimeType(): void
    {
        $this->mcpReadResource('app://rules');

        $this->assertMcpResourceText('# House rules');
        $this->assertMcpResourceText('# House rules', 'text/markdown');
        $this->assertMcpResourceContains('House');
    }

    public function testResourceTextFailsOnAnotherMimeType(): void
    {
        $this->mcpReadResource('app://rules');

        $this->expectException(AssertionFailedError::class);
        $this->assertMcpResourceText('# House rules', 'text/plain');
    }

    public function testReadsAResourceThroughATemplate(): void
    {
        $this->mcpReadResource('orders://1002');

        $this->assertMcpResourceText('Order 1002', 'text/plain');
    }

    public function testAResourceThatFailsOrIsUnknownIsAResourceError(): void
    {
        $this->mcpReadResource('app://broken');
        // The SDK hides the exception's message from the client.
        $this->assertMcpResourceError('Error while reading resource');

        $this->mcpReadResource('app://nothing-here');
        $this->assertMcpResourceError();
    }

    public function testResourceErrorFailsOnASuccess(): void
    {
        $this->mcpReadResource('app://rules');

        $this->expectException(AssertionFailedError::class);
        $this->assertMcpResourceError();
    }

    public function testCompletesAPromptArgument(): void
    {
        $this->mcpComplete(['type' => 'ref/prompt', 'name' => 'greet'], 'name', 'A');

        $this->assertMcpCompletionValues(['Ada', 'Alan']);
    }

    public function testCompletesATemplateVariable(): void
    {
        $this->mcpComplete(['type' => 'ref/resource', 'uri' => 'orders://{number}'], 'number', '10');

        $this->assertMcpCompletionValues(['1001', '1002']);
    }

    public function testCompletionValuesFailOnOtherValues(): void
    {
        $this->mcpComplete(['type' => 'ref/prompt', 'name' => 'greet'], 'name', 'A');

        $this->expectException(AssertionFailedError::class);
        $this->assertMcpCompletionValues(['Ada']);
    }

    public function testListsResourcesTemplatesAndPrompts(): void
    {
        $this->assertContains('app://rules', array_column($this->mcpListResources(), 'uri'));
        $this->assertSame(['orders://{number}'], array_column($this->mcpListResourceTemplates(), 'uriTemplate'));
        $this->assertContains('greet', array_column($this->mcpListPrompts(), 'name'));

        $this->assertMcpResourceListed('app://rules');
        $this->assertMcpResourceTemplateListed('orders://{number}');
        $this->assertMcpPromptListed('greet');
    }

    public function testListedFailsForWhatIsNotListed(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertMcpResourceListed('app://nothing-here');
    }

    public function testNotListedFailsForWhatIsListed(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->assertMcpPromptNotListed('greet');
    }

    public function testNotListedFailsForWhatDoesNotExist(): void
    {
        // A typo must not pass as hidden.
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not registered');
        $this->assertMcpResourceNotListed('app://typo');
    }

    public function testPromptNotListedFailsForWhatDoesNotExist(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not registered');
        $this->assertMcpPromptNotListed('typo');
    }

    public function testTemplateNotListedFailsForWhatDoesNotExist(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not registered');
        $this->assertMcpResourceTemplateNotListed('typo://{x}');
    }

    public function testRunsOnTheCurrentServer(): void
    {
        Configure::write('Mcp.servers', ['content' => ['route' => '/mcp/content']]);
        Configure::write('Mcp.discovery.scanDirs', ['src/Mcp']);
        Configure::write('Mcp.servers.content.discovery', ['scanDirs' => ['src/McpContent']]);

        $this->assertSame([], $this->mcpListPrompts());

        $this->mcpUseServer('content');
        $this->assertMcpPromptListed('greet');
    }
}
