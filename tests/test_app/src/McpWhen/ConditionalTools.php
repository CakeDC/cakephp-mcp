<?php
declare(strict_types=1);

namespace TestApp\McpWhen;

use CakeDC\Mcp\Attribute\McpWhen;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;

/**
 * Elements registered only when `TestApp.reports`, `TestApp.guides` or `TestApp.prompts` is on.
 */
class ConditionalTools
{
    /**
     * The monthly report.
     */
    #[McpWhen(config: 'TestApp.reports')]
    #[McpTool(name: 'monthly_report')]
    public function report(): string
    {
        return 'report';
    }

    /**
     * Always registered.
     */
    #[McpTool(name: 'always')]
    public function always(): string
    {
        return 'always';
    }

    /**
     * The guide.
     */
    #[McpWhen(config: 'TestApp.guides')]
    #[McpResource(uri: 'app://guide', name: 'guide', mimeType: 'text/plain')]
    public function guide(): string
    {
        return 'guide';
    }

    /**
     * The weekly prompt.
     *
     * @return list<array{role: string, content: string}>
     */
    #[McpWhen(config: 'TestApp.prompts')]
    #[McpPrompt(name: 'weekly')]
    public function weekly(): array
    {
        return [['role' => 'user', 'content' => 'Weekly.']];
    }
}
