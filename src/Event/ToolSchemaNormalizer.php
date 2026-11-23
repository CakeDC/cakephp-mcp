<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Event;

use CakeDC\Mcp\Schema\SchemaNormalizer;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;

/**
 * Rewrites the input schemas of a `tools/list` result with SchemaNormalizer (`Mcp.schema.normalize`).
 *
 * Only the advertised list changes: the SDK validates arguments against the schema it registered, so a client
 * that still sends `null` for an optional argument keeps working.
 */
final class ToolSchemaNormalizer
{
    /**
     * @param \Mcp\Event\ResponseEvent $event The response to a request.
     * @return void
     */
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $result = $response->result;
        if (!$result instanceof ListToolsResult) {
            return;
        }

        $tools = array_map(fn(Tool $tool): Tool => new Tool(
            $tool->name,
            $tool->title,
            SchemaNormalizer::normalize($tool->inputSchema),
            $tool->description,
            $tool->annotations,
            $tool->icons,
            $tool->meta,
            $tool->outputSchema,
            $tool->execution,
        ), $result->tools);

        $event->setResponse(new Response($response->getId(), new ListToolsResult($tools, $result->nextCursor)));
    }
}
