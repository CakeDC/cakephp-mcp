<?php
declare(strict_types=1);

namespace TestApp\UsersHost;

use Cake\Core\BasePlugin;
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

/**
 * A cakedc/users host, for `mcp check` (App.namespace `TestApp\UsersHost`). cakedc/users builds the
 * authentication service from `Auth.Authenticators`, so the application provides none, and the oauth plugin's
 * integration registers its Token authenticator there. cakedc/users is not installed: a plugin named
 * CakeDC/Users stands in for it, loaded first as its documentation asks.
 */
class Application extends BaseApplication
{
    /**
     * @return void
     */
    public function bootstrap(): void
    {
        $this->addPlugin(new BasePlugin([
            'name' => 'CakeDC/Users',
            'bootstrap' => false,
            'routes' => false,
            'middleware' => false,
            'console' => false,
            'services' => false,
            'events' => false,
        ]));
        $this->addPlugin(McpPlugin::class);
        $this->addPlugin(OAuthServerPlugin::class);
    }

    /**
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The queue.
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $csrf = new CsrfProtectionMiddleware();
        $csrf->skipCheckCallback(fn(ServerRequest $request): bool => McpConfig::isEndpoint($request)
            || OAuthServer::csrfSkip($request));

        return $middlewareQueue
            ->add(new RoutingMiddleware($this))
            ->add(new BodyParserMiddleware())
            ->add($csrf);
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
}
