<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Event;

use CakeDC\Mcp\Authorization\ToolGate;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;

/**
 * Removes the tools the current caller may not use from `tools/list`.
 */
final class ToolListFilter
{
    /**
     * @param \CakeDC\Mcp\Authorization\ToolGate $gate The gate.
     */
    public function __construct(private readonly ToolGate $gate)
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
        if (!$result instanceof ListToolsResult) {
            return;
        }

        $tools = array_values(array_filter(
            $result->tools,
            fn(Tool $tool): bool => $this->gate->allows($tool->name),
        ));
        $event->setResponse(new Response($response->getId(), new ListToolsResult($tools, $result->nextCursor)));
    }
}
