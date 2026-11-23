<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Catalog;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Catalog\CatalogScopes;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;

class CatalogScopesTest extends TestCase
{
    private const PATTERNS = ['order_*'];

    public function testADirectCallNeedsWhatThePolicySays(): void
    {
        $this->assertSame(['mcp', 'orders:write'], CatalogScopes::required($this->policy(), $this->call('order_cancel'), self::PATTERNS));
    }

    public function testCallToolsAddsTheScopesOfItsInnerCalls(): void
    {
        $payload = $this->call('call_tools', ['calls' => [
            ['name' => 'order_status', 'arguments' => []],
            ['name' => 'order_cancel', 'arguments' => []],
        ]]);

        $this->assertSame(['mcp', 'orders:read', 'orders:write'], CatalogScopes::required($this->policy(), $payload, self::PATTERNS));
    }

    public function testToolsOutsideTheCatalogAddNothing(): void
    {
        $payload = $this->call('call_tools', ['calls' => [['name' => 'admin_reset', 'arguments' => []]]]);

        $this->assertSame(['mcp'], CatalogScopes::required($this->policy(), $payload, self::PATTERNS));
    }

    public function testABatchAddsUpEveryMessage(): void
    {
        $payload = [
            $this->call('call_tools', ['calls' => [['name' => 'order_status']]]),
            $this->call('call_tools', ['calls' => [['name' => 'order_cancel']]]),
        ];

        $this->assertSame(['mcp', 'orders:read', 'orders:write'], CatalogScopes::required($this->policy(), $payload, self::PATTERNS));
    }

    public function testMalformedCallsAreIgnored(): void
    {
        $payload = $this->call('call_tools', ['calls' => 'all']);

        $this->assertSame(['mcp'], CatalogScopes::required($this->policy(), $payload, self::PATTERNS));
        $this->assertSame([], CatalogScopes::required(new ScopePolicy(), null, self::PATTERNS));
    }

    private function policy(): ScopePolicy
    {
        return new ScopePolicy(
            default: ['mcp'],
            tools: [
                'order_status' => ['orders:read'],
                'order_cancel' => ['orders:write'],
                'admin_reset' => ['admin'],
            ],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => $tool,
            'arguments' => $arguments,
        ]];
    }
}
