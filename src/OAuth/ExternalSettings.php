<?php
declare(strict_types=1);

namespace CakeDC\Mcp\OAuth;

use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;

/**
 * The validated `Mcp.auth.external` settings: the third-party authorization server whose JWT access
 * tokens the MCP endpoint accepts.
 */
final class ExternalSettings
{
    /**
     * Signature algorithms with public keys, the only ones a JWKS can verify.
     */
    private const ALGORITHMS = [
        'RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512', 'ES256', 'ES384', 'ES512', 'EdDSA',
    ];

    /**
     * @param string $issuer The issuer, compared verbatim with the `iss` claim.
     * @param list<string> $audiences Accepted `aud` values.
     * @param list<string> $algorithms Accepted signature algorithms.
     * @param string $scopeClaim The claim holding the scopes.
     * @param string|null $tokenType Required `typ` header, if any.
     * @param int $leeway Clock skew tolerated, in seconds.
     * @param string $cache The Cake cache config for discovery metadata and keys.
     * @param \Psr\Http\Client\ClientInterface|null $httpClient The HTTP client; Cake's when null.
     */
    private function __construct(
        public readonly string $issuer,
        public readonly array $audiences,
        public readonly array $algorithms,
        public readonly string $scopeClaim,
        public readonly ?string $tokenType,
        public readonly int $leeway,
        public readonly string $cache,
        public readonly ?ClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, mixed> $settings `Mcp.auth.external`, merged over its defaults.
     * @param string $resource The canonical resource URL, the default audience.
     * @return self
     * @throws \InvalidArgumentException For an invalid setting, named in the message.
     */
    public static function fromArray(array $settings, string $resource): self
    {
        $issuer = $settings['issuer'] ?? null;
        if (!is_string($issuer) || $issuer === '') {
            throw new InvalidArgumentException(
                '"Mcp.auth.external.issuer" is required in external mode: the issuer URL of your authorization server.',
            );
        }
        // Validated like a resource URL, but kept verbatim: it must equal the `iss` claim byte for byte.
        AuthSettings::canonicalUrl($issuer, '"Mcp.auth.external.issuer"');

        $audience = $settings['audience'] ?? null;
        $audiences = match (true) {
            $audience === null => [$resource],
            is_string($audience) && $audience !== '' => [$audience],
            is_array($audience) && $audience !== [] && array_is_list($audience)
                && array_filter($audience, fn(mixed $value): bool => !is_string($value) || $value === '') === []
                => array_values(array_unique($audience)),
            default => throw new InvalidArgumentException(
                '"Mcp.auth.external.audience" must be a string, a non-empty list of strings or null.',
            ),
        };

        $algorithms = $settings['algorithms'] ?? ['RS256'];
        if (
            !is_array($algorithms) || $algorithms === [] || !array_is_list($algorithms)
            || array_diff($algorithms, self::ALGORITHMS) !== []
        ) {
            throw new InvalidArgumentException(sprintf(
                '"Mcp.auth.external.algorithms" must be a non-empty list of: %s.',
                implode(', ', self::ALGORITHMS),
            ));
        }

        $scopeClaim = $settings['scopeClaim'] ?? 'scope';
        if (!is_string($scopeClaim) || $scopeClaim === '') {
            throw new InvalidArgumentException('"Mcp.auth.external.scopeClaim" must be a non-empty string.');
        }
        $tokenType = $settings['tokenType'] ?? null;
        if ($tokenType !== null && (!is_string($tokenType) || $tokenType === '')) {
            throw new InvalidArgumentException('"Mcp.auth.external.tokenType" must be a non-empty string or null.');
        }
        $leeway = $settings['leeway'] ?? 0;
        if (!is_int($leeway) || $leeway < 0 || $leeway > 300) {
            throw new InvalidArgumentException('"Mcp.auth.external.leeway" must be an integer from 0 to 300.');
        }
        $cache = $settings['cache'] ?? 'mcp_oauth';
        if (!is_string($cache) || $cache === '') {
            throw new InvalidArgumentException('"Mcp.auth.external.cache" must name a Cake cache config.');
        }
        $client = $settings['httpClient'] ?? null;
        if ($client !== null && !$client instanceof ClientInterface) {
            throw new InvalidArgumentException(
                '"Mcp.auth.external.httpClient" must be a PSR-18 client (Psr\Http\Client\ClientInterface) or null.',
            );
        }

        return new self(
            $issuer,
            $audiences,
            array_values(array_unique($algorithms)),
            $scopeClaim,
            $tokenType,
            $leeway,
            $cache,
            $client,
        );
    }
}
