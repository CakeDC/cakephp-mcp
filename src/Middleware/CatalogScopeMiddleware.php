<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Middleware;

use CakeDC\Mcp\Catalog\CatalogScopes;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Mcp\Server\Transport\ReadsBoundedBody;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Requires, for a `call_tools` request, the scopes of every catalogued tool it runs, with the same
 * `403 insufficient_scope` and challenge the SDK sends for a direct `tools/call`, so the client can ask for them.
 *
 * Runs after the SDK's AuthorizationMiddleware, which validated the token and checked the outer call.
 */
final class CatalogScopeMiddleware implements MiddlewareInterface
{
    use ReadsBoundedBody;

    /**
     * @param \Mcp\Server\Transport\Http\OAuth\ScopePolicy $policy The server's scope policy.
     * @param \Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata $metadata For the challenge.
     * @param list<string> $patterns `Mcp.catalog.tools`.
     * @param \Psr\Http\Message\ResponseFactoryInterface $responses For the 403.
     * @param \Psr\Http\Message\StreamFactoryInterface $streams To hand the body on.
     * @param int $maxBodyBytes The largest body read.
     */
    public function __construct(
        private readonly ScopePolicy $policy,
        private readonly ProtectedResourceMetadata $metadata,
        private readonly array $patterns,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly int $maxBodyBytes,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $request->getAttribute(AccessToken::class);
        if ($request->getMethod() !== 'POST' || !$token instanceof AccessToken) {
            return $handler->handle($request);
        }
        $body = $this->readBoundedBody($request->getBody(), $this->maxBodyBytes);
        if ($body === null) {
            // The body limit before authentication already answered an oversized body.
            return $handler->handle($request);
        }
        // Handed on byte for byte: the transport reads the body again.
        $request = $request->withBody($this->streams->createStream($body));
        $required = CatalogScopes::required($this->policy, json_decode($body, true), $this->patterns);
        // The token already holds the scopes the policy implies (the SDK middleware expanded them).
        if (array_diff($required, $token->getScopes()) === []) {
            return $handler->handle($request);
        }

        return $this->responses->createResponse(403)->withHeader('WWW-Authenticate', 'Bearer ' . implode(', ', [
            sprintf('resource_metadata="%s"', $this->escape($this->metadata->getMetadataUrl())),
            sprintf('scope="%s"', $this->escape(implode(' ', $required))),
            'error="insufficient_scope"',
            'error_description="The access token lacks a required scope."',
        ]));
    }

    /**
     * As the SDK escapes challenge values.
     *
     * @param string $value A value.
     * @return string
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
    }
}
