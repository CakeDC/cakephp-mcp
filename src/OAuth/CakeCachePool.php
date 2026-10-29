<?php
declare(strict_types=1);

namespace CakeDC\Mcp\OAuth;

use Cake\Cache\Cache;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * A PSR-6 pool over a Cake cache config, for the authorization server metadata and keys external mode
 * caches (the SDK and firebase/php-jwt need PSR-6; Cake provides PSR-16).
 */
final class CakeCachePool implements CacheItemPoolInterface
{
    /**
     * @var array<string, \CakeDC\Mcp\OAuth\CakeCacheItem>
     */
    private array $deferred = [];

    /**
     * @param string $config A Cake cache config name.
     * @throws \RuntimeException When the config does not exist.
     */
    public function __construct(private readonly string $config)
    {
        if (Cache::getConfig($config) === null) {
            throw new RuntimeException(sprintf(
                'The cache config "%s" (Mcp.auth.external.cache) does not exist.',
                $config,
            ));
        }
    }

    /**
     * Commits deferred items, as PSR-6 allows.
     */
    public function __destruct()
    {
        $this->commit();
    }

    /**
     * @inheritDoc
     */
    public function getItem(string $key): CacheItemInterface
    {
        self::assertKey($key);
        if (isset($this->deferred[$key])) {
            $deferred = $this->deferred[$key];

            return new CakeCacheItem($key, $deferred->get(), true, $deferred->expiry());
        }
        // Stored wrapped, so false and null values are hits too.
        $stored = $this->cache()->get($key);
        if (is_array($stored) && array_key_exists('value', $stored)) {
            return new CakeCacheItem($key, $stored['value'], true);
        }

        return new CakeCacheItem($key, null, false);
    }

    /**
     * @param array<string> $keys The keys.
     * @return iterable<string, \Psr\Cache\CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    /**
     * @inheritDoc
     */
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @inheritDoc
     */
    public function clear(): bool
    {
        $this->deferred = [];

        return $this->cache()->clear();
    }

    /**
     * @inheritDoc
     */
    public function deleteItem(string $key): bool
    {
        self::assertKey($key);
        unset($this->deferred[$key]);
        $this->cache()->delete($key);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CakeCacheItem) {
            return false;
        }
        $ttl = $item->ttl();
        if ($ttl !== null && $ttl <= 0) {
            $this->cache()->delete($item->getKey());

            return true;
        }

        return $this->cache()->set($item->getKey(), ['value' => $item->get()], $ttl);
    }

    /**
     * @inheritDoc
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$item instanceof CakeCacheItem) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function commit(): bool
    {
        $saved = true;
        foreach ($this->deferred as $item) {
            $saved = $this->save($item) && $saved;
        }
        $this->deferred = [];

        return $saved;
    }

    /**
     * @return \Psr\SimpleCache\CacheInterface
     */
    private function cache(): CacheInterface
    {
        return Cache::pool($this->config);
    }

    /**
     * @param string $key The key.
     * @return void
     * @throws \CakeDC\Mcp\OAuth\CacheKeyException For an empty key or a reserved character.
     */
    private static function assertKey(string $key): void
    {
        if ($key === '' || strpbrk($key, '{}()/\\@:') !== false) {
            throw new CacheKeyException(sprintf('"%s" is not a valid cache key.', $key));
        }
    }
}
