<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Event;

use CakeDC\Mcp\Authorization\ToolGate;
use Closure;
use Mcp\Capability\RegistryInterface;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;

/**
 * Removes the tools the current caller may not use from `tools/list`.
 */
final class ToolListFilter
{
    /**
     * @param \CakeDC\Mcp\Authorization\ToolGate $gate The gate.
     * @param \Closure(string): bool|null $hidden Tools to leave out of the list even when allowed (the catalog's).
     * @param \Closure(): (\Mcp\Capability\RegistryInterface|null)|null $registry The registry the page came from,
     *   to fill a page the filter emptied from the pages after it.
     */
    public function __construct(
        private readonly ToolGate $gate,
        private readonly ?Closure $hidden = null,
        private readonly ?Closure $registry = null,
    ) {
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

        $tools = array_values(array_filter($result->tools, $this->keeps(...)));
        $cursor = $result->nextCursor;
        $registry = $this->registry === null ? null : ($this->registry)();
        if ($cursor !== null && count($tools) < count($result->tools) && $registry instanceof RegistryInterface) {
            [$tools, $cursor] = $this->fill($tools, count($result->tools), $event->getRequest(), $registry);
        }
        $event->setResponse(new Response($response->getId(), new ListToolsResult($tools, $cursor)));
    }

    /**
     * @param \Mcp\Schema\Tool $tool A tool of the page.
     * @return bool Whether the caller sees it.
     */
    private function keeps(Tool $tool): bool
    {
        return $this->gate->allows($tool->name) && ($this->hidden === null || !($this->hidden)($tool->name));
    }

    /**
     * Fills a page the filter left short from the tools after it, so a client does not get empty pages (120
     * catalogued tools would list as two tools and then empty pages). The cursor is the registry's own (the
     * offset after the last tool looked at), so the server's handler reads it as usual.
     *
     * @param list<\Mcp\Schema\Tool> $tools The tools kept from the page.
     * @param int $size The page size.
     * @param \Mcp\Schema\JsonRpc\Request $request The `tools/list` request.
     * @param \Mcp\Capability\RegistryInterface $registry The registry.
     * @return array{0: list<\Mcp\Schema\Tool>, 1: string|null}
     */
    private function fill(array $tools, int $size, Request $request, RegistryInterface $registry): array
    {
        $cursor = $request instanceof ListToolsRequest ? $request->cursor : null;
        $start = $cursor === null ? 0 : base64_decode($cursor, true);
        if (!is_int($start) && (!is_string($start) || !ctype_digit($start))) {
            return [$tools, base64_encode((string)$size)];
        }
        $all = array_values($registry->getTools()->references);
        $total = count($all);
        $missing = $size - count($tools);
        $offset = (int)$start + $size;
        while ($missing > 0 && $offset < $total) {
            $tool = $all[$offset++];
            if ($this->keeps($tool)) {
                $tools[] = $tool;
                $missing--;
            }
        }

        return [$tools, $offset < $total ? base64_encode((string)$offset) : null];
    }
}
