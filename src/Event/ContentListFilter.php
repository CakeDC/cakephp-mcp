<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Event;

use CakeDC\Mcp\Authorization\ContentGate;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Schema\Result\ListResourceTemplatesResult;

/**
 * Leaves the resources, resource templates and prompts the current identity may not read or get out of
 * `resources/list`, `resources/templates/list` and `prompts/list`.
 */
final class ContentListFilter
{
    /**
     * @param \CakeDC\Mcp\Authorization\ContentGate $gate The gate.
     */
    public function __construct(private readonly ContentGate $gate)
    {
    }

    /**
     * @param \Mcp\Event\ResponseEvent $event The response about to be sent.
     * @return void
     */
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $result = $response->result;
        $filtered = match (true) {
            $result instanceof ListResourcesResult => new ListResourcesResult(array_values(array_filter(
                $result->resources,
                fn(ResourceDefinition $resource): bool => $this->gate->allowsResource($resource->uri),
            )), $result->nextCursor),
            $result instanceof ListResourceTemplatesResult => new ListResourceTemplatesResult(array_values(array_filter(
                $result->resourceTemplates,
                fn(ResourceTemplate $template): bool => $this->gate->allowsResource($template->uriTemplate),
            )), $result->nextCursor),
            $result instanceof ListPromptsResult => new ListPromptsResult(array_values(array_filter(
                $result->prompts,
                fn(Prompt $prompt): bool => $this->gate->allowsPrompt($prompt->name),
            )), $result->nextCursor),
            default => null,
        };
        if ($filtered !== null) {
            $event->setResponse(new Response($response->getId(), $filtered));
        }
    }
}
