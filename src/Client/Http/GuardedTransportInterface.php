<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

/**
 * Sends one request through a client pinned to an address GuardedHttp already checked. Follows no redirect, uses no
 * proxy. Only GuardedHttp calls it: anything else would skip the check.
 */
interface GuardedTransportInterface
{
    /**
     * @param \CakeDC\Mcp\Client\Http\HttpRequest $request The request, sent to its exact URL.
     * @param \CakeDC\Mcp\Client\Http\CheckedEndpoint $pin The checked origin and the address to connect to.
     * @param int $maxBytes The largest body read.
     * @return \CakeDC\Mcp\Client\Http\HttpResponse Any status, 3xx included: the caller decides.
     * @throws \CakeDC\Mcp\Client\Http\BlockedUrlException For a URL off the pinned origin.
     * @throws \CakeDC\Mcp\Client\Exception\ClientException For a network failure or a body over $maxBytes.
     */
    public function send(HttpRequest $request, CheckedEndpoint $pin, int $maxBytes): HttpResponse;
}
