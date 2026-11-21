<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Remembers the status of the last answer, so a `401` (a token the server no longer accepts) can be told apart from
 * other failures: the SDK's transport reports both as the same exception.
 *
 * @internal
 */
final class WatchedHttpClient implements ClientInterface
{
    private ?int $lastStatus = null;

    /**
     * @param \Psr\Http\Client\ClientInterface $client The client it wraps.
     * @param \CakeDC\Mcp\Client\ClientConfig|null $config The client, whose `token` callable is called for each request.
     */
    public function __construct(private ClientInterface $client, private ?ClientConfig $config = null)
    {
    }

    /**
     * @inheritDoc
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastStatus = null;
        $token = $this->config?->callableToken();
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        $response = $this->client->sendRequest($request);
        $this->lastStatus = $response->getStatusCode();

        return $response;
    }

    /**
     * @return int|null The status of the last answer, null when none came.
     */
    public function lastStatus(): ?int
    {
        return $this->lastStatus;
    }
}
