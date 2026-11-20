<?php
declare(strict_types=1);

namespace TestApp\McpProgress;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Server\ClientGateway;

/**
 * A tool that reports progress and logs while it works.
 */
class SlowTools
{
    /**
     * Imports orders in two steps.
     *
     * @param \Mcp\Server\ClientGateway $client The client, for progress and log notifications.
     */
    #[McpTool(name: 'import_orders')]
    public function import(ClientGateway $client): string
    {
        $client->progress(1, 2, 'Read the file');
        $client->log(LoggingLevel::Info, 'Imported 10 orders');
        $client->progress(2, 2, 'Saved the orders');

        return 'Imported 10 orders.';
    }

    /**
     * Asks the client's model to summarize the orders (sampling).
     *
     * @param \Mcp\Server\ClientGateway $client The client.
     */
    #[McpTool(name: 'summarize_orders')]
    public function summarize(ClientGateway $client): string
    {
        $result = $client->sample('Summarize the open orders.');

        return (string)json_encode($result);
    }

    /**
     * Returns without notifying.
     */
    #[McpTool(name: 'quick')]
    public function quick(): string
    {
        return 'done';
    }
}
