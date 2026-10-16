<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Command;

use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Authenticator\AuthenticationRequiredException;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Http\ServerRequest;
use Cake\Routing\Exception\MissingRouteException;
use Cake\Routing\Router;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\OAuth\AuthSettings;
use CakeDC\Mcp\OAuth\ResourceServer;
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
        ];
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
        $app = $this->application();
        if (is_string($app)) {
            return ['warn', $app . '; its authenticators were not inspected'];
        }
        if (!$app instanceof AuthenticationServiceProviderInterface) {
            return ['pass', 'the application provides no authentication service'];
        }
        $prefix = OAuthServer::server()->config()->tokenPrefix;
        $probe = (new ServerRequest(['url' => $route, 'environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withHeader('Authorization', 'Bearer ' . $prefix . '_at_' . str_repeat('A', 43));
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
