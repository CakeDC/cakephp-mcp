<?php
declare(strict_types=1);

namespace TestApp\McpNested\Admin;

use Mcp\Capability\Attribute\McpTool;

/**
 * A back office tool inside the directory of another server.
 */
class RefundTools
{
    /**
     * Refunds an order.
     *
     * @param string $number The order number.
     */
    #[McpTool(name: 'refund_order')]
    public function refund(string $number): string
    {
        return 'refunded ' . $number;
    }
}
