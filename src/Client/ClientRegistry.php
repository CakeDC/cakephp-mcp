<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use CakeDC\Mcp\Client\Http\CakeGuardedTransport;
use CakeDC\Mcp\Client\Http\DnsHostResolver;
use CakeDC\Mcp\Client\Http\EndpointGuard;
use CakeDC\Mcp\Client\Http\GuardedHttp;
use CakeDC\Mcp\Client\Http\OutboundHttp;
use CakeDC\Mcp\Client\OAuth\Discovery;
use CakeDC\Mcp\Client\OAuth\OAuthFlow;
use CakeDC\Mcp\Client\OAuth\Registrar;
use CakeDC\Mcp\Client\OAuth\TokenClient;
use CakeDC\Mcp\Client\Store\ConnectionStoreInterface;
use InvalidArgumentException;
use LogicException;

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
     * @param \CakeDC\Mcp\Client\Store\ConnectionStoreInterface|null $store The token store; `Mcp.client.store` by
     *   default, built on first use (only user mode needs it).
     * @param \CakeDC\Mcp\Client\Http\GuardedHttp|null $guarded The layer for URLs learned from remote servers; the
     *   server's DNS and Cake's Curl adapter by default.
     */
    public function __construct(
        private ?HttpClientFactoryInterface $http = null,
        private ?ConnectionStoreInterface $store = null,
        private ?GuardedHttp $guarded = null,
    ) {
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
     * @return \CakeDC\Mcp\Client\Store\ConnectionStoreInterface
     * @throws \InvalidArgumentException When `Mcp.client.store` is not a store class.
     */
    public function store(): ConnectionStoreInterface
    {
        if ($this->store === null) {
            $class = ClientConfig::setting('store');
            if (!is_string($class) || !is_subclass_of($class, ConnectionStoreInterface::class)) {
                throw new InvalidArgumentException(sprintf(
                    '"Mcp.client.store" must be the name of a class implementing %s.',
                    ConnectionStoreInterface::class,
                ));
            }
            $this->store = new $class();
        }

        return $this->store;
    }

    /**
     * @return \CakeDC\Mcp\Client\Http\GuardedHttp
     */
    public function guarded(): GuardedHttp
    {
        return $this->guarded ??= new GuardedHttp(
            new EndpointGuard(new DnsHostResolver()),
            new CakeGuardedTransport(),
            (int)ClientConfig::setting('maxResponseBytes'),
        );
    }

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config A client.
     * @return \CakeDC\Mcp\Client\Http\OutboundHttp Its OAuth requests, at the trust level of each URL.
     */
    public function outbound(ClientConfig $config): OutboundHttp
    {
        return new OutboundHttp($this->guarded(), $this->http()->create($config));
    }

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config A user mode client.
     * @return \CakeDC\Mcp\Client\OAuth\TokenClient
     */
    public function tokens(ClientConfig $config): TokenClient
    {
        return new TokenClient($this->outbound($config), $config->timeout);
    }

    /**
     * @param string $name A user mode client of `Mcp.clients`.
     * @return \CakeDC\Mcp\Client\OAuth\OAuthFlow Its sign-in.
     * @throws \LogicException When it is not a user mode client.
     */
    public function flow(string $name): OAuthFlow
    {
        $config = $this->get($name)->config();
        if (!$config->isUser()) {
            throw new LogicException(sprintf('"Mcp.clients.%s" is not a user mode client.', $name));
        }
        $outbound = $this->outbound($config);

        return new OAuthFlow(
            $config,
            new Discovery($outbound, $config),
            new Registrar($outbound, $this->store(), $config),
            new TokenClient($outbound, $config->timeout),
            $this->store(),
        );
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
