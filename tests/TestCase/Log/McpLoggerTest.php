<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Log;

use Cake\Log\Engine\ArrayLog;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Log\McpLogger;
use RuntimeException;

class McpLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::setConfig('mcp_test', ['className' => ArrayLog::class, 'scopes' => ['mcp']]);
    }

    protected function tearDown(): void
    {
        Log::drop('mcp_test');
        parent::tearDown();
    }

    public function testWritesToCakeLogUnderTheMcpScope(): void
    {
        (new McpLogger())->warning('Tool {name} failed', ['name' => 'echo']);

        $engine = Log::engine('mcp_test');
        $this->assertInstanceOf(ArrayLog::class, $engine);
        $lines = $engine->read();
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('warning', $lines[0]);
        $this->assertStringContainsString('Tool echo failed', $lines[0]);
    }

    public function testExceptionDetailsAndUnusedContextReachTheLog(): void
    {
        (new McpLogger())->error(
            'Unhandled error during tool execution',
            ['name' => 'echo', 'exception' => new RuntimeException('database exploded')],
        );

        $engine = Log::engine('mcp_test');
        $this->assertInstanceOf(ArrayLog::class, $engine);
        $line = $engine->read()[0];
        $this->assertStringContainsString('database exploded', $line);
        $this->assertStringContainsString('name=echo', $line);
    }

    public function testPlaceholderContextIsNotAppendedTwice(): void
    {
        (new McpLogger())->warning('Tool {name} failed', ['name' => 'echo']);

        $engine = Log::engine('mcp_test');
        $this->assertInstanceOf(ArrayLog::class, $engine);
        $this->assertStringNotContainsString('name=echo', $engine->read()[0]);
    }
}
