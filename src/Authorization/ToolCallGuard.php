<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Authorization;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Prepended to the SDK's request handlers: claims a `tools/call` only when the
 * gate denies it, and answers it the way the SDK answers an unknown tool, so a
 * denied tool cannot be told apart from a missing one. Allowed calls fall
 * through to the SDK's own handler.
 *
 * @implements \Mcp\Server\Handler\Request\RequestHandlerInterface<mixed>
 */
final class ToolCallGuard implements RequestHandlerInterface
{
    /**
     * @param \CakeDC\Mcp\Authorization\ToolGate $gate The gate.
     */
    public function __construct(private readonly ToolGate $gate)
    {
    }

    /**
     * @inheritDoc
     */
    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest && !$this->gate->allows($request->name);
    }

    /**
     * @inheritDoc
     */
    public function handle(Request $request, SessionInterface $session): Error
    {
        $name = $request instanceof CallToolRequest ? $request->name : '';

        return Error::forInvalidParams(sprintf('Tool not found: "%s".', $name), $request->getId());
    }
}
