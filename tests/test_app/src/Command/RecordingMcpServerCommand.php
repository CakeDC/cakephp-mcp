<?php
declare(strict_types=1);

namespace TestApp\Command;

use CakeDC\Mcp\Command\McpServerCommand;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Server\ServerFactory;
use Mcp\Server\Transport\TransportInterface;
use TestApp\RecordingTransport;

/**
 * Runs the real command against queued messages instead of STDIN/STDOUT.
 */
class RecordingMcpServerCommand extends McpServerCommand
{
    public ?RecordingTransport $transport = null;

    /**
     * @param list<string> $messages JSON-RPC lines to feed.
     */
    public function __construct(ServerFactory $factory, CurrentIdentity $identity, private readonly array $messages)
    {
        parent::__construct($factory, $identity);
    }

    /**
     * @inheritDoc
     */
    protected function createTransport(): TransportInterface
    {
        return $this->transport = new RecordingTransport($this->messages);
    }
}
