<?php
declare(strict_types=1);

namespace TestApp\McpSchema;

use Mcp\Capability\Attribute\McpTool;

/**
 * A tool written the way the SDK's defaults make strict hosts complain: a nullable optional string and an
 * array documented as list<int>, which the SDK does not resolve. Its own directory keeps it out of the tool
 * lists of the other tests.
 */
class SchemaTools
{
    /**
     * Builds a report.
     *
     * @param string|null $date_from First day.
     * @param list<int> $statuses Status ids.
     * @param int[] $ids Ids.
     * @param string $field Date field.
     * @return array<string, mixed>
     */
    #[McpTool(name: 'report')]
    public function report(
        ?string $date_from = null,
        array $statuses = [],
        array $ids = [],
        string $field = 'signed',
    ): array {
        return ['date_from' => $date_from];
    }
}
