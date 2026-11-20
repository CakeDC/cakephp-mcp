<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

/**
 * One outbound request of the client's OAuth work, sent to the exact URL given.
 */
final class HttpRequest
{
    /**
     * @param string $method HTTP method, upper case.
     * @param string $url The exact URL, query included.
     * @param array<non-empty-string, non-empty-string> $headers Header name => value.
     * @param string|null $body The body, or null for none.
     * @param float $timeout Seconds for the whole request.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly float $timeout = 15.0,
    ) {
    }
}
