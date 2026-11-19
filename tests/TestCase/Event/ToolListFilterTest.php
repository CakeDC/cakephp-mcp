<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Event;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolGate;
use CakeDC\Mcp\Event\ToolListFilter;
use CakeDC\Mcp\Identity\CurrentIdentity;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;

class ToolListFilterTest extends TestCase
{
    public function testRemovesDeniedToolsAndKeepsTheCursor(): void
    {
        $result = new ListToolsResult([$this->tool('echo'), $this->tool('admin_only')], 'cursor-2');
        $event = $this->event(new Response(3, $result));

        ($this->filter())($event);

        $filtered = $event->getResponse()->result;
        $this->assertInstanceOf(ListToolsResult::class, $filtered);
        $this->assertSame(['echo'], array_map(fn(Tool $tool): string => $tool->name, $filtered->tools));
        $this->assertSame('cursor-2', $filtered->nextCursor);
        $this->assertSame(3, $event->getResponse()->getId());
    }

    public function testLeavesOtherResponsesAlone(): void
    {
        $response = new Response(4, ['anything' => true]);
        $event = $this->event($response);

        ($this->filter())($event);

        $this->assertSame($response, $event->getResponse());
    }

    private function filter(): ToolListFilter
    {
        $onlyEcho = new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool === 'echo';
            }
        };

        return new ToolListFilter(new ToolGate($onlyEcho, new CurrentIdentity()));
    }

    /**
     * @param \Mcp\Schema\JsonRpc\Response<mixed> $response
     */
    private function event(Response $response): ResponseEvent
    {
        return new ResponseEvent($response, (new ListToolsRequest())->withId(1), $this->createStub(SessionInterface::class));
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, null, ['type' => 'object'], null, null);
    }
}
