<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Server;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;

/**
 * The "Several servers" example of docs/configuration.md, run as written: the default server never registers
 * the admin server's tools. `excludeDirs` is relative to each scanned directory, as the docs say.
 */
class DocumentedServersExampleTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testTheDefaultServerOfTheExampleDoesNotRegisterTheAdminTools(): void
    {
        $config = $this->example();
        $this->assertSame(['src/McpAdmin'], $config['servers']['admin']['discovery']['scanDirs'] ?? null);
        Configure::write('Mcp', $config);
        // What every user may do, without the rules of the example's host application.
        Configure::write('Mcp.authorization.authorizer', 'CakeDC\Mcp\Authorization\AllowAllToolAuthorizer');

        $default = array_column($this->mcpListTools(), 'name');
        $this->mcpUseServer('admin');
        $admin = array_column($this->mcpListTools(), 'name');

        $this->assertContains('echo', $default);
        $this->assertNotContains('refund_order', $default);
        $this->assertSame(['refund_order'], $admin);
    }

    public function testMcpCheckFindsNoNestedDirectoryInTheExample(): void
    {
        Configure::write('Mcp', $this->example());

        $this->exec('mcp check');

        $this->assertOutputNotContains('[WARN] Servers');
    }

    public function testExcludeDirsIsRelativeToEachScannedDirectory(): void
    {
        Configure::write('Mcp.discovery', ['scanDirs' => ['src/McpNested'], 'excludeDirs' => ['src/McpNested/Admin']]);
        $this->assertContains('refund_order', array_column($this->mcpListTools(), 'name'));

        Configure::write('Mcp.discovery', ['scanDirs' => ['src/McpNested'], 'excludeDirs' => ['Admin']]);
        $this->assertSame(['opening_hours'], array_column($this->mcpListTools(), 'name'));
    }

    /**
     * @return array<string, mixed> The `Mcp` config of the example.
     */
    private function example(): array
    {
        $docs = (string)file_get_contents(dirname(__DIR__, 3) . '/docs/configuration.md');
        $section = substr($docs, (int)strpos($docs, "\n## Several servers"));
        $this->assertSame(1, preg_match('/```php\n(.*?)```/s', $section, $match));
        $file = TMP . 'documented_servers_example.php';
        file_put_contents(
            $file,
            "<?php\nuse CakeDC\\Mcp\\Authorization\\RbacToolAuthorizer;\n\nreturn [\n" . $match[1] . "];\n",
        );
        $config = require $file;
        unlink($file);
        $this->assertIsArray($config);

        return $config['Mcp'];
    }
}
