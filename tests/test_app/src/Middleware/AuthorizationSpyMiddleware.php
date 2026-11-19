<?php
declare(strict_types=1);

namespace TestApp\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TestApp\AuthorizationSpy;

/**
 * Puts an AuthorizationSpy on the `authorization` attribute, like the
 * AuthorizationMiddleware of cakephp/authorization would.
 */
final class AuthorizationSpyMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('authorization', new AuthorizationSpy()));
    }
}
