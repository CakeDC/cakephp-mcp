<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Utility\Text;
use CakeDC\Mcp\McpConfig;

/**
 * `bin/cake mcp inspector`: opens the MCP Inspector (a web app, run with npx)
 * already pointed at this server, over stdio or at the HTTP endpoint.
 */
class McpInspectorCommand extends Command
{
    /**
     * The npm package of the Inspector.
     *
     * @var string
     */
    public const PACKAGE = '@modelcontextprotocol/inspector';

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'mcp inspector';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Open the MCP Inspector on this server, over stdio or HTTP. Needs Node.js.';
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription(static::getDescription())
            ->addOption('http', [
                'boolean' => true,
                'help' => 'Inspect the HTTP endpoint instead of starting `bin/cake mcp server`.',
            ])
            ->addOption('url', [
                'help' => 'URL of the HTTP endpoint (implies --http). Defaults to App.fullBaseUrl plus Mcp.route.',
            ])
            ->addOption('header', [
                'multiple' => true,
                'help' => 'Header sent to the HTTP endpoint, as "Name: Value". Repeat it for more headers.',
            ])
            ->addOption('dry-run', [
                'boolean' => true,
                'help' => 'Print the Inspector configuration and command without starting it.',
            ]);
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $url = $args->getOption('url');
        // Read raw: getMultipleOption() is deprecated from Cake 5.2 and getArrayOption() is new in 5.2.
        $headers = $args->getOptions()['header'] ?? [];
        $headers = is_array($headers) ? array_values(array_filter($headers, 'is_string')) : [];
        $http = $args->getOption('http') === true || is_string($url);
        if ($headers !== [] && !$http) {
            $io->error('Headers are only sent over HTTP. Add --http or --url.');

            return static::CODE_ERROR;
        }

        $server = $http ? $this->httpServer(is_string($url) ? $url : null, $headers, $io) : $this->stdioServer($io);
        if ($server === null) {
            return static::CODE_ERROR;
        }
        $config = ['mcpServers' => [$this->serverName() => $server]];

        if ($args->getOption('dry-run') === true) {
            $io->out((string)json_encode($this->redact($config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $io->out(implode(' ', $this->command('<config file>')));

            return static::CODE_SUCCESS;
        }

        $file = $this->writeConfig($config);
        if ($file === null) {
            $io->error('Could not write the Inspector configuration file.');

            return static::CODE_ERROR;
        }
        $io->out(sprintf('Opening the MCP Inspector on %s. Stop it with Ctrl+C.', $server['url'] ?? 'stdio'));

        try {
            $code = $this->launch($this->command($file), $io);
        } finally {
            unlink($file);
        }
        if ($code === 127) {
            $io->error('Could not run npx. The Inspector needs Node.js 22 or later.');
        }

        return $code;
    }

    /**
     * Runs the Inspector in the foreground, attached to this terminal.
     *
     * @param list<string> $command The command and its arguments.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return int The exit code of the Inspector.
     */
    protected function launch(array $command, ConsoleIo $io): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, rtrim(ROOT, DS));
        if ($process === false) {
            $io->error('Could not start the Inspector.');

            return static::CODE_ERROR;
        }

        return proc_close($process);
    }

    /**
     * @param string $configFile Path of the configuration file.
     * @return list<string>
     */
    private function command(string $configFile): array
    {
        return ['npx', '--yes', self::PACKAGE, '--config', $configFile];
    }

    /**
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return array<string, mixed>|null
     */
    private function stdioServer(ConsoleIo $io): ?array
    {
        // ROOT has no trailing separator in the app skeleton.
        $cake = rtrim(ROOT, DS) . DS . 'bin' . DS . 'cake.php';
        if (!is_file($cake)) {
            $io->error(sprintf('`%s` does not exist, so the Inspector cannot start the stdio server.', $cake));

            return null;
        }

        return [
            'type' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$cake, 'mcp', 'server'],
            'cwd' => rtrim(ROOT, DS),
            'protocolEra' => 'auto',
        ];
    }

    /**
     * @param string|null $url The --url option.
     * @param list<string> $headers The --header options.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return array<string, mixed>|null
     */
    private function httpServer(?string $url, array $headers, ConsoleIo $io): ?array
    {
        if ($url === null) {
            $route = McpConfig::get('route');
            if (!is_string($route) || $route === '') {
                $io->error('The HTTP endpoint is disabled (`Mcp.route`). Pass --url to inspect another server.');

                return null;
            }
            $base = Configure::read('App.fullBaseUrl');
            if (!is_string($base) || $base === '') {
                $io->error('`App.fullBaseUrl` is not set, so the endpoint URL is unknown. Pass it with --url.');

                return null;
            }
            $url = rtrim($base, '/') . $route;
        }

        $server = ['type' => 'http', 'url' => $url];
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '') {
                $io->error(sprintf('Header `%s` is not in the "Name: Value" form.', $header));

                return null;
            }
            $server['headers'][trim($parts[0])] = trim($parts[1]);
        }
        $server['protocolEra'] = 'auto';

        return $server;
    }

    /**
     * @return string The key of the server in the Inspector configuration.
     */
    private function serverName(): string
    {
        return strtolower(Text::slug((string)McpConfig::get('server.name'))) ?: 'cakephp';
    }

    /**
     * @param array<string, mixed> $config The Inspector configuration.
     * @return array<string, mixed> The same, with header values hidden.
     */
    private function redact(array $config): array
    {
        foreach ($config['mcpServers'] as $name => $server) {
            foreach (array_keys($server['headers'] ?? []) as $header) {
                $config['mcpServers'][$name]['headers'][$header] = '***';
            }
        }

        return $config;
    }

    /**
     * Writes the configuration to a file only the current user can read, since
     * headers may carry a token.
     *
     * @param array<string, mixed> $config The Inspector configuration.
     * @return string|null The path, or null on failure.
     */
    private function writeConfig(array $config): ?string
    {
        $temp = tempnam(sys_get_temp_dir(), 'mcp-inspector-');
        if ($temp === false) {
            return null;
        }
        $file = $temp . '.json';
        if (!rename($temp, $file)) {
            unlink($temp);

            return null;
        }
        chmod($file, 0600);
        file_put_contents($file, (string)json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $file;
    }
}
