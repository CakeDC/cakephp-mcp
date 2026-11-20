<?php
declare(strict_types=1);

namespace TestApp\McpDocs;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * The examples of the Results section of docs/tools.md, kept in step with it.
 */
class ResultTools
{
    private const SUMMARY_SCHEMA = [
        'type' => 'object',
        'properties' => ['total' => ['type' => 'number'], 'count' => ['type' => 'integer']],
        'required' => ['total'],
    ];

    /**
     * Finds an order by its number.
     *
     * @param string $number The order number.
     * @return array<string, mixed>
     */
    #[McpTool(name: 'find_order')]
    public function find(string $number): array
    {
        return ['number' => $number, 'status' => 'open', 'total' => 12.5];
    }

    /**
     * The numbers of the open orders.
     *
     * @return list<string>
     */
    #[McpTool(name: 'order_numbers')]
    public function numbers(): array
    {
        return ['A-1', 'A-2'];
    }

    /**
     * The status of an order.
     *
     * @param string $number The order number.
     */
    #[McpTool(name: 'order_status')]
    public function status(string $number): string
    {
        return 'open';
    }

    /**
     * Totals of the open orders.
     */
    #[McpTool(name: 'order_summary', outputSchema: self::SUMMARY_SCHEMA)]
    public function summary(): CallToolResult
    {
        return new CallToolResult(
            [new TextContent('2 open orders, 25.50 in total.')],
            false,
            ['total' => 25.5, 'count' => 2],
        );
    }

    /**
     * The open orders, as one compact JSON text.
     */
    #[McpTool(name: 'orders_compact')]
    public function compact(): CallToolResult
    {
        $data = ['orders' => [['number' => 'A-1', 'total' => 12.5], ['number' => 'A-2', 'total' => 12.5]]];

        return new CallToolResult([
            new TextContent(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * A summary that breaks its own output schema (no total).
     */
    #[McpTool(name: 'bad_summary', outputSchema: self::SUMMARY_SCHEMA)]
    public function bad(): CallToolResult
    {
        return new CallToolResult([new TextContent('2 open orders.')], false, ['count' => 2]);
    }
}
