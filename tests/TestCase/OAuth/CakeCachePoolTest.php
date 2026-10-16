<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\OAuth;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\OAuth\CakeCachePool;
use Psr\Cache\InvalidArgumentException;
use RuntimeException;

class CakeCachePoolTest extends TestCase
{
    private CakeCachePool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('mcp_pool_test', ['className' => ArrayEngine::class]);
        $this->pool = new CakeCachePool('mcp_pool_test');
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_pool_test');
        parent::tearDown();
    }

    public function testMissIsNotAHit(): void
    {
        $item = $this->pool->getItem('jwks');

        $this->assertFalse($item->isHit());
        $this->assertNull($item->get());
        $this->assertFalse($this->pool->hasItem('jwks'));
    }

    public function testSavedItemIsAHit(): void
    {
        $this->assertTrue($this->pool->save($this->pool->getItem('jwks')->set(['keys' => []])->expiresAfter(60)));

        $item = $this->pool->getItem('jwks');
        $this->assertTrue($item->isHit());
        $this->assertSame(['keys' => []], $item->get());
    }

    public function testFalseIsCachedAsAValue(): void
    {
        // The SDK caches a failed discovery as false.
        $this->pool->save($this->pool->getItem('failed')->set(false));

        $item = $this->pool->getItem('failed');
        $this->assertTrue($item->isHit());
        $this->assertFalse($item->get());
    }

    public function testExpiredItemIsNotStored(): void
    {
        $this->pool->save($this->pool->getItem('old')->set('x')->expiresAfter(0));

        $this->assertFalse($this->pool->getItem('old')->isHit());
    }

    public function testDeferredItemsAreVisibleBeforeAndStoredOnCommit(): void
    {
        $this->pool->saveDeferred($this->pool->getItem('later')->set('x'));

        $this->assertTrue($this->pool->getItem('later')->isHit());
        $this->assertNull(Cache::read('later', 'mcp_pool_test'));
        $this->assertTrue($this->pool->commit());
        $this->assertTrue((new CakeCachePool('mcp_pool_test'))->getItem('later')->isHit());
    }

    public function testDeleteAndClear(): void
    {
        $this->pool->save($this->pool->getItem('a')->set(1));
        $this->pool->save($this->pool->getItem('b')->set(2));

        $this->assertTrue($this->pool->deleteItem('a'));
        $this->assertTrue($this->pool->deleteItem('never-stored'));
        $this->assertFalse($this->pool->hasItem('a'));
        $this->assertTrue($this->pool->clear());
        $this->assertFalse($this->pool->hasItem('b'));
    }

    public function testReservedCharactersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pool->getItem('bad:key');
    }

    public function testUnknownCacheConfigNamesTheSetting(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mcp.auth.external.cache');
        new CakeCachePool('no_such_cache');
    }
}
