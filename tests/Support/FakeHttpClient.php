<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use Cake\Http\Client\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client serving canned JSON documents; 404 for any other URL.
 */
final class FakeHttpClient implements ClientInterface
{
    /**
     * @var array<string, array{0: int, 1: string}>
     */
    private array $responses = [];

    /**
     * The URLs requested, in order.
     *
     * @var list<string>
     */
    public array $requested = [];

    /**
     * @param string $url The URL.
     * @param array<string, mixed> $body The JSON document.
     * @return void
     */
    public function serveJson(string $url, array $body): void
    {
        $this->responses[$url] = [200, (string)json_encode($body)];
    }

    /**
     * @inheritDoc
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string)$request->getUri();
        $this->requested[] = $url;
        [$status, $body] = $this->responses[$url] ?? [404, '{}'];

        $line = sprintf('HTTP/1.1 %d %s', $status, $status === 200 ? 'OK' : 'Not Found');

        return new Response([$line, 'Content-Type: application/json'], $body);
    }
}
