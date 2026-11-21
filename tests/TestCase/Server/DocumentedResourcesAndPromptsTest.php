<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Server;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use ReflectionClass;
use TestApp\McpDocs\ShopContent;

/**
 * What a client receives from the resources, templates, prompts and completions of
 * docs/resources-and-prompts.md (TestApp\McpDocs\ShopContent mirrors its examples).
 */
class DocumentedResourcesAndPromptsTest extends TestCase
{
    use McpTestTrait;

    private const PAGE = __DIR__ . '/../../../docs/resources-and-prompts.md';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpDocs']);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testTheExamplesAreThoseOfThePage(): void
    {
        $this->assertFileExists(self::PAGE);
        $page = (string)file_get_contents(self::PAGE);
        $source = (string)file_get_contents((string)(new ReflectionClass(ShopContent::class))->getFileName());
        foreach (['shop://policies/returns', 'shop://stats', 'shop://logo', 'shop://orders/{number}', 'summarize_order', 'describe_photo', 'transcribe_voicemail', 'OrderNumbers'] as $name) {
            $this->assertStringContainsString($name, $page, $name . ' is not on the page');
            $this->assertStringContainsString($name, $source . 'OrderNumbers', $name . ' is not in the example class');
        }
    }

    public function testATextResource(): void
    {
        $this->mcpReadResource('shop://policies/returns');

        $this->assertMcpResourceText("# Returns\n\nWithin 30 days, with the receipt.", 'text/markdown');
        $this->assertMcpResourceListed('shop://policies/returns');
    }

    public function testAnArrayIsSentAsJson(): void
    {
        $this->mcpReadResource('shop://stats');

        $this->assertMcpResourceText((string)json_encode(['orders' => 42, 'open' => 3], JSON_PRETTY_PRINT), 'application/json');
    }

    public function testABinaryResourceIsABlob(): void
    {
        $this->mcpReadResource('shop://logo');

        $contents = $this->mcpResponse()['result']['contents'][0] ?? [];
        $this->assertSame(ShopContent::PIXEL, $contents['blob'] ?? null);
        $this->assertSame('image/png', $contents['mimeType'] ?? null);
    }

    public function testATemplateAndItsCompletions(): void
    {
        $this->assertMcpResourceTemplateListed('shop://orders/{number}');
        $this->mcpReadResource('shop://orders/A-1001');
        $this->assertMcpResourceContains('"status": "shipped"');

        $this->mcpComplete(['type' => 'ref/resource', 'uri' => 'shop://orders/{number}'], 'number', 'A-');
        $this->assertMcpCompletionValues(['A-1001', 'A-1002']);
    }

    public function testAPromptWithAnEmbeddedResourceAndALink(): void
    {
        $this->mcpGetPrompt('summarize_order', ['number' => 'A-1001']);

        $messages = $this->mcpResponse()['result']['messages'] ?? [];
        $this->assertSame('Summarize order A-1001 for the customer.', $messages[0]['content']['text'] ?? null);
        $this->assertSame('resource', $messages[1]['content']['type'] ?? null);
        $this->assertSame('shop://orders/A-1001', $messages[1]['content']['resource']['uri'] ?? null);
        $this->assertSame('resource_link', $messages[2]['content']['type'] ?? null);
        $this->assertSame('shop://policies/returns', $messages[2]['content']['uri'] ?? null);

        $this->mcpComplete(['type' => 'ref/prompt', 'name' => 'summarize_order'], 'number', 'B');
        $this->assertMcpCompletionValues(['B-2001']);
    }

    public function testImageAndAudioPrompts(): void
    {
        $this->mcpGetPrompt('describe_photo');
        $this->assertSame(['type' => 'image', 'data' => ShopContent::PIXEL, 'mimeType' => 'image/png'], $this->mcpResponse()['result']['messages'][0]['content'] ?? null);

        $this->mcpGetPrompt('transcribe_voicemail', ['recording' => 'UklGRg==']);
        $this->assertSame('audio', $this->mcpResponse()['result']['messages'][0]['content']['type'] ?? null);
        $this->assertMcpPromptContains('Transcribe this voicemail.');
    }
}
