<?php
declare(strict_types=1);

namespace TestApp\McpDocs;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextResourceContents;

/**
 * The examples of docs/resources-and-prompts.md, kept in step with it.
 */
class ShopContent
{
    /**
     * A 1x1 transparent PNG, base64.
     */
    public const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /**
     * How returns work.
     */
    #[McpResource(uri: 'shop://policies/returns', name: 'return_policy', mimeType: 'text/markdown')]
    public function returnPolicy(): string
    {
        return "# Returns\n\nWithin 30 days, with the receipt.";
    }

    /**
     * Today's numbers.
     *
     * @return array<string, int>
     */
    #[McpResource(uri: 'shop://stats', name: 'shop_stats', mimeType: 'application/json')]
    public function stats(): array
    {
        return ['orders' => 42, 'open' => 3];
    }

    /**
     * The shop's logo.
     *
     * @return array{blob: string, mimeType: string}
     */
    #[McpResource(uri: 'shop://logo', name: 'logo', mimeType: 'image/png')]
    public function logo(): array
    {
        return ['blob' => self::PIXEL, 'mimeType' => 'image/png'];
    }

    /**
     * An order by its number.
     *
     * @param string $number The order number.
     * @return array<string, mixed>
     */
    #[McpResourceTemplate(uriTemplate: 'shop://orders/{number}', name: 'order', mimeType: 'application/json')]
    public function order(
        #[CompletionProvider(provider: OrderNumbers::class)]
        string $number,
    ): array {
        return ['number' => $number, 'status' => 'shipped'];
    }

    /**
     * Writes a summary of an order for the customer.
     *
     * @param string $number The order number.
     * @return list<array{role: string, content: mixed}>
     */
    #[McpPrompt(name: 'summarize_order')]
    public function summarizeOrder(
        #[CompletionProvider(provider: OrderNumbers::class)]
        string $number,
    ): array {
        $order = (string)json_encode($this->order($number));

        return [
            ['role' => 'user', 'content' => 'Summarize order ' . $number . ' for the customer.'],
            ['role' => 'user', 'content' => new EmbeddedResource(
                new TextResourceContents('shop://orders/' . $number, 'application/json', $order),
            )],
            ['role' => 'user', 'content' => new ResourceLink(
                'shop://policies/returns',
                'return_policy',
                mimeType: 'text/markdown',
            )],
        ];
    }

    /**
     * Describes a product photo.
     *
     * @return list<array{role: string, content: mixed}>
     */
    #[McpPrompt(name: 'describe_photo')]
    public function describePhoto(): array
    {
        return [
            ['role' => 'user', 'content' => new ImageContent(self::PIXEL, 'image/png')],
            ['role' => 'user', 'content' => 'Describe this product photo in one sentence.'],
        ];
    }

    /**
     * Transcribes a voicemail.
     *
     * @param string $recording The recording, base64 WAV.
     * @return list<array{role: string, content: mixed}>
     */
    #[McpPrompt(name: 'transcribe_voicemail')]
    public function transcribeVoicemail(string $recording): array
    {
        return [
            ['role' => 'user', 'content' => new AudioContent($recording, 'audio/wav')],
            ['role' => 'user', 'content' => 'Transcribe this voicemail.'],
        ];
    }
}
