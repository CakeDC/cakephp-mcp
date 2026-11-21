<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

/**
 * Keeps URLs a remote server hands the client (metadata, token, registration and revocation endpoints, redirects)
 * from pointing it at private addresses (SSRF): the database, the cloud metadata service, anything on the
 * application's network. Only https, only host names (never an IP literal), and only names whose every address is
 * public. The request is then pinned to the address checked, so a second lookup cannot answer differently.
 *
 * Adapted from ZuluBrain's EndpointGuard and ConnectorUrl.
 */
final class EndpointGuard
{
    /**
     * @param \CakeDC\Mcp\Client\Http\HostResolverInterface $resolver Looks the host up.
     */
    /**
     * Special-use names (RFC 6761, RFC 6762, RFC 8375) and the reverse lookup zone, which name this machine or the
     * local network: refused whatever they resolve to, and the only name check left to do behind a proxy.
     *
     * @var list<string>
     */
    private const LOCAL_SUFFIXES = ['localhost', 'local', 'localdomain', 'internal', 'home.arpa', 'arpa'];

    /**
     * @param \CakeDC\Mcp\Client\Http\HostResolverInterface $resolver Resolves host names.
     * @param bool $proxied Whether requests go through `Mcp.client.proxy`, which resolves the name itself: a name
     *   the application cannot resolve is then allowed, and the checked endpoint has no address to pin.
     */
    public function __construct(private HostResolverInterface $resolver, private bool $proxied = false)
    {
    }

    /**
     * Checks the origin of $url; the path and query are the caller's.
     *
     * @param string $url An absolute URL.
     * @return \CakeDC\Mcp\Client\Http\CheckedEndpoint
     * @throws \CakeDC\Mcp\Client\Http\BlockedUrlException
     */
    public function check(string $url): CheckedEndpoint
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(trim((string)($parts['host'] ?? ''), '[]')) : '';
        if (
            !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] < 1)
            || str_contains($url, '#')
            || preg_match('/\s/', $url) === 1
        ) {
            throw new BlockedUrlException(
                'A remote server named an address the MCP client does not call: only https, without a user name, '
                . 'password or fragment.',
            );
        }
        $notPublic = sprintf('%s is not a public address the MCP client may call.', $host);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || self::looksNumeric($host)) {
            // curl reads 2130706433, 127.1 or 0x7f000001 as an IP address; learned URLs are reached by name only.
            throw new BlockedUrlException($notPublic);
        }
        // A trailing dot is refused by isHostName(): curl would not match the pin to "host." and resolve again.
        if (!self::isHostName($host) || !str_contains($host, '.') || self::isLocalName($host)) {
            // A single label could go through the server's DNS search domains.
            throw new BlockedUrlException($notPublic);
        }
        $addresses = $this->resolver->resolve($host);
        if ($addresses === [] && !$this->proxied) {
            throw new BlockedUrlException($notPublic);
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                throw new BlockedUrlException($notPublic);
            }
        }
        $port = (int)($parts['port'] ?? 443);

        return new CheckedEndpoint(
            'https://' . $host . ($port === 443 ? '' : ':' . $port),
            $host,
            $port,
            // Behind a proxy nothing is pinned: the proxy resolves the name.
            $this->proxied ? '' : $addresses[0],
        );
    }

    /**
     * @param string $host A host name.
     * @return bool Whether it is, or is under, a special-use name of this machine or the local network.
     */
    private static function isLocalName(string $host): bool
    {
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $host A host that is not an IP address, lowercase, without a trailing dot.
     * @return bool Whether it is letters, digits, hyphens and dots only, in labels of 1 to 63 characters that do not
     *   start or end with a hyphen.
     */
    private static function isHostName(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(?:\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $host) === 1;
    }

    /**
     * @param string $host A host that is not an IP address.
     * @return bool Whether its last label is all digits or a 0x number, which curl takes for an IP address.
     */
    private static function looksNumeric(string $host): bool
    {
        $label = substr($host, (int)strrpos('.' . $host, '.'));

        return preg_match('/^(?:\d+|0x[0-9a-f]*)$/', $label) === 1;
    }

    /**
     * Judged on the binary form, so every spelling of an address gets the same answer.
     *
     * @param string $address An IPv4 or IPv6 address.
     * @return bool Whether it is globally reachable and not multicast, with the IPv4 inside a mapped, translated
     *   (NAT64) or 6to4 address judged as well, or refused where it cannot be told.
     */
    private static function isPublic(string $address): bool
    {
        $bytes = inet_pton($address);
        if ($bytes === false) {
            return false;
        }
        if (strlen($bytes) === 4) {
            return ord($bytes[0]) < 224 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
        }
        $head = substr($bytes, 0, 12);
        if (
            $head === str_repeat("\0", 12) // ::/96 (unspecified, loopback, compatible)
            || $head === "\0\x64\xff\x9b" . str_repeat("\0", 8) // 64:ff9b::/96 (NAT64)
            || str_starts_with($bytes, "\0\x64\xff\x9b\0\x01") // 64:ff9b:1::/48 (local-use NAT64)
            || $head === str_repeat("\0", 8) . "\xff\xff\0\0" // ::ffff:0:0:0/96 (translated)
            || (ord($bytes[0]) === 0xfe && (ord($bytes[1]) & 0xc0) === 0xc0) // fec0::/10 (site-local)
            || (ord($bytes[0]) === 0x3f && ord($bytes[1]) === 0xff && (ord($bytes[2]) & 0xf0) === 0) // 3fff::/20
            || str_starts_with($bytes, "\x5f\0") // 5f00::/16 (SRv6 SIDs)
            || ord($bytes[0]) === 0xff // ff00::/8 (multicast)
        ) {
            return false;
        }
        if ($head === str_repeat("\0", 10) . "\xff\xff") {
            return self::isPublic((string)inet_ntop(substr($bytes, 12))); // ::ffff:0:0/96 (mapped)
        }
        if (str_starts_with($bytes, "\x20\x02")) {
            return self::isPublic((string)inet_ntop(substr($bytes, 2, 4))); // 2002::/16 (6to4)
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }
}
