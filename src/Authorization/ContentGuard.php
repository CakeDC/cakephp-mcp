<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Authorization;

use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\PromptReference;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Request\ResourceSubscribeRequest;
use Mcp\Schema\Result\CompletionCompleteResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;

/**
 * Answers a read of, or a subscription to, a denied resource exactly like an unknown URI, a get of a denied prompt
 * exactly like an unknown prompt (as the SDK words them), and a completion for a denied prompt or template with no
 * values. A concrete URI is denied when it is, or when the template that serves it is. Registered before the SDK's handlers, which it replaces only for denied elements.
 *
 * @implements \Mcp\Server\Handler\Request\RequestHandlerInterface<mixed>
 */
final class ContentGuard implements RequestHandlerInterface
{
    /**
     * @param \CakeDC\Mcp\Authorization\ContentGate $gate The gate.
     */
    public function __construct(private readonly ContentGate $gate)
    {
    }

    /**
     * @inheritDoc
     */
    public function supports(Request $request): bool
    {
        return match (true) {
            $request instanceof ReadResourceRequest, $request instanceof ResourceSubscribeRequest
                => !$this->gate->allowsRead($request->uri),
            $request instanceof GetPromptRequest => !$this->gate->allowsPrompt($request->name),
            $request instanceof CompletionCompleteRequest => $request->ref instanceof PromptReference
                ? !$this->gate->allowsPrompt($request->ref->name)
                : !$this->gate->allowsRead($request->ref->uri),
            default => false,
        };
    }

    /**
     * @param \Mcp\Schema\JsonRpc\Request $request The request.
     * @param \Mcp\Server\Session\SessionInterface $session The session.
     * @return \Mcp\Schema\JsonRpc\Response<\Mcp\Schema\Result\CompletionCompleteResult>|\Mcp\Schema\JsonRpc\Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if ($request instanceof CompletionCompleteRequest) {
            return new Response($request->getId(), new CompletionCompleteResult([]));
        }
        if ($request instanceof GetPromptRequest) {
            $message = (new PromptNotFoundException($request->name))->getMessage();

            return Error::forInvalidParams($message, $request->getId());
        }
        $uri = $request instanceof ReadResourceRequest || $request instanceof ResourceSubscribeRequest
            ? $request->uri
            : '';
        $message = (new ResourceNotFoundException($uri))->getMessage();

        return (new RequestContext($session, $request))->getProtocolVersion()->usesInvalidParamsForResourceNotFound()
            ? Error::forInvalidParams($message, $request->getId(), ['uri' => $uri])
            : Error::forResourceNotFound($message, $request->getId());
    }
}
