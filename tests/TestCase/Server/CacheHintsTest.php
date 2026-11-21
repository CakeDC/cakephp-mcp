<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Server;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Server\CacheHints;
use InvalidArgumentException;
use Mcp\Schema\Enum\CacheScope;
use PHPUnit\Framework\Attributes\DataProvider;

class CacheHintsTest extends TestCase
{
    public function testNullMeansNoPolicy(): void
    {
        $this->assertNull(CacheHints::fromConfig(null));
    }

    public function testDefaultTtlAndScope(): void
    {
        $policy = CacheHints::fromConfig(['ttl' => 60000, 'scope' => 'public']);

        $this->assertNotNull($policy);
        $this->assertSame(60000, $policy->ttlFor('tools/list'));
        $this->assertSame(CacheScope::Public, $policy->scopeFor('tools/list'));
    }

    public function testPerMethodOverride(): void
    {
        $policy = CacheHints::fromConfig([
            'ttl' => 60000,
            'methods' => ['tools/list' => ['ttl' => 300000, 'scope' => 'public']],
        ]);

        $this->assertNotNull($policy);
        $this->assertSame(300000, $policy->ttlFor('tools/list'));
        $this->assertSame(CacheScope::Public, $policy->scopeFor('tools/list'));
        $this->assertSame(60000, $policy->ttlFor('resources/read'));
        $this->assertSame(CacheScope::Private, $policy->scopeFor('resources/read'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidConfigs(): array
    {
        return [
            'not an array' => ['yes'],
            'negative ttl' => [['ttl' => -1]],
            'unknown scope' => [['scope' => 'shared']],
            'method entry not an array' => [['methods' => ['tools/list' => 5]]],
        ];
    }

    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigFails(mixed $config): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^"Mcp\.server\.cache/');

        CacheHints::fromConfig($config);
    }
}
