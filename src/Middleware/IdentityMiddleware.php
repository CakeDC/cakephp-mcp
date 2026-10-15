<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Middleware;

use CakeDC\Mcp\Identity\CurrentIdentity;
use Closure;
use Mcp\Server\Authorization\AccessToken;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns the identity the host's authentication put on the request into the
 * SDK's AccessToken, and records it as the CurrentIdentity.
 *
 * It sits in the SDK transport's middleware stack, the slot the SDK's OAuth
 * AuthorizationMiddleware takes in a later version, so tools never change.
 */
final class IdentityMiddleware implements MiddlewareInterface
{
    /**
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $current Shared holder for tools.
     * @param \Psr\Http\Message\ResponseFactoryInterface $responseFactory For the 401.
     * @param \Psr\Http\Message\StreamFactoryInterface $streamFactory For the 401 body.
     * @param bool $required Answer 401 when there is no identity.
     * @param string $attribute Request attribute holding the identity.
     * @param \Closure|null $scopes `fn(mixed $identity): array` returning scope strings.
     */
    public function __construct(
        private readonly CurrentIdentity $current,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly bool $required = true,
        private readonly string $attribute = 'identity',
        private readonly ?Closure $scopes = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->current->set(null);

        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $identity = $request->getAttribute($this->attribute);
        if ($identity === null) {
            return $this->required ? $this->unauthorized() : $handler->handle($request);
        }

        $this->current->set($identity);
        $claims = [];
        $identifier = CurrentIdentity::identifierOf($identity);
        if ($identifier !== null) {
            $claims['sub'] = (string)$identifier;
        }

        return $handler->handle($request->withAttribute(
            AccessToken::class,
            new AccessToken($this->scopesFor($identity), $claims),
        ));
    }

    /**
     * @param mixed $identity The identity.
     * @return list<string>
     */
    private function scopesFor(mixed $identity): array
    {
        if ($this->scopes === null) {
            return [];
        }
        $scopes = ($this->scopes)($identity);

        return is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function unauthorized(): ResponseInterface
    {
        $body = (string)json_encode([
            'error' => 'unauthorized',
            'error_description' => 'Authentication required.',
        ]);

        return $this->responseFactory->createResponse(401)
            ->withHeader('WWW-Authenticate', 'Bearer')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));
    }
}
