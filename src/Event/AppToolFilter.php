<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Event;

use CakeDC\Mcp\App\AppSupport;
use CakeDC\Mcp\App\AppTools;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;

/**
 * Leaves app-only tools out of `tools/list` for clients that do not render MCP Apps: no host would keep them from
 * the model. A hint, not access control: calls to them are gated like any other.
 */
final class AppToolFilter
{
    /**
     * @param \Mcp\Event\ResponseEvent $event The response about to be sent.
     * @return void
     */
    public function __invoke(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $result = $response->result;
        if (!$result instanceof ListToolsResult || AppSupport::client($event->getSession())) {
            return;
        }
        $tools = array_values(array_filter($result->tools, fn(Tool $tool): bool => !AppTools::appOnly($tool)));
        if (count($tools) !== count($result->tools)) {
            $event->setResponse(new Response($response->getId(), new ListToolsResult($tools, $result->nextCursor)));
        }
    }
}
