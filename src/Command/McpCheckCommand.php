<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Http\ServerRequest;
use Cake\Log\Engine\BaseLog;
use Cake\Log\Engine\ConsoleLog;
use Cake\Log\Log;
use CakeDC\Mcp\Audit\AuditSettings;
use CakeDC\Mcp\Audit\AuditWriter;
use CakeDC\Mcp\Authorization\AllowAllToolAuthorizer;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\Server\ServerFactory;
use ReflectionProperty;
use Throwable;

/**
 * `bin/cake mcp check`: how the MCP endpoint authenticates its callers, and what would stop remote
 * clients from connecting. Makes no network call.
 */
class McpCheckCommand extends Command
{
    /**
     * Where the documentation lives, for the links in the messages.
     */
    private const DOCS = 'https://github.com/CakeDC/cakephp-mcp/blob/1.next-cake4/docs/';

    /**
     * Tools named in a message before it says "and N more".
     */
    private const LISTED_TOOLS = 10;

    /**
     * @var list<string>|null
     */
    private ?array $tools = null;

    /**
     * @param \CakeDC\Mcp\Server\ServerFactory|null $serverFactory Builds the server, as the endpoint does.
     */
    public function __construct(private readonly ?ServerFactory $serverFactory = null)
    {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'mcp check';
    }

    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Check the MCP endpoint configuration (authentication, hosts, CSRF, tools) without network calls.';
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $failed = false;
        foreach ($this->checks() as $name => $probe) {
            try {
                [$status, $message] = $probe();
            } catch (Throwable $e) {
                [$status, $message] = ['fail', $e->getMessage()];
            }
            $tag = ['pass' => 'success', 'warn' => 'warning', 'fail' => 'error'][$status];
            $io->out(sprintf('<%s>[%s] %s: %s</%s>', $tag, strtoupper($status), $name, $message, $tag));
            $failed = $failed || $status === 'fail';
        }

        return $failed ? static::CODE_ERROR : static::CODE_SUCCESS;
    }

    /**
     * The checks, in order.
     *
     * @return array<string, callable(): array{0: 'pass'|'warn'|'fail', 1: string}>
     */
    protected function checks(): array
    {
        return [
            'Endpoint' => fn(): array => $this->endpoint(),
            'Authentication' => fn(): array => $this->authentication(),
            'Allowed hosts' => fn(): array => $this->hosts(),
            'CSRF' => fn(): array => $this->csrf(),
            'Tools' => fn(): array => $this->toolsCheck(),
            'Authorizer' => fn(): array => $this->authorizerCheck(),
            'Logging' => fn(): array => $this->logging(),
            'Audit' => fn(): array => $this->audit(),
        ];
    }

    /**
     * @return \CakeDC\Mcp\Server\ServerFactory
     */
    private function factory(): ServerFactory
    {
        return $this->serverFactory ?? new ServerFactory(new Container(), new CurrentIdentity());
    }

    /**
     * The tools the server offers, listed once per run.
     *
     * @return list<string>
     */
    private function toolNames(): array
    {
        return $this->tools ??= $this->factory()->toolNames();
    }

    /**
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function toolsCheck(): array
    {
        $tools = $this->toolNames();
        if ($tools !== []) {
            return ['pass', sprintf('%d tools: %s', count($tools), self::names($tools))];
        }
        $dirs = array_filter((array)McpConfig::get('discovery.scanDirs'), 'is_string');

        return ['warn', sprintf(
            'no tool found in %s (Mcp.discovery.scanDirs). A tool is a public method with #[McpTool] in a class '
            . 'with a namespace, inside those directories; other classes are skipped without an error. See %s',
            $dirs === [] ? 'no directory' : implode(', ', $dirs),
            self::DOCS . 'tools.md',
        )];
    }

    /**
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function authorizerCheck(): array
    {
        $authorizer = $this->factory()->authorizer();
        if ($authorizer instanceof AllowAllToolAuthorizer) {
            return ['pass', 'AllowAllToolAuthorizer: every identified caller may call every tool'];
        }
        if (!$authorizer instanceof RbacToolAuthorizer) {
            return ['pass', $authorizer::class];
        }
        $docs = self::DOCS . 'authorization.md#rbactoolauthorizer-cakedcauth';
        $rules = $authorizer->permissions();
        if ($rules === []) {
            return ['warn', sprintf(
                "RbacToolAuthorizer has no rules, so no one can call a tool. Add rules for plugin '%s', "
                . "controller '%s' and the tool as action, see %s",
                RbacToolAuthorizer::PLUGIN,
                RbacToolAuthorizer::CONTROLLER,
                $docs,
            )];
        }
        $forTools = count(array_filter($rules, fn(mixed $rule): bool => is_array($rule) && self::allowsTools($rule)));
        if ($forTools === 0) {
            return ['warn', sprintf(
                "none of the %d rules of RbacToolAuthorizer allows plugin '%s', controller '%s', so no one can "
                . 'call a tool, see %s',
                count($rules),
                RbacToolAuthorizer::PLUGIN,
                RbacToolAuthorizer::CONTROLLER,
                $docs,
            )];
        }

        return ['pass', sprintf('RbacToolAuthorizer, %d of %d rules for the tools', $forTools, count($rules))];
    }

    /**
     * Whether an Rbac rule can allow a tool call: it allows, names a controller and an action, and its
     * plugin, controller and prefix match those of a tool (`*`, a list, a missing key). A rule this
     * cannot judge (an inverted key, a callable) counts.
     *
     * @param array<mixed> $rule An Rbac rule.
     * @return bool
     */
    private static function allowsTools(array $rule): bool
    {
        if (($rule['allowed'] ?? true) === false) {
            return false;
        }
        $has = fn(string $key): bool => array_key_exists($key, $rule) || array_key_exists('*' . $key, $rule);
        if (!$has('controller') || !$has('action')) {
            return false;
        }
        $expected = ['plugin' => RbacToolAuthorizer::PLUGIN, 'controller' => RbacToolAuthorizer::CONTROLLER];
        foreach ($expected as $key => $value) {
            if (array_key_exists($key, $rule) && !self::matches($rule[$key], $value)) {
                return false;
            }
        }
        $prefix = $rule['prefix'] ?? null;

        return !is_string($prefix) || $prefix === '' || $prefix === '*';
    }

    /**
     * @param mixed $value A rule value.
     * @param string $param The request value.
     * @return bool
     */
    private static function matches(mixed $value, string $param): bool
    {
        if (!is_string($value) && is_callable($value)) {
            return true;
        }
        if (is_array($value)) {
            return in_array($param, $value, true) || in_array('*', $value, true);
        }

        return $value === '*' || $value === $param;
    }

    /**
     * @param list<string> $names Tool names.
     * @return string The first ones, and how many more.
     */
    private static function names(array $names): string
    {
        $listed = implode(', ', array_slice($names, 0, self::LISTED_TOOLS));
        $more = count($names) - self::LISTED_TOOLS;

        return $more > 0 ? sprintf('%s and %d more', $listed, $more) : $listed;
    }

    /**
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function logging(): array
    {
        $engines = self::enginesFor('error', [McpLogger::SCOPE]);
        if ($engines !== []) {
            return ['pass', sprintf('the %s scope goes to %s', McpLogger::SCOPE, implode(', ', $engines))];
        }

        return ['warn', sprintf(
            'no Log engine takes the errors of the %1$s scope, so a failing tool only answers "Error while '
            . 'executing tool" and its error is not kept anywhere (the debug and error engines of the '
            . "application skeleton have 'scopes' => false). Add a FileLog with 'scopes' => ['%1\$s'], see %2\$s",
            McpLogger::SCOPE,
            self::DOCS . 'configuration.md#logging',
        )];
    }

    /**
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function audit(): array
    {
        $settings = AuditSettings::fromConfig();
        if (!$settings->enabled) {
            return ['pass', 'off'];
        }
        if (!$settings->log) {
            return ['pass', sprintf('%s to the %s event only', implode(', ', $settings->methods), AuditWriter::EVENT)];
        }
        if (self::enginesFor('info', [AuditWriter::SCOPE, McpLogger::SCOPE]) !== []) {
            $methods = implode(', ', $settings->methods);

            return ['pass', sprintf('%s to the %s log scope', $methods, AuditWriter::SCOPE)];
        }

        return ['warn', sprintf(
            'on, but no Log engine takes its lines (scopes %1$s and %2$s, level info), so they are lost. '
            . "Add a FileLog with 'scopes' => ['%1\$s'], see %3\$s",
            AuditWriter::SCOPE,
            McpLogger::SCOPE,
            self::DOCS . 'configuration.md#audit-log',
        )];
    }

    /**
     * The Log engines that write a message of $level with $scopes, decided as Log::write() does: an engine
     * given as an object counts with its own settings, and a logger that is not a Cake engine takes
     * everything. Console engines do not count.
     *
     * @param string $level A log level.
     * @param list<string> $scopes The scopes of the message.
     * @return list<string> Names of the engines.
     */
    private static function enginesFor(string $level, array $scopes): array
    {
        $engines = [];
        foreach (Log::configured() as $name) {
            $logger = Log::engine((string)$name);
            // The console engines (stdout, stderr) only exist while a command runs, not for web requests.
            if ($logger instanceof ConsoleLog) {
                continue;
            }
            $levels = $logger instanceof BaseLog ? $logger->levels() : null;
            $listens = $logger instanceof BaseLog ? $logger->scopes() : [];
            $inScope = $listens === [] || (is_array($listens) && array_intersect($scopes, $listens) !== []);
            if (($levels === null || $levels === [] || in_array($level, $levels, true)) && $inScope) {
                $engines[] = (string)$name;
            }
        }

        return $engines;
    }

    /**
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function endpoint(): array
    {
        $route = McpConfig::get('route');
        if (!is_string($route) || $route === '') {
            return ['warn', 'Mcp.route is disabled: only stdio (bin/cake mcp server) is served'];
        }

        return ['pass', rtrim((string)Configure::read('App.fullBaseUrl'), '/') . $route];
    }

    /**
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function authentication(): array
    {
        // Throws for the oauth and external modes of 2.x, which execute() reports as a failure.
        McpConfig::assertHostAuthMode();

        return ['pass', sprintf(
            'host mode: the identity your authentication puts in the "%s" request attribute',
            (string)McpConfig::get('identity.attribute'),
        )];
    }

    /**
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function hosts(): array
    {
        return ['pass', implode(', ', McpConfig::allowedHosts())];
    }

    /**
     * Whether the application's CSRF middleware skips POST requests to the MCP route.
     *
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function csrf(): array
    {
        $route = McpConfig::get('route');
        if (!is_string($route) || $route === '') {
            return ['pass', 'no HTTP endpoint'];
        }
        $app = $this->application();
        if (is_string($app)) {
            return ['warn', $app . '; its middleware was not inspected'];
        }
        foreach ($app->middleware(new MiddlewareQueue()) as $middleware) {
            if (!$middleware instanceof CsrfProtectionMiddleware) {
                continue;
            }
            $callback = (new ReflectionProperty(CsrfProtectionMiddleware::class, 'skipCheckCallback'))
                ->getValue($middleware);
            $request = new ServerRequest(['url' => $route, 'environment' => ['REQUEST_METHOD' => 'POST']]);

            return is_callable($callback) && $callback($request) === true
                ? ['pass', 'the MCP route is skipped']
                : ['fail', 'add McpConfig::isEndpoint($request) to the CsrfProtectionMiddleware skipCheckCallback'];
        }

        return ['pass', 'no CsrfProtectionMiddleware in the queue'];
    }

    /**
     * The host application, without replacing the running plugin collection; or why it cannot be built.
     *
     * @return \Cake\Http\BaseApplication|string
     */
    private function application(): BaseApplication|string
    {
        $class = Configure::read('App.namespace') . '\\Application';
        if (!class_exists($class)) {
            return 'could not find the application class to inspect it';
        }
        // CakePHP 4 applications share the running plugin collection (Plugin::getCollection()),
        // so building one replaces nothing.
        $app = new $class(CONFIG);

        return $app instanceof BaseApplication ? $app : 'the application class is not a BaseApplication';
    }
}
