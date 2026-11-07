<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Command;

use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Authenticator\AuthenticationRequiredException;
use Authentication\Authenticator\ResultInterface;
use Authentication\Identifier\IdentifierCollection;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Container;
use Cake\Core\Plugin;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Http\ServerRequest;
use Cake\Log\Engine\BaseLog;
use Cake\Log\Engine\ConsoleLog;
use Cake\Log\Log;
use Cake\Routing\Exception\MissingRouteException;
use Cake\Routing\Router;
use CakeDC\Mcp\Audit\AuditSettings;
use CakeDC\Mcp\Audit\AuditWriter;
use CakeDC\Mcp\Authorization\AllowAllToolAuthorizer;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\OAuth\AuthSettings;
use CakeDC\Mcp\OAuth\ResourceServer;
use CakeDC\Mcp\Server\ServerFactory;
use CakeDC\OAuthServer\Authenticator\TokenAuthenticator;
use CakeDC\OAuthServer\OAuthServer;
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
    private const DOCS = 'https://github.com/CakeDC/cakephp-mcp/blob/2.next-cake5/docs/';

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
        return 'Check the MCP endpoint configuration (authentication, OAuth, hosts, CSRF) without network calls.';
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
            'Resource' => fn(): array => $this->resource(),
            'Metadata route' => fn(): array => $this->metadataRoute(),
            'Allowed hosts' => fn(): array => $this->hosts(),
            'CSRF' => fn(): array => $this->csrf(),
            'Authenticators' => fn(): array => $this->authenticators(),
            'Tools' => fn(): array => $this->toolsCheck(),
            'Authorizer' => fn(): array => $this->authorizerCheck(),
            'Tool scopes' => fn(): array => $this->toolScopes(),
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
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function toolScopes(): array
    {
        $settings = AuthSettings::fromConfig();
        if ($settings->mode === AuthSettings::HOST) {
            return ['pass', 'host mode, access tokens and their scopes do not apply'];
        }
        $tools = $this->toolNames();
        $problems = [];
        if ($settings->defaultScopes === [] && ($settings->methodScopes['tools/call'] ?? []) === []) {
            $scoped = array_keys(array_filter($settings->toolScopes, fn(array $scopes): bool => $scopes !== []));
            $free = array_values(array_diff($tools, $scoped));
            if ($free !== []) {
                $problems[] = 'tools that need no scope, so any valid token may call them: ' . self::names($free);
            }
        }
        $unknown = array_values(array_diff(array_keys($settings->toolScopes), $tools));
        if ($unknown !== []) {
            $problems[] = 'Mcp.auth.scopes.tools names tools that do not exist: ' . implode(', ', $unknown);
        }
        if ($problems === []) {
            return ['pass', 'every tool needs a scope'];
        }

        return ['warn', implode('; ', $problems) . ', see ' . self::DOCS . 'oauth.md'];
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
            . "application skeleton have 'scopes' => null). Add a FileLog with 'scopes' => ['%1\$s'], see %2\$s",
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
        $settings = AuthSettings::fromConfig();

        return ['pass', match ($settings->mode) {
            AuthSettings::OAUTH => 'oauth mode: access tokens of cakedc/cakephp-oauth-server in this application',
            AuthSettings::EXTERNAL => sprintf('external mode: JWT access tokens from %s', $settings->external?->issuer),
            default => sprintf(
                'host mode: the identity your authentication puts in the "%s" request attribute',
                (string)McpConfig::get('identity.attribute'),
            ),
        }];
    }

    /**
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function resource(): array
    {
        $settings = AuthSettings::fromConfig();
        if ($settings->mode === AuthSettings::HOST) {
            return ['pass', 'not used in host mode'];
        }
        $server = new ResourceServer($settings);
        $metadata = $server->metadata();
        $server->validator(new CurrentIdentity());
        $scopes = $metadata->getScopesSupported() ?? [];
        $message = sprintf(
            '%s, metadata at %s, scopes: %s',
            $metadata->getResource(),
            $metadata->getMetadataUrl(),
            $scopes === [] ? 'none declared' : implode(' ', $scopes),
        );
        $audiences = $settings->external?->audiences;
        if ($audiences === null) {
            return ['pass', $message];
        }
        $message .= '; accepted audiences: ' . implode(', ', $audiences);
        if (!in_array($settings->resource, $audiences, true)) {
            return ['warn', $message
                . '; the resource URL is not an accepted audience, so your identity provider must issue tokens '
                . 'with one of those "aud" values (an API identifier is fine, as long as it matches)'];
        }

        return ['pass', $message];
    }

    /**
     * Whether the RFC 9728 metadata route is connected and where the web server must serve it.
     *
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function metadataRoute(): array
    {
        $settings = AuthSettings::fromConfig();
        if ($settings->mode === AuthSettings::HOST) {
            return ['pass', 'not used in host mode'];
        }
        $route = McpConfig::get('route');
        if (!is_string($route) || $route === '') {
            return ['pass', 'no HTTP endpoint'];
        }
        try {
            $url = Router::url([
                'plugin' => 'CakeDC/Mcp',
                'controller' => 'ProtectedResource',
                'action' => 'metadata',
                'prefix' => false,
            ]);
        } catch (MissingRouteException) {
            return ['fail', 'the metadata route of the plugin is not connected: load the plugin routes'];
        }
        $advertised = (string)parse_url(
            (new ResourceServer($settings))->metadata()->getMetadataUrl(),
            PHP_URL_PATH,
        );
        if ($url !== $advertised) {
            return ['warn', sprintf(
                'the application serves the metadata at %s but the advertised URL path is %s; on a subdirectory '
                . 'install the web server must rewrite the well-known path to the application',
                $url,
                $advertised,
            )];
        }

        return ['pass', $url];
    }

    /**
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function hosts(): array
    {
        $allowed = McpConfig::allowedHosts();
        $settings = AuthSettings::fromConfig();
        if ($settings->mode === AuthSettings::HOST) {
            return ['pass', implode(', ', $allowed)];
        }
        $host = strtolower((string)parse_url($settings->resource, PHP_URL_HOST));

        return in_array($host, $allowed, true)
            ? ['pass', implode(', ', $allowed)]
            : ['fail', sprintf(
                'the resource host "%s" is not in Mcp.http.allowedHosts (%s), so its requests are rejected',
                $host,
                implode(', ', $allowed),
            )];
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
     * In oauth mode, whether an authenticator of the application answers this server's bearer tokens at
     * the MCP route before the MCP plugin sees them (the oauth plugin's Token authenticator does).
     *
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function authenticators(): array
    {
        $settings = AuthSettings::fromConfig();
        $route = McpConfig::get('route');
        if ($settings->mode !== AuthSettings::OAUTH || !is_string($route) || $route === '') {
            return ['pass', 'nothing to check outside oauth mode'];
        }
        if (!$this->oauthServerInstalled()) {
            return ['fail', ResourceServer::OAUTH_INSTALL_HINT];
        }
        $app = $this->application();
        if (is_string($app)) {
            return ['warn', $app . '; its authenticators were not inspected'];
        }
        $prefix = OAuthServer::server()->config()->tokenPrefix;
        $probe = (new ServerRequest(['url' => $route, 'environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withHeader('Authorization', 'Bearer ' . $prefix . '_at_' . str_repeat('A', 43));
        if (!$app instanceof AuthenticationServiceProviderInterface) {
            $configured = Configure::read('Auth.Authenticators');
            if (Plugin::isLoaded('CakeDC/Users') && is_array($configured)) {
                return $this->usersAuthenticators($configured, $settings, $probe);
            }

            return ['pass', 'the application provides no authentication service'];
        }
        try {
            $app->getAuthenticationService($probe)->authenticate($probe);
        } catch (AuthenticationRequiredException) {
            return ['fail', sprintf(
                'an authenticator of the application (the CakeDC/OAuthServer.Token one) answers access tokens '
                . 'at %s before the MCP plugin sees them; leave it out for the MCP route, see '
                . 'McpConfig::isEndpoint()',
                $route,
            )];
        }

        return ['pass', 'no authenticator answers access tokens at the MCP route first'];
    }

    /**
     * The resources and paths a Token authenticator of cakedc/cakephp-oauth-server accepts, from its resolve():
     * a list of them since 1.0.0, a single one before.
     *
     * @param array<mixed> $resolved What resolve() returned.
     * @return list<array{resource: string, paths: list<string>}>
     */
    private static function targets(array $resolved): array
    {
        $list = array_is_list($resolved) ? $resolved : [$resolved];
        $targets = [];
        foreach ($list as $target) {
            if (is_array($target) && is_string($target['resource'] ?? null) && is_array($target['paths'] ?? null)) {
                $targets[] = [
                    'resource' => $target['resource'],
                    'paths' => array_values(array_filter($target['paths'], 'is_string')),
                ];
            }
        }

        return $targets;
    }

    /**
     * cakedc/users builds the authentication service from `Auth.Authenticators` and runs it on every request,
     * the MCP route included, and the oauth plugin's integration puts its Token authenticator there. That
     * authenticator answers every token of the authorization server: below its paths it validates the token for
     * its resource, anywhere else it refuses it with 401. MCP tokens therefore get through only when it
     * validates the MCP resource at the MCP route, or when its class leaves the MCP endpoint alone.
     *
     * @param array<mixed> $configured `Auth.Authenticators`.
     * @param \CakeDC\Mcp\OAuth\AuthSettings $settings The `Mcp.auth` settings.
     * @param \Cake\Http\ServerRequest $probe A request with a token of this server at the MCP route.
     * @return array{0: 'pass'|'warn'|'fail', 1: string}
     */
    private function usersAuthenticators(array $configured, AuthSettings $settings, ServerRequest $probe): array
    {
        $metadata = (new ResourceServer($settings))->metadata();
        $resource = $metadata->getResource();
        $path = (string)parse_url($resource, PHP_URL_PATH);
        $warning = null;
        foreach ($configured as $name => $options) {
            $class = is_array($options) ? ($options['className'] ?? $name) : $name;
            $class = is_string($class) ? App::className($class, 'Authenticator', 'Authenticator') : null;
            if ($class === null || !is_a($class, TokenAuthenticator::class, true) || !is_array($options)) {
                continue;
            }
            unset($options['className']);
            $authenticator = new $class(new IdentifierCollection(), $options);
            $setting = 'Auth.Authenticators.' . $name;
            try {
                $targets = self::targets($authenticator->resolve());
            } catch (Throwable $e) {
                return ['fail', sprintf(
                    'the Token authenticator of cakedc/users (%s) refuses every access token it sees: %s',
                    $setting,
                    $e->getMessage(),
                )];
            }
            $result = $authenticator->authenticate($probe);
            if ($result->getStatus() === ResultInterface::FAILURE_CREDENTIALS_MISSING) {
                // Its class leaves the MCP endpoint alone.
                continue;
            }
            $covers = array_filter(
                $targets,
                fn(array $target): bool => $target['resource'] === $resource && self::within($path, $target['paths']),
            );
            if ($covers === []) {
                return ['fail', sprintf(
                    'the Token authenticator of cakedc/users (%s) runs on every request and refuses MCP access '
                    . 'tokens at %s: it accepts tokens for %s only. See "cakedc/users" in the OAuth '
                    . 'documentation of the plugin',
                    $setting,
                    $probe->getPath(),
                    implode('; ', array_map(
                        fn(array $target): string => $target['resource'] . ' below ' . implode(', ', $target['paths']),
                        $targets,
                    )),
                )];
            }
            if ($authenticator->getConfig('resourceMetadataUrl') !== $metadata->getMetadataUrl()) {
                $warning = sprintf(
                    'the Token authenticator of cakedc/users (%s) validates MCP access tokens at %s before the '
                    . 'MCP plugin, for the same resource, but the 401 it answers for a refused token does not name '
                    . 'the metadata; set its resourceMetadataUrl to %s',
                    $setting,
                    $probe->getPath(),
                    $metadata->getMetadataUrl(),
                );
            }
        }

        return $warning === null
            ? ['pass', 'no authenticator of cakedc/users refuses MCP access tokens at the MCP route']
            : ['warn', $warning];
    }

    /**
     * Whether $path is one of $prefixes or below one, on segment boundaries.
     *
     * @param string $path A normalized path.
     * @param list<string> $prefixes Normalized path prefixes.
     * @return bool
     */
    private static function within(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix === '/' || $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether cakedc/cakephp-oauth-server is installed.
     *
     * @return bool
     */
    protected function oauthServerInstalled(): bool
    {
        return class_exists(OAuthServer::class);
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
        $plugins = Plugin::getCollection();
        try {
            $app = new $class(CONFIG);
        } finally {
            Plugin::setCollection($plugins);
        }

        return $app instanceof BaseApplication ? $app : 'the application class is not a BaseApplication';
    }
}
