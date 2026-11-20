<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Cake\Http\Client;
use Psr\Http\Client\ClientInterface;

/**
 * Cake's HTTP client (PSR-18) for configured URLs: the client's timeout, no redirects (a redirect would re-send the
 * token to wherever it points).
 */
final class CakeHttpClientFactory implements HttpClientFactoryInterface
{
    /**
     * @inheritDoc
     */
    public function create(ClientConfig $config): ClientInterface
    {
        return new Client(['timeout' => $config->timeout, 'redirect' => 0]);
    }
}
