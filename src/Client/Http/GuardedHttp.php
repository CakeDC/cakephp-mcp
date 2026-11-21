<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

use CakeDC\Mcp\Client\ClientConfig;

/**
 * Every request to a URL learned from a remote server. Checks the origin with EndpointGuard and sends the exact URL,
 * query included, through a transport pinned to the address checked.
 *
 * Redirects are followed only by get(), which carries no credentials, each hop checked again. send() is for
 * requests with credentials (a code, a verifier, a client secret, a token) and refuses a redirect: following it would
 * re-send them to wherever it points.
 *
 * Adapted from ZuluBrain's ConnectorHttp.
 */
final class GuardedHttp
{
    /**
     * Redirects a metadata GET may follow.
     */
    public const MAX_REDIRECTS = 3;

    /**
     * @param \CakeDC\Mcp\Client\Http\EndpointGuard $guard Judges each origin.
     * @param \CakeDC\Mcp\Client\Http\GuardedTransportInterface $transport Sends through the pinned client.
     * @param int $maxBytes The largest body read.
     */
    public function __construct(
        private EndpointGuard $guard,
        private GuardedTransportInterface $transport,
        private int $maxBytes,
    ) {
    }

    /**
     * This layer for one client: with its `allowPrivateNetwork`, a guard that lets private addresses through.
     *
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client.
     * @return self
     */
    public function forClient(ClientConfig $config): self
    {
        if (!$config->allowPrivateNetwork) {
            return $this;
        }

        return new self(
            $this->guard->allowingPrivateNetwork($config->allowInsecure),
            $this->transport,
            $this->maxBytes,
        );
    }

    /**
     * An unauthenticated GET for metadata, following at most MAX_REDIRECTS redirects.
     *
     * @param string $url The exact URL.
     * @param float $timeout Seconds for each request.
     * @param \CakeDC\Mcp\Client\Http\Deadline|null $deadline A limit the redirects share, if any.
     * @return \CakeDC\Mcp\Client\Http\HttpResponse The last answer, any status but a redirect with a Location.
     * @throws \CakeDC\Mcp\Client\Http\BlockedUrlException
     * @throws \CakeDC\Mcp\Client\Exception\ClientException
     */
    public function get(string $url, float $timeout, ?Deadline $deadline = null): HttpResponse
    {
        for ($hop = 0;; $hop++) {
            $seconds = $deadline?->timeout($timeout, $url) ?? $timeout;
            $response = $this->transport->send(
                new HttpRequest('GET', $url, ['Accept' => 'application/json'], null, $seconds),
                $this->guard->check($url),
                $this->maxBytes,
            );
            $location = $response->header('Location');
            if (!$response->isRedirect() || $location === null || trim($location) === '') {
                return $response;
            }
            if ($hop >= self::MAX_REDIRECTS) {
                throw new BlockedUrlException('The server redirected too many times.');
            }
            $url = self::resolve($url, $location);
        }
    }

    /**
     * Checks a learned URL without sending anything, so a bad endpoint fails as soon as it is read.
     *
     * @param string $url An absolute URL.
     * @return void
     * @throws \CakeDC\Mcp\Client\Http\BlockedUrlException
     */
    public function check(string $url): void
    {
        $this->guard->check($url);
    }

    /**
     * A request that may carry credentials. Never follows a redirect.
     *
     * @param \CakeDC\Mcp\Client\Http\HttpRequest $request The request, sent to its exact URL.
     * @return \CakeDC\Mcp\Client\Http\HttpResponse Any status but a 3xx.
     * @throws \CakeDC\Mcp\Client\Http\BlockedUrlException For an address that is not allowed or a 3xx answer.
     * @throws \CakeDC\Mcp\Client\Exception\ClientException
     */
    public function send(HttpRequest $request): HttpResponse
    {
        $response = $this->transport->send($request, $this->guard->check($request->url), $this->maxBytes);
        if ($response->isRedirect()) {
            throw self::redirectRefused();
        }

        return $response;
    }

    /**
     * @return \CakeDC\Mcp\Client\Http\BlockedUrlException
     */
    public static function redirectRefused(): BlockedUrlException
    {
        return new BlockedUrlException(
            'The server answered with a redirect, which the MCP client does not follow for a request with credentials.',
        );
    }

    /**
     * @param string $from The URL that answered.
     * @param string $location Its Location header, absolute or relative.
     * @return string The next URL, without a fragment; the guard judges it next.
     */
    public static function resolve(string $from, string $location): string
    {
        $location = (string)preg_replace('/#.*$/s', '', trim($location));
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location) === 1) {
            return $location;
        }
        $parts = (array)parse_url($from);
        $scheme = (string)($parts['scheme'] ?? 'https');
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        $origin = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string)($parts['path'] ?? '/');
        $base = substr($path, 0, (int)strrpos($path, '/') + 1);
        if (str_starts_with($location, '?')) {
            return $origin . $path . $location;
        }

        return $origin . ($base === '' ? '/' : $base) . $location;
    }
}
