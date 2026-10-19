<?php
declare(strict_types=1);

namespace TestApp;

use ArrayObject;
use Authentication\AuthenticationService;
use Authentication\AuthenticationServiceInterface;
use Authentication\AuthenticationServiceProviderInterface;
use Authentication\Middleware\AuthenticationMiddleware;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Error\Middleware\ErrorHandlerMiddleware;
use Cake\Http\BaseApplication;
use Cake\Http\Middleware\BodyParserMiddleware;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\MiddlewareQueue;
use Cake\Http\ServerRequest;
use Cake\Routing\Middleware\RoutingMiddleware;
use Cake\Routing\RouteBuilder;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\McpPlugin;
use CakeDC\OAuthServer\OAuthServer;
use CakeDC\OAuthServer\OAuthServerPlugin;
use Psr\Http\Message\ServerRequestInterface;
use TestApp\Authenticator\ApiTokenAuthenticator;
use TestApp\Middleware\AuthorizationSpyMiddleware;

/**
 * Minimal host app: the middleware queue a real Cake 5 app would have, plus a
 * Bearer token authenticator, a session login, optionally the authorization
 * server of the oauth plugin, and catch-all fallback routes (to prove the MCP
 * route still wins).
 */
class Application extends BaseApplication implements AuthenticationServiceProviderInterface
{
    /**
     * @return void
     */
    public function bootstrap(): void
    {
        // `TestApp.mcpRoutes` false: a host that did not connect the plugin routes (mcp check test).
        $this->addPlugin(McpPlugin::class, ['routes' => Configure::read('TestApp.mcpRoutes') !== false]);
        if (Configure::check('OAuthServer.resources')) {
            // The PHPUnit suite: the authorization server of the same application (Mcp.auth.mode oauth).
            $this->addPlugin(OAuthServerPlugin::class);
        }
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The queue.
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $csrf = new CsrfProtectionMiddleware();
        $csrf->skipCheckCallback(fn(ServerRequest $request): bool => McpConfig::isEndpoint($request)
            || (Plugin::isLoaded('CakeDC/OAuthServer') && OAuthServer::csrfSkip($request)));

        return $middlewareQueue
            ->add(new ErrorHandlerMiddleware())
            ->add(new RoutingMiddleware($this))
            ->add(new BodyParserMiddleware())
            ->add($csrf)
            ->add(new AuthenticationMiddleware($this))
            ->add(new AuthorizationSpyMiddleware());
    }

    /**
     * @param \Cake\Routing\RouteBuilder $routes The builder.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->scope('/', function (RouteBuilder $builder): void {
            $builder->fallbacks();
        });
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request The request.
     * @return \Authentication\AuthenticationServiceInterface
     */
    public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
    {
        $service = new AuthenticationService();
        if (Configure::read('TestApp.sessionFirst') === true) {
            // The wrong order, which `oauth check` fails (oauth check test).
            $service->loadAuthenticator('Authentication.Session', ['sessionKey' => 'Auth']);
        }
        // docs/oauth.md, step 5: the oauth plugin's Token authenticator protects the application's other API,
        // first in line and never on the MCP endpoint. `TestApp.oauthTokenAuthenticator`: `everywhere`, a host
        // that left the guard out (mcp check test); `skipsMcp`, the authenticator of docs/oauth.md (cakedc/users)
        // that leaves the MCP endpoint alone by itself, loaded for every request.
        $variant = Configure::read('TestApp.oauthTokenAuthenticator');
        if (
            Plugin::isLoaded('CakeDC/OAuthServer')
            && ($variant === 'everywhere' || $variant === 'skipsMcp' || !McpConfig::isEndpoint($request))
        ) {
            $service->loadAuthenticator('OAuthToken', [
                'className' => $variant === 'skipsMcp' ? ApiTokenAuthenticator::class : 'CakeDC/OAuthServer.Token',
                'resource' => OAuthTestConfig::API,
            ]);
        }
        $service->loadAuthenticator('Authentication.Token', [
            'header' => 'Authorization',
            'tokenPrefix' => 'Bearer',
            'identifier' => [
                'Authentication.Callback' => [
                    'callback' => fn(array $data): ?ArrayObject => TestTokens::identity($data['token'] ?? null),
                ],
            ],
        ]);
        if (Configure::read('TestApp.sessionFirst') !== true) {
            // The browser login the oauth plugin's consent page needs, after the Token authenticators.
            $service->loadAuthenticator('Authentication.Session', ['sessionKey' => 'Auth']);
        }

        return $service;
    }
}
