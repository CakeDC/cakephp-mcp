<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

/**
 * Looks up the addresses of a host name.
 */
interface HostResolverInterface
{
    /**
     * @param string $host Host name or IP address.
     * @return list<string> Its IPv4 and IPv6 addresses; empty when it does not resolve.
     */
    public function resolve(string $host): array;
}
