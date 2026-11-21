<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers 400 with a JSON-RPC "Invalid Request" to a request whose `Mcp-*` or `MCP-*` header (`Mcp-Method`,
 * `Mcp-Name`, `Mcp-Param-*`, `Mcp-Session-Id`, `MCP-Protocol-Version`) is not valid UTF-8: such a header cannot
 * mirror the JSON body, and later checks would fail on it with a server error.
 */
final class HeaderEncodingMiddleware implements MiddlewareInterface
{
    /**
     * @param \Psr\Http\Message\ResponseFactoryInterface $responseFactory For the 400.
     * @param \Psr\Http\Message\StreamFactoryInterface $streamFactory For its body.
     */
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        foreach ($request->getHeaders() as $name => $values) {
            $name = (string)$name;
            if (strncasecmp($name, 'mcp-', 4) !== 0) {
                continue;
            }
            foreach ($values as $value) {
                if (!mb_check_encoding($value, 'UTF-8')) {
                    return $this->badRequest($name);
                }
            }
        }

        return $handler->handle($request);
    }

    /**
     * @param string $header The header.
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function badRequest(string $header): ResponseInterface
    {
        return $this->responseFactory->createResponse(400)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream((string)json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => sprintf('Invalid Request: the %s header is not valid UTF-8.', $header),
                ],
            ])));
    }
}
