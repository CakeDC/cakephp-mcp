<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\TestSuite;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Tools that notify (TestApp\McpProgress\SlowTools) under McpTestTrait.
 */
class McpTestTraitProgressTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpProgress']);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testProgressAndLogsAreRecorded(): void
    {
        $this->mcpCallTool('import_orders');

        $this->assertMcpToolSucceeded();
        $this->assertSame('Imported 10 orders.', $this->mcpResultText());
        $this->assertMcpProgressSent(2);
        $this->assertSame(
            ['Read the file', 'Saved the orders'],
            array_column(array_column($this->mcpNotifications('notifications/progress'), 'params'), 'message'),
        );
        $this->assertMcpNotificationSent('notifications/message');
    }

    public function testAToolWithoutNotificationsRecordsNone(): void
    {
        $this->mcpCallTool('quick');

        $this->assertMcpToolSucceeded();
        $this->assertSame([], $this->mcpNotifications());
    }

    public function testProgressAssertionFailsWithoutProgress(): void
    {
        $this->mcpCallTool('quick');

        $this->expectException(AssertionFailedError::class);
        $this->assertMcpProgressSent();
    }

    public function testAToolThatAsksTheClientFailsInsteadOfHanging(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('The tool asked the client something (sampling or elicitation)');

        $this->mcpCallTool('summarize_orders');
    }
}
