<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers a POST whose body is larger than `Mcp.http.maxBodyBytes` with HTTP 413 and a JSON-RPC error that
 * names the limit, before the authentication middleware reads the body. Without it the SDK's
 * AuthorizationMiddleware answered such a body with a Bearer `invalid_request` challenge, which MCP clients
 * read as a problem with their token.
 *
 * The Content-Length header is checked first, so an announced oversized body is never read. A body without
 * one (chunked) is read up to the limit and no further. A body within the limit is handed on as a fresh
 * stream: the stream over `php://input` cannot always be read twice.
 */
final class BodyLimitMiddleware implements MiddlewareInterface
{
    private const CHUNK = 8192;

    /**
     * @param int $maxBytes The largest body accepted, in bytes.
     * @param \Psr\Http\Message\ResponseFactoryInterface $responseFactory For the 413 response.
     * @param \Psr\Http\Message\StreamFactoryInterface $streamFactory For the bodies.
     */
    public function __construct(
        private readonly int $maxBytes,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        $length = $request->getHeaderLine('Content-Length');
        if ($length !== '' && ctype_digit($length) && (int)$length > $this->maxBytes) {
            return $this->tooLarge();
        }

        $body = $this->read($request->getBody());
        if ($body === null) {
            return $this->tooLarge();
        }

        return $handler->handle($request->withBody($this->streamFactory->createStream($body)));
    }

    /**
     * @param \Psr\Http\Message\StreamInterface $stream The request body.
     * @return string|null The body, or null when it is larger than the limit.
     */
    private function read(StreamInterface $stream): ?string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $size = $stream->getSize();
        if ($size !== null && $size > $this->maxBytes) {
            return null;
        }

        $contents = '';
        while (!$stream->eof()) {
            // Never more than one byte past the limit is read, which is enough to know the body is too large.
            $chunk = $stream->read(min(self::CHUNK, $this->maxBytes + 1 - strlen($contents)));
            if ($chunk === '') {
                break;
            }
            $contents .= $chunk;
            if (strlen($contents) > $this->maxBytes) {
                return null;
            }
        }

        return $contents;
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function tooLarge(): ResponseInterface
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32600,
                'message' => sprintf(
                    'Request body too large: the limit is %d bytes (Mcp.http.maxBodyBytes). Send a smaller payload, '
                    . 'for example a smaller file or fewer items per call.',
                    $this->maxBytes,
                ),
                'data' => ['maxBodyBytes' => $this->maxBytes],
            ],
        ], JSON_THROW_ON_ERROR);

        return $this->responseFactory->createResponse(413)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload));
    }
}
