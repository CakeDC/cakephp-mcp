<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\HttpClientFactoryInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Hands every client the same PSR-18 double, and remembers the configs it was asked for.
 */
final class FixedHttpClientFactory implements HttpClientFactoryInterface
{
    /**
     * @var list<string>
     */
    public array $created = [];

    /**
     * @param \Psr\Http\Client\ClientInterface $client The double.
     */
    public function __construct(private ClientInterface $client)
    {
    }

    /**
     * @inheritDoc
     */
    public function create(ClientConfig $config): ClientInterface
    {
        $this->created[] = $config->name;

        return $this->client;
    }
}
