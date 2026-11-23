<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

use Cake\Http\Client;
use Cake\Http\Client\Request;
use CakeDC\Mcp\Client\Exception\ClientException;
use Psr\Http\Client\ClientInterface;
use Throwable;

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
     * @param \CakeDC\Mcp\Client\Http\Deadline|null $deadline A limit every request shares, if any.
     */
    public function __construct(
        private GuardedHttp $guarded,
        private ClientInterface $trusted,
        private ?Deadline $deadline = null,
    ) {
    }

    /**
     * @param \CakeDC\Mcp\Client\Http\Deadline $deadline A limit for all the requests sent through the copy.
     * @return self A copy that sends each request with what is left of it, and none once it has passed.
     */
    public function withDeadline(Deadline $deadline): self
    {
        return new self($this->guarded, $this->trusted, $deadline);
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
            return $this->guarded->get($url, $timeout, $this->deadline);
        }
        $response = $this->trusted(new HttpRequest(
            'GET',
            $url,
            ['Accept' => 'application/json'],
            null,
            $this->deadline?->timeout($timeout, $url) ?? $timeout,
        ));
        $location = $response->header('Location');
        if ($response->isRedirect() && $location !== null && trim($location) !== '') {
            return $this->guarded->get(GuardedHttp::resolve($url, $location), $timeout, $this->deadline);
        }

        return $response;
    }

    /**
     * Checks a learned URL with the guard, without sending anything.
     *
     * @param string $url An absolute URL.
     * @return void
     * @throws \CakeDC\Mcp\Client\Http\BlockedUrlException
     */
    public function check(string $url): void
    {
        $this->guarded->check($url);
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
        if ($this->deadline !== null) {
            $request = new HttpRequest(
                $request->method,
                $request->url,
                $request->headers,
                $request->body,
                $this->deadline->timeout($request->timeout, $request->url),
            );
        }
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
            $psr = new Request($request->url, $request->method, $request->headers, $request->body);
            // Cake's client takes a timeout for one request; another PSR-18 client keeps its own.
            $client = $this->trusted;
            $response = $this->deadline !== null && $client instanceof Client
                ? $client->send($psr, ['timeout' => max(1, (int)ceil($request->timeout))] + $client->getConfig())
                : $client->sendRequest($psr);
        } catch (Throwable $e) {
            // Anything the HTTP layer throws (a PSR-18 error, a gzip body that is not one) is this client's error.
            // Its message is left out of the exception, which may quote the URL or the request: the cause is
            // logged at debug level with every URL cut down to its host.
            $host = (string)parse_url($request->url, PHP_URL_HOST);
            HttpFailure::log($e, $host);

            throw new ClientException(sprintf('The server at %s could not be reached.', $host));
        }

        return new HttpResponse($response->getStatusCode(), $response->getHeaders(), (string)$response->getBody());
    }
}
