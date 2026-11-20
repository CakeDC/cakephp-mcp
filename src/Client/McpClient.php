<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use LogicException;

/**
 * A named client of `Mcp.clients`. In app mode it calls the server with the app's own credentials; in user mode,
 * for($user) gives the connection of one user.
 */
final class McpClient
{
    use CallsServerTrait;

    private ?ClientSession $session = null;

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client.
     * @param \CakeDC\Mcp\Client\ClientRegistry $registry Where its HTTP client, store and OAuth pieces come from.
     */
    public function __construct(private ClientConfig $config, private ClientRegistry $registry)
    {
    }

    /**
     * @return string The client's name in `Mcp.clients`.
     */
    public function name(): string
    {
        return $this->config->name;
    }

    /**
     * @inheritDoc
     */
    public function config(): ClientConfig
    {
        return $this->config;
    }

    /**
     * Ends the connection to the server; the next call opens a new one.
     *
     * @return void
     */
    public function close(): void
    {
        $this->session?->close();
        $this->session = null;
    }

    /**
     * @inheritDoc
     */
    protected function run(callable $call): mixed
    {
        if ($this->config->isUser()) {
            throw new LogicException(sprintf(
                '"Mcp.clients.%s" is a user mode client: call it on behalf of a user with '
                . 'McpClients::get(\'%s\')->for($user).',
                $this->config->name,
                $this->config->name,
            ));
        }
        $this->session ??= new ClientSession($this->config, $this->registry->http());

        return $call($this->session->client());
    }
}
