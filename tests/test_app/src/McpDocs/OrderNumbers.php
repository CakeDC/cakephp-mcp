<?php
declare(strict_types=1);

namespace TestApp\McpDocs;

use Mcp\Capability\Completion\ProviderInterface;

/**
 * Completes order numbers, as docs/resources-and-prompts.md shows.
 */
class OrderNumbers implements ProviderInterface
{
    /**
     * @param string $currentValue What the user typed so far.
     * @return list<string>
     */
    public function getCompletions(string $currentValue): array
    {
        $numbers = ['A-1001', 'A-1002', 'B-2001'];

        return array_values(array_filter($numbers, fn(string $number): bool => str_starts_with($number, $currentValue)));
    }
}
