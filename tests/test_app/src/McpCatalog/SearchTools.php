<?php
declare(strict_types=1);

namespace TestApp\McpCatalog;

use Mcp\Capability\Attribute\McpTool;

/**
 * Tools for the find_tools search tests: words outside ASCII, and words only in the input schema.
 */
class SearchTools
{
    /**
     * Busca facturas por cliente y año.
     *
     * @param string $cliente El cliente.
     */
    #[McpTool(name: 'buscar_facturas')]
    public function facturas(string $cliente): string
    {
        return $cliente;
    }

    /**
     * Lists the orders.
     */
    #[McpTool(name: 'list_orders')]
    public function orders(): string
    {
        return 'orders';
    }

    /**
     * Sends money.
     *
     * @param string $iban The account number to pay.
     * @param float $amount How much.
     */
    #[McpTool(name: 'send_payment')]
    public function pay(string $iban, float $amount): string
    {
        return $iban . $amount;
    }
}
