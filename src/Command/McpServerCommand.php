<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\Server\ServerFactory;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\TransportInterface;

/**
 * `bin/cake mcp server`: serves MCP over stdio for clients on this machine
 * (for example Claude Code configured with a local command).
 *
 * Nothing may be written to STDOUT besides protocol messages, so this command
 * never uses `$io->out()`.
 */
class McpServerCommand extends Command
{
    /**
     * @param \CakeDC\Mcp\Server\ServerFactory $serverFactory Builds the server.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity Shared with the tools.
     */
    public function __construct(
        private readonly ServerFactory $serverFactory,
        private readonly CurrentIdentity $identity,
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'mcp server';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Serve MCP over stdio, for MCP clients running on this machine.';
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        // The console framework mirrors info logs to STDOUT, which would corrupt the protocol.
        // QUIET keeps only the stderr logger for errors and warnings.
        $io->setLoggers(ConsoleIo::QUIET);

        $resolver = McpConfig::get('stdio.identity');
        $this->identity->set(is_callable($resolver) ? $resolver() : null);

        $this->serverFactory->create((bool)McpConfig::get('stdio.trusted'))->run($this->createTransport());

        return static::CODE_SUCCESS;
    }

    /**
     * @return \Mcp\Server\Transport\TransportInterface<mixed>
     */
    protected function createTransport(): TransportInterface
    {
        return new StdioTransport(logger: new McpLogger());
    }
}
