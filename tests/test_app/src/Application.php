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
use CakeDC\Mcp\McpPlugin;
use CakeDC\OAuthServer\OAuthServer;
use CakeDC\OAuthServer\OAuthServerPlugin;
use Psr\Http\Message\ServerRequestInterface;
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
        $this->addPlugin(McpPlugin::class);
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
        $csrf->skipCheckCallback(fn(ServerRequest $request): bool => $request->getPath() === '/mcp'
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
        if (Configure::read('TestApp.oauthTokenAuthenticator') === true) {
            // A host that also protects an API with the oauth plugin's Token authenticator (mcp check test).
            $service->loadAuthenticator('CakeDC/OAuthServer.Token', ['resource' => OAuthTestConfig::API]);
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
        // The browser login the oauth plugin's consent page needs.
        $service->loadAuthenticator('Authentication.Session', ['sessionKey' => 'Auth']);

        return $service;
    }
}
