<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

/**
 * Builds the named clients and holds what they share: the HTTP client factory, the token store and the guarded HTTP
 * layer. McpClients uses one for the whole process; tests give it doubles.
 */
class ClientRegistry
{
    /**
     * @var array<string, \CakeDC\Mcp\Client\McpClient>
     */
    private array $clients = [];

    /**
     * @param \CakeDC\Mcp\Client\HttpClientFactoryInterface|null $http The PSR-18 client for configured URLs; Cake's
     *   HTTP client by default.
     */
    public function __construct(private ?HttpClientFactoryInterface $http = null)
    {
    }

    /**
     * @param string $name A key of `Mcp.clients`.
     * @return \CakeDC\Mcp\Client\McpClient The same instance for the same name.
     * @throws \InvalidArgumentException When it is not configured or its config is invalid.
     */
    public function get(string $name): McpClient
    {
        return $this->clients[$name] ??= new McpClient(ClientConfig::fromConfig($name), $this);
    }

    /**
     * @return \CakeDC\Mcp\Client\HttpClientFactoryInterface
     */
    public function http(): HttpClientFactoryInterface
    {
        return $this->http ??= new CakeHttpClientFactory();
    }

    /**
     * Closes every client's connection.
     *
     * @return void
     */
    public function close(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->clients = [];
    }
}
