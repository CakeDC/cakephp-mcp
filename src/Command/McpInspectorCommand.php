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
use Closure;

/**
 * `bin/cake mcp inspector`: opens the MCP Inspector (a web app, run with npx)
 * already pointed at this server, over stdio or at the HTTP endpoint.
 */
class McpInspectorCommand extends Command
{
    /**
     * The npm package of the Inspector. Pinned to the major version whose
     * configuration format this command writes.
     *
     * @var string
     */
    public const PACKAGE = '@modelcontextprotocol/inspector@2';

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
        return 'Open the MCP Inspector on this server, over stdio or HTTP. Needs Node.js 22.19 or later.';
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
            ->addOption('env', [
                'multiple' => true,
                'help' => 'Environment variable passed to the stdio server, by name. The Inspector passes only '
                    . 'a few (PATH, HOME...), so name the ones your app reads. Repeat it for more.',
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
        $headers = $this->listOption($args, 'header');
        $env = $this->listOption($args, 'env');
        $http = $args->getOption('http') === true || is_string($url);
        if ($headers !== [] && !$http) {
            $io->error('Headers are only sent over HTTP. Add --http or --url.');

            return static::CODE_ERROR;
        }
        if ($env !== [] && $http) {
            $io->error('Environment variables only reach the stdio server. Drop --http and --url.');

            return static::CODE_ERROR;
        }

        $server = $http
            ? $this->httpServer(is_string($url) ? $url : null, $headers, $io)
            : $this->stdioServer($env, $io);
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
            $io->error('Could not run npx. The Inspector needs Node.js 22.19 or later.');
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
        $process = null;
        $restore = $this->outliveInterrupts($process);
        try {
            $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, rtrim(ROOT, DS));
            if ($process === false) {
                $io->error('Could not start the Inspector. It needs Node.js 22.19 or later, with npx.');

                return static::CODE_ERROR;
            }

            return proc_close($process);
        } finally {
            $restore();
        }
    }

    /**
     * Ctrl+C reaches PHP as well as the Inspector, both being in the terminal's
     * process group. Left alone, PHP would die at once and leave the
     * configuration file, and any token in it, on disk. These handlers keep PHP
     * waiting for the Inspector to exit so the file is deleted; SIGTERM and
     * SIGHUP are passed on to the Inspector. They are handlers and not
     * `SIG_IGN`, which the Inspector would inherit and then ignore Ctrl+C.
     *
     * @param resource|false|null $process The Inspector process, once started.
     * @return \Closure Restores the previous behavior.
     */
    private function outliveInterrupts(mixed &$process): Closure
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            $async = pcntl_async_signals(true);
            $forward = static function (int $signal) use (&$process): void {
                if (is_resource($process)) {
                    proc_terminate($process, $signal);
                }
            };
            $handlers = [SIGINT => static function (): void {
            }, SIGTERM => $forward, SIGHUP => $forward];
            $previous = [];
            foreach ($handlers as $signal => $handler) {
                $previous[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, $handler);
            }

            return static function () use ($previous, $async): void {
                foreach ($previous as $signal => $handler) {
                    pcntl_signal($signal, $handler);
                }
                pcntl_async_signals($async);
            };
        }
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            $handler = static function (int $event): void {
            };
            sapi_windows_set_ctrl_handler($handler);

            return static function () use ($handler): void {
                sapi_windows_set_ctrl_handler($handler, false);
            };
        }

        return static function (): void {
        };
    }

    /**
     * @param string $configFile Path of the configuration file.
     * @return list<string>
     */
    private function command(string $configFile): array
    {
        // On Windows npm installs npx as a batch file, which proc_open() only finds by its full name.
        $npx = PHP_OS_FAMILY === 'Windows' ? 'npx.cmd' : 'npx';

        return [$npx, '--yes', self::PACKAGE, '--config', $configFile];
    }

    /**
     * The values of a multiple option. Read raw: `getMultipleOption()` is
     * deprecated from Cake 5.2 and `getArrayOption()` only exists from 5.2.
     *
     * @param \Cake\Console\Arguments $args The arguments.
     * @param string $name The option.
     * @return list<string>
     */
    private function listOption(Arguments $args, string $name): array
    {
        $values = $args->getOptions()[$name] ?? [];

        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    /**
     * @param list<string> $env Names of the environment variables to pass on.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return array<string, mixed>|null
     */
    private function stdioServer(array $env, ConsoleIo $io): ?array
    {
        // ROOT has no trailing separator in the app skeleton.
        $cake = rtrim(ROOT, DS) . DS . 'bin' . DS . 'cake.php';
        if (!is_file($cake)) {
            $io->error(sprintf('`%s` does not exist, so the Inspector cannot start the stdio server.', $cake));

            return null;
        }

        $server = [
            'type' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$cake, 'mcp', 'server'],
            'cwd' => rtrim(ROOT, DS),
        ];
        foreach ($env as $name) {
            $value = getenv($name);
            if ($value === false) {
                $io->error(sprintf('The environment variable `%s` is not set.', $name));

                return null;
            }
            $server['env'][$name] = $value;
        }
        $server['protocolEra'] = 'auto';

        return $server;
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
     * @return array<string, mixed> The same, with header and environment values hidden.
     */
    private function redact(array $config): array
    {
        foreach ($config['mcpServers'] as $name => $server) {
            foreach (['headers', 'env'] as $secrets) {
                foreach (array_keys($server[$secrets] ?? []) as $key) {
                    $config['mcpServers'][$name][$secrets][$key] = '***';
                }
            }
        }

        return $config;
    }

    /**
     * Writes the configuration to a file only the current user can read, since
     * headers and environment variables may carry secrets.
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
