<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Command;

use Cake\Console\CommandCollection;
use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Command\McpInspectorCommand;
use CakeDC\Mcp\McpPlugin;

class McpInspectorCommandTest extends TestCase
{
    private StubConsoleOutput $out;

    private StubConsoleOutput $err;

    /**
     * @var list<array{command: list<string>, config: array<string, mixed>}>
     */
    private array $launched = [];

    public function testIsRegisteredAsMcpInspector(): void
    {
        $commands = (new McpPlugin())->console(new CommandCollection());

        $this->assertSame(McpInspectorCommand::class, $commands->get('mcp inspector'));
    }

    public function testStdioStartsTheServerCommand(): void
    {
        $this->assertSame(0, $this->runCommand([]));

        $this->assertCount(1, $this->launched);
        $command = $this->launched[0]['command'];
        $this->assertSame(['npx', '--yes', '@modelcontextprotocol/inspector@2', '--config'], array_slice($command, 0, 4));
        $this->assertSame([
            'type' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [rtrim(ROOT, DS) . DS . 'bin' . DS . 'cake.php', 'mcp', 'server'],
            'cwd' => rtrim(ROOT, DS),
            'protocolEra' => 'auto',
        ], $this->launched[0]['config']['mcpServers']['cakephp-mcp-server'] ?? null);
    }

    public function testHttpUsesTheFullBaseUrlAndTheRoute(): void
    {
        Configure::write('App.fullBaseUrl', 'https://app.example.com/');

        $this->assertSame(0, $this->runCommand(['--http', '--header', 'Authorization: Bearer secret-token']));

        $this->assertSame([
            'type' => 'http',
            'url' => 'https://app.example.com/mcp',
            'headers' => ['Authorization' => 'Bearer secret-token'],
            'protocolEra' => 'auto',
        ], $this->launched[0]['config']['mcpServers']['cakephp-mcp-server'] ?? null);
    }

    public function testUrlOptionImpliesHttp(): void
    {
        $this->assertSame(0, $this->runCommand(['--url', 'http://localhost:8765/mcp']));

        $server = $this->launched[0]['config']['mcpServers']['cakephp-mcp-server'] ?? [];
        $this->assertSame('http', $server['type'] ?? null);
        $this->assertSame('http://localhost:8765/mcp', $server['url'] ?? null);
        $this->assertArrayNotHasKey('headers', $server);
    }

    public function testTheConfigFileIsRemovedAfterwards(): void
    {
        $this->runCommand([]);

        $this->assertFileDoesNotExist($this->launched[0]['command'][4]);
    }

    public function testDryRunPrintsWithoutLaunchingAndHidesHeaderValues(): void
    {
        $code = $this->runCommand(['--url', 'https://app.example.com/mcp', '--header', 'Authorization: Bearer secret-token', '--dry-run']);

        $this->assertSame(0, $code);
        $this->assertSame([], $this->launched);
        $output = implode("\n", $this->out->messages());
        $this->assertStringContainsString('"url": "https://app.example.com/mcp"', $output);
        $this->assertStringContainsString('"Authorization": "***"', $output);
        $this->assertStringNotContainsString('secret-token', $output);
        $this->assertStringContainsString('npx --yes @modelcontextprotocol/inspector@2 --config', $output);
    }

    public function testEnvironmentVariablesArePassedToTheStdioServer(): void
    {
        putenv('MCP_INSPECTOR_TEST=from-the-shell');
        try {
            $this->assertSame(0, $this->runCommand(['--env', 'MCP_INSPECTOR_TEST']));
            $this->assertSame(0, $this->runCommand(['--env', 'MCP_INSPECTOR_TEST', '--dry-run']));
        } finally {
            putenv('MCP_INSPECTOR_TEST');
        }

        $server = $this->launched[0]['config']['mcpServers']['cakephp-mcp-server'] ?? [];
        $this->assertSame(['MCP_INSPECTOR_TEST' => 'from-the-shell'], $server['env'] ?? null);
        $output = implode("\n", $this->out->messages());
        $this->assertStringContainsString('"MCP_INSPECTOR_TEST": "***"', $output);
        $this->assertStringNotContainsString('from-the-shell', $output);
    }

    public function testMissingEnvironmentVariablesAreRejected(): void
    {
        $this->assertSame(1, $this->runCommand(['--env', 'MCP_INSPECTOR_NOT_SET']));

        $this->assertSame([], $this->launched);
        $this->assertStringContainsString('MCP_INSPECTOR_NOT_SET', implode("\n", $this->err->messages()));
    }

    public function testEnvironmentVariablesNeedStdio(): void
    {
        $this->assertSame(1, $this->runCommand(['--http', '--env', 'PATH']));

        $this->assertStringContainsString('stdio', implode("\n", $this->err->messages()));
    }

    public function testLaunchRunsTheCommandAndRestoresSignalHandlers(): void
    {
        $before = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler(SIGINT) : null;
        $command = new class extends McpInspectorCommand {
            /**
             * @param list<string> $command
             */
            public function launchNow(array $command): int
            {
                return $this->launch($command, new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput()));
            }
        };

        $this->assertSame(3, $command->launchNow([PHP_BINARY, '-r', 'exit(3);']));
        if (function_exists('pcntl_signal_get_handler')) {
            $this->assertSame($before, pcntl_signal_get_handler(SIGINT));
        }
    }

    public function testHttpWithoutABaseUrlAsksForOne(): void
    {
        Configure::write('App.fullBaseUrl', false);

        $this->assertSame(1, $this->runCommand(['--http']));

        $this->assertSame([], $this->launched);
        $this->assertStringContainsString('--url', implode("\n", $this->err->messages()));
    }

    public function testHttpWithTheRouteDisabledFails(): void
    {
        Configure::write('Mcp.route', false);

        $this->assertSame(1, $this->runCommand(['--http']));

        $this->assertStringContainsString('Mcp.route', implode("\n", $this->err->messages()));
    }

    public function testMalformedHeadersAreRejected(): void
    {
        $this->assertSame(1, $this->runCommand(['--url', 'https://app.example.com/mcp', '--header', 'no-colon']));

        $this->assertSame([], $this->launched);
        $this->assertStringContainsString('"Name: Value"', implode("\n", $this->err->messages()));
    }

    public function testHeadersNeedHttp(): void
    {
        $this->assertSame(1, $this->runCommand(['--header', 'Authorization: Bearer x']));

        $this->assertStringContainsString('--http', implode("\n", $this->err->messages()));
    }

    public function testExitCodeOfTheInspectorIsReturned(): void
    {
        $this->assertSame(3, $this->runCommand([], 3));
    }

    /**
     * @param list<string> $argv Command arguments.
     */
    private function runCommand(array $argv, int $exitCode = 0): ?int
    {
        $this->out = new StubConsoleOutput();
        $this->err = new StubConsoleOutput();
        $io = new ConsoleIo($this->out, $this->err, new StubConsoleInput([]));

        $launched = &$this->launched;
        $command = new class ($launched, $exitCode) extends McpInspectorCommand {
            /**
             * @param list<array{command: list<string>, config: array<string, mixed>}> $launched
             */
            public function __construct(private array &$launched, private readonly int $exitCode)
            {
            }

            protected function launch(array $command, ConsoleIo $io): int
            {
                $config = json_decode((string)file_get_contents($command[4]), true);
                $this->launched[] = ['command' => $command, 'config' => is_array($config) ? $config : []];

                return $this->exitCode;
            }
        };

        return $command->run($argv, $io);
    }
}
