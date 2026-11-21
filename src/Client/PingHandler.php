<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\EmptyResult;

/**
 * Answers a `ping` the server sends (handshake revisions), as the specification requires, instead of the SDK's
 * "method not found".
 *
 * @implements \Mcp\Client\Handler\Request\RequestHandlerInterface<\Mcp\Schema\Result\EmptyResult>
 */
final class PingHandler implements RequestHandlerInterface
{
    /**
     * @inheritDoc
     */
    public function supports(Request $request): bool
    {
        return $request instanceof PingRequest;
    }

    /**
     * @inheritDoc
     */
    public function handle(Request $request): Response
    {
        return new Response($request->getId(), new EmptyResult());
    }
}
