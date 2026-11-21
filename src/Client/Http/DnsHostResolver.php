<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

use Closure;

/**
 * The server's DNS, and when it has no record of a name, the system's lookup (which also reads /etc/hosts, where
 * `localhost` and local development names often only are).
 */
final class DnsHostResolver implements HostResolverInterface
{
    /**
     * @var \Closure(string): list<string>
     */
    private Closure $dns;

    /**
     * @var \Closure(string): list<string>
     */
    private Closure $system;

    /**
     * @param (\Closure(string): list<string>)|null $dns The DNS lookup; `dns_get_record()` by default.
     * @param (\Closure(string): list<string>)|null $system The system lookup; `gethostbynamel()` by default.
     */
    public function __construct(?Closure $dns = null, ?Closure $system = null)
    {
        $this->dns = $dns ?? self::dnsRecords(...);
        $this->system = $system ?? fn(string $host): array => gethostbynamel($host) ?: [];
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $addresses = ($this->dns)($host);
        if ($addresses === []) {
            $addresses = ($this->system)($host);
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @param string $host A host name.
     * @return list<string> Its A and AAAA records.
     */
    private static function dnsRecords(string $host): array
    {
        $addresses = [];
        // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a failed lookup is just no address
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
