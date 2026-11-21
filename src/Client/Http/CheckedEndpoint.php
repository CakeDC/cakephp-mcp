<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

/**
 * An origin EndpointGuard checked, and the address the request must connect to.
 */
final class CheckedEndpoint
{
    /**
     * @param string $origin `https://host[:port]`, lowercase, rebuilt from the checked parts.
     * @param string $host The host name, lowercase, without a trailing dot.
     * @param int $port The port.
     * @param string $ip The checked address to connect to.
     */
    public function __construct(
        public readonly string $origin,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
    ) {
    }
}
