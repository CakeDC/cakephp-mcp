<?php
declare(strict_types=1);

namespace TestApp\McpContent;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use RuntimeException;

/**
 * Resources, a resource template and prompts for the tests of McpTestTrait and of their authorization.
 */
class ContentElements
{
    /**
     * The house rules.
     */
    #[McpResource(uri: 'app://rules', name: 'rules', mimeType: 'text/markdown')]
    public function rules(): string
    {
        return '# House rules';
    }

    /**
     * The salaries, for the tests of resource authorization.
     */
    #[McpResource(uri: 'app://salaries', name: 'salaries', mimeType: 'application/json')]
    public function salaries(): string
    {
        return '{"total": 100}';
    }

    /**
     * A resource that fails.
     */
    #[McpResource(uri: 'app://broken', name: 'broken', mimeType: 'text/plain')]
    public function broken(): string
    {
        throw new RuntimeException('The disk is gone.');
    }

    /**
     * An order by its number.
     *
     * @param string $number The order number.
     */
    #[McpResourceTemplate(uriTemplate: 'orders://{number}', name: 'order', mimeType: 'text/plain')]
    public function order(
        #[CompletionProvider(values: ['1001', '1002', '2001'])]
        string $number,
    ): string {
        return 'Order ' . $number;
    }

    /**
     * Greets someone.
     *
     * @param string $name Who to greet.
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(name: 'greet')]
    public function greet(
        #[CompletionProvider(values: ['Ada', 'Alan', 'Grace'])]
        string $name,
    ): array {
        return [
            ['role' => 'user', 'content' => 'Say hello to ' . $name . '.'],
            ['role' => 'assistant', 'content' => 'Hello, ' . $name . '!'],
        ];
    }

    /**
     * The payroll prompt, for the tests of prompt authorization.
     *
     * @return list<array{role: string, content: string}>
     */
    #[McpPrompt(name: 'payroll')]
    public function payroll(): array
    {
        return [['role' => 'user', 'content' => 'Run the payroll.']];
    }
}
