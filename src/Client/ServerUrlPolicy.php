<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use InvalidArgumentException;

/**
 * The rule for URLs the developer writes in `Mcp.clients`: they are trusted, internal hosts included, so only their
 * form is checked. https anywhere; plain http only for this machine (localhost, a `.localhost` name, a loopback
 * address) or when the client sets `allowInsecure`, since a token sent over http can be read on the way.
 *
 * URLs learned from a remote server never come here: they go through Http\EndpointGuard.
 */
final class ServerUrlPolicy
{
    /**
     * @param string $url The URL as configured.
     * @param bool $allowInsecure The client's `allowInsecure`.
     * @param string $key The config key, for the message, e.g. `Mcp.clients.github.url`.
     * @return void
     * @throws \InvalidArgumentException When the URL may not be used.
     */
    public static function check(string $url, bool $allowInsecure, string $key): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower(trim((string)($parts['host'] ?? ''), '[]')) : '';
        if (
            !is_array($parts)
            || !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || str_contains($url, '#')
            || preg_match('/\s/', $url) === 1
        ) {
            throw new InvalidArgumentException(sprintf(
                '"%s" must be an absolute http or https URL without a user name, password or fragment.',
                $key,
            ));
        }
        if ($scheme === 'http' && !$allowInsecure && !self::isLocal($host)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" uses plain http to %s, which sends tokens readable on the way. Use https, or set '
                . '"allowInsecure" => true on the client if this network is trusted.',
                $key,
                $host,
            ));
        }
    }

    /**
     * @param string $host Lowercase host, without IPv6 brackets.
     * @return bool Whether it names this machine.
     */
    private static function isLocal(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
    }
}
