<?php
declare(strict_types=1);

namespace TestApp\McpAdmin;

use Mcp\Capability\Attribute\McpTool;

/**
 * The admin server of the "Several servers" example of docs/configuration.md, kept in step with it.
 */
class BackOfficeTools
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
