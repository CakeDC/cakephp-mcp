<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Command\Bake;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Schema\SchemaLint;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use Mcp\Server;
use stdClass;

/**
 * The bake commands write into the BakeTarget test plugin, and tearDown removes what they wrote.
 */
class McpBakeCommandsTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    private const PLUGIN = ROOT . 'plugins' . DS . 'BakeTarget' . DS;

    /**
     * @var list<string>
     */
    private const BAKED = [
        'src/Mcp/ListOrdersTool.php',
        'src/Mcp/OrderGuideResource.php',
        'src/Mcp/WeeklyReportPrompt.php',
        'tests/TestCase/Mcp/ListOrdersToolTest.php',
        'tests/TestCase/Mcp/OrderGuideResourceTest.php',
        'tests/TestCase/Mcp/WeeklyReportPromptTest.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Not autoloaded: the test plugin exists only for these tests.
        require_once self::PLUGIN . 'src' . DS . 'BakeTargetPlugin.php';
        $this->loadPlugins(['Bake', 'BakeTarget' => ['path' => self::PLUGIN]]);
    }

    protected function tearDown(): void
    {
        foreach (self::BAKED as $file) {
            if (is_file(self::PLUGIN . $file)) {
                unlink(self::PLUGIN . $file);
            }
        }
        foreach (['src/Mcp', 'tests/TestCase/Mcp', 'tests/TestCase'] as $dir) {
            if (is_dir(self::PLUGIN . $dir)) {
                rmdir(self::PLUGIN . $dir);
            }
        }
        parent::tearDown();
    }

    public function testBakesAToolAndItsTest(): void
    {
        $this->exec('bake mcp_tool ListOrders --plugin BakeTarget --force');

        $this->assertExitSuccess();
        $class = $this->baked('src/Mcp/ListOrdersTool.php');
        $this->assertStringContainsString('namespace BakeTarget\Mcp;', $class);
        $this->assertStringContainsString('class ListOrdersTool', $class);
        $this->assertStringContainsString("name: 'list_orders'", $class);
        $test = $this->baked('tests/TestCase/Mcp/ListOrdersToolTest.php');
        $this->assertStringContainsString('namespace BakeTarget\Test\TestCase\Mcp;', $test);
        $this->assertStringContainsString('use McpTestTrait;', $test);
        $this->assertStringContainsString("mcpCallTool('list_orders'", $test);
    }

    public function testThePluginDotNameForm(): void
    {
        $this->exec('bake mcp_tool BakeTarget.ListOrders --force');

        $this->assertExitSuccess();
        $class = $this->baked('src/Mcp/ListOrdersTool.php');
        $this->assertStringContainsString('class ListOrdersTool', $class);
        $this->assertStringContainsString("name: 'list_orders'", $class);
        $this->assertFileExists(self::PLUGIN . 'tests/TestCase/Mcp/ListOrdersToolTest.php');
    }

    public function testTheSuffixIsNotDoubled(): void
    {
        $this->exec('bake mcp_tool ListOrdersTool --plugin BakeTarget --force --no-test');

        $this->assertExitSuccess();
        $this->assertFileExists(self::PLUGIN . 'src/Mcp/ListOrdersTool.php');
        $this->assertStringContainsString("name: 'list_orders'", $this->baked('src/Mcp/ListOrdersTool.php'));
    }

    public function testBakesAResourceAndAPrompt(): void
    {
        $this->exec('bake mcp_resource OrderGuide --plugin BakeTarget --force');
        $this->assertExitSuccess();
        $this->assertStringContainsString("uri: 'app://order-guide'", $this->baked('src/Mcp/OrderGuideResource.php'));
        $this->assertFileExists(self::PLUGIN . 'tests/TestCase/Mcp/OrderGuideResourceTest.php');

        $this->exec('bake mcp_prompt WeeklyReport --plugin BakeTarget --force');
        $this->assertExitSuccess();
        $this->assertStringContainsString("name: 'weekly_report'", $this->baked('src/Mcp/WeeklyReportPrompt.php'));
        $this->assertFileExists(self::PLUGIN . 'tests/TestCase/Mcp/WeeklyReportPromptTest.php');
    }

    public function testNoTestOption(): void
    {
        $this->exec('bake mcp_tool ListOrders --plugin BakeTarget --force --no-test');

        $this->assertExitSuccess();
        $this->assertFileDoesNotExist(self::PLUGIN . 'tests/TestCase/Mcp/ListOrdersToolTest.php');
    }

    public function testBakedClassesWork(): void
    {
        foreach (['mcp_tool ListOrders', 'mcp_resource OrderGuide', 'mcp_prompt WeeklyReport'] as $command) {
            $this->exec(sprintf('bake %s --plugin BakeTarget --force --no-test', $command));
            $this->assertExitSuccess();
        }
        foreach (['ListOrdersTool', 'OrderGuideResource', 'WeeklyReportPrompt'] as $class) {
            require_once self::PLUGIN . 'src/Mcp/' . $class . '.php';
        }

        $transport = new RecordingTransport(array_map('json_encode', [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name' => 'list_orders',
                'arguments' => ['query' => 'late', 'limit' => 5],
            ]],
            ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/read', 'params' => ['uri' => 'app://order-guide']],
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'prompts/get', 'params' => ['name' => 'weekly_report']],
        ]));
        Server::builder()
            ->setServerInfo('bake', '1')
            ->setDiscovery(rtrim(self::PLUGIN, DS), ['src/Mcp'])
            ->build()
            ->run($transport);

        $tools = $transport->responseFor(2)['result']['tools'] ?? [];
        $this->assertSame(['list_orders'], array_column($tools, 'name'));
        $this->assertSame([], SchemaLint::problems($tools[0]));
        $this->assertFalse($transport->responseFor(3)['result']['isError'] ?? true, (string)json_encode($transport->responseFor(3)));
        $this->assertNotEmpty($transport->responseFor(4)['result']['contents'] ?? null, (string)json_encode($transport->responseFor(4)));
        $this->assertNotEmpty($transport->responseFor(5)['result']['messages'] ?? null, (string)json_encode($transport->responseFor(5)));
    }

    private function baked(string $file): string
    {
        $this->assertFileExists(self::PLUGIN . $file);

        return (string)file_get_contents(self::PLUGIN . $file);
    }
}
