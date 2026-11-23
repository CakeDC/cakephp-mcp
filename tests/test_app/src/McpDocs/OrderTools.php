<?php
declare(strict_types=1);

namespace TestApp\McpDocs;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * The example of the Input schemas section of docs/tools.md, kept in step with it.
 */
class OrderTools
{
    /**
     * Orders in a date range.
     *
     * @param string|null $from First day, YYYY-MM-DD. Leave it out for 30 days ago.
     * @param string $status Order status.
     * @param int[] $facilities Facility ids. Leave it out for all.
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_orders')]
    public function list(
        #[Schema(format: 'date')]
        ?string $from = null,
        #[Schema(enum: ['open', 'closed'])]
        string $status = 'open',
        array $facilities = [],
    ): array {
        return ['from' => $from, 'status' => $status];
    }
}
