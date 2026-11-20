<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

use Cake\Http\Client\Request;
use CakeDC\Mcp\Client\Exception\ClientException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;

/**
 * Sends a client's OAuth requests at the trust level of their URL: URLs from the developer's config through the
 * client's own PSR-18 client, URLs learned from a remote server through GuardedHttp. A redirect is never trusted:
 * one answered to a trusted GET continues through the guard, and a request with credentials follows none.
 */
final class OutboundHttp
{
    /**
     * @param \CakeDC\Mcp\Client\Http\GuardedHttp $guarded For learned URLs.
     * @param \Psr\Http\Client\ClientInterface $trusted For configured URLs (it follows no redirect).
     */
    public function __construct(private GuardedHttp $guarded, private ClientInterface $trusted)
    {
    }

    /**
     * An unauthenticated GET for metadata.
     *
     * @param string $url The exact URL.
     * @param bool $trusted Whether the URL comes from the config.
     * @param float $timeout Seconds for each request.
     * @return \CakeDC\Mcp\Client\Http\HttpResponse
     * @throws \CakeDC\Mcp\Client\Exception\ClientException
     */
    public function get(string $url, bool $trusted, float $timeout): HttpResponse
    {
        if (!$trusted) {
            return $this->guarded->get($url, $timeout);
        }
        $response = $this->trusted(new HttpRequest('GET', $url, ['Accept' => 'application/json'], null, $timeout));
        $location = $response->header('Location');
        if ($response->isRedirect() && $location !== null && trim($location) !== '') {
            return $this->guarded->get(GuardedHttp::resolve($url, $location), $timeout);
        }

        return $response;
    }

    /**
     * A request that may carry credentials; a redirect is refused.
     *
     * @param \CakeDC\Mcp\Client\Http\HttpRequest $request The request.
     * @param bool $trusted Whether its URL comes from the config.
     * @return \CakeDC\Mcp\Client\Http\HttpResponse
     * @throws \CakeDC\Mcp\Client\Exception\ClientException
     */
    public function send(HttpRequest $request, bool $trusted): HttpResponse
    {
        if (!$trusted) {
            return $this->guarded->send($request);
        }
        $response = $this->trusted($request);
        if ($response->isRedirect()) {
            throw GuardedHttp::redirectRefused();
        }

        return $response;
    }

    /**
     * @param \CakeDC\Mcp\Client\Http\HttpRequest $request The request.
     * @return \CakeDC\Mcp\Client\Http\HttpResponse
     */
    private function trusted(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->trusted->sendRequest(
                new Request($request->url, $request->method, $request->headers, $request->body),
            );
        } catch (ClientExceptionInterface) {
            // The message is left out: it may quote the URL or the request.
            throw new ClientException(sprintf(
                'The server at %s could not be reached.',
                (string)parse_url($request->url, PHP_URL_HOST),
            ));
        }

        return new HttpResponse($response->getStatusCode(), $response->getHeaders(), (string)$response->getBody());
    }
}
