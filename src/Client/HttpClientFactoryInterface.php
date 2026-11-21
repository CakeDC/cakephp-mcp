<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Psr\Http\Client\ClientInterface;

/**
 * The PSR-18 client that carries a client's requests to URLs the developer configured: the MCP server (through the
 * SDK's HttpTransport) and configured OAuth endpoints. Replace it to send through another HTTP stack, or with a
 * double in tests. URLs learned from a remote server never go through it: they use Http\GuardedHttp.
 */
interface HttpClientFactoryInterface
{
    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client.
     * @return \Psr\Http\Client\ClientInterface A client that follows no redirect and honours `$config->timeout`.
     */
    public function create(ClientConfig $config): ClientInterface;
}
