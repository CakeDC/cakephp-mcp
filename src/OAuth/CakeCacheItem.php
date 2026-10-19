<?php
declare(strict_types=1);

namespace CakeDC\Mcp\OAuth;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

/**
 * An item of CakeCachePool.
 *
 * @internal
 */
final class CakeCacheItem implements CacheItemInterface
{
    private ?int $expiresAt;

    /**
     * @param string $key The key.
     * @param mixed $value The value, null on a miss.
     * @param bool $hit Whether it came from the cache.
     * @param int|null $expiresAt Unix time it expires at; null for the cache config's duration.
     */
    public function __construct(
        private readonly string $key,
        private mixed $value,
        private readonly bool $hit,
        ?int $expiresAt = null,
    ) {
        $this->expiresAt = $expiresAt;
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @inheritDoc
     */
    public function get(): mixed
    {
        return $this->value;
    }

    /**
     * @inheritDoc
     */
    public function isHit(): bool
    {
        return $this->hit;
    }

    /**
     * @inheritDoc
     */
    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiresAt = $expiration?->getTimestamp();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function expiresAfter(DateInterval|int|null $time): static
    {
        $this->expiresAt = match (true) {
            $time === null => null,
            is_int($time) => time() + $time,
            default => (new DateTimeImmutable())->add($time)->getTimestamp(),
        };

        return $this;
    }

    /**
     * Seconds left, null for the cache config's duration.
     *
     * @return int|null
     */
    public function ttl(): ?int
    {
        return $this->expiresAt === null ? null : $this->expiresAt - time();
    }

    /**
     * Unix time it expires at, null for the cache config's duration.
     *
     * @return int|null
     */
    public function expiry(): ?int
    {
        return $this->expiresAt;
    }
}
