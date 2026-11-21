<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Passes requests to another client, unless $intercept answers one first (with the decoded JSON-RPC body).
 */
final class InterceptingHttpClient implements ClientInterface
{
    /**
     * @param \Psr\Http\Client\ClientInterface $inner The real server.
     * @param \Closure(\Psr\Http\Message\RequestInterface, array<mixed>): ?\Psr\Http\Message\ResponseInterface $intercept
     */
    public function __construct(private ClientInterface $inner, private Closure $intercept)
    {
    }

    /**
     * @inheritDoc
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = (string)$request->getBody();
        $request->getBody()->rewind();
        $decoded = json_decode($body, true);

        return ($this->intercept)($request, is_array($decoded) ? $decoded : []) ?? $this->inner->sendRequest($request);
    }
}
