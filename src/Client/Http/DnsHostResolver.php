<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

/**
 * The server's DNS.
 */
final class DnsHostResolver implements HostResolverInterface
{
    /**
     * @inheritDoc
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $addresses = [];
        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a failed lookup is just no address
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
