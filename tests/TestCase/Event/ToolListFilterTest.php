<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Event;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolGate;
use CakeDC\Mcp\Event\ToolListFilter;
use CakeDC\Mcp\Identity\CurrentIdentity;
use Mcp\Capability\Registry;
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

    public function testAPageTheFilterEmptiesIsFilledFromTheNextOnes(): void
    {
        // 45 tools, 20 a page: only echo (the first) and echo_too (the 41st) may be used.
        $registry = new Registry();
        $names = ['echo', ...array_map(fn(int $i): string => 'admin_' . $i, range(1, 39)), 'echo_too'];
        $names = [...$names, ...array_map(fn(int $i): string => 'admin_x' . $i, range(1, 4))];
        foreach ($names as $name) {
            $registry->registerTool($this->tool($name), fn(): string => $name);
        }
        $filter = new ToolListFilter(new ToolGate(new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return str_starts_with($tool, 'echo');
            }
        }, new CurrentIdentity()), null, fn(): Registry => $registry);

        $page = $registry->getTools(20);
        $event = $this->event(new Response(3, new ListToolsResult(array_values($page->references), $page->nextCursor)));
        $filter($event);

        $result = $event->getResponse()->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertSame(['echo', 'echo_too'], array_map(fn(Tool $tool): string => $tool->name, $result->tools));
        $this->assertNull($result->nextCursor);
    }

    public function testTheCursorOfAFilledPageIsOneTheServerAccepts(): void
    {
        // 30 tools, 10 a page, every third allowed: a full page needs 30 tools, so the next cursor points past them.
        $registry = new Registry();
        foreach (range(0, 34) as $i) {
            $registry->registerTool($this->tool(($i % 3 === 0 ? 'echo_' : 'admin_') . $i), fn(): int => $i);
        }
        $filter = new ToolListFilter(new ToolGate(new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return str_starts_with($tool, 'echo');
            }
        }, new CurrentIdentity()), null, fn(): Registry => $registry);

        $page = $registry->getTools(10);
        $event = $this->event(new Response(3, new ListToolsResult(array_values($page->references), $page->nextCursor)));
        $filter($event);
        $result = $event->getResponse()->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertCount(10, $result->tools);
        $this->assertSame('echo_27', $result->tools[9]->name);
        $this->assertIsString($result->nextCursor);

        // The next page starts after it.
        $next = $registry->getTools(10, $result->nextCursor);
        $this->assertSame('admin_28', array_values($next->references)[0]->name);
    }

    public function testACursorThatIsNotTheRegistrysOffsetIsPassedOnAndThePageLeftShort(): void
    {
        // A registry of the application's own may page with cursors of its own: only that registry can read them.
        $registry = new Registry();
        foreach (['echo', 'admin_1', 'echo_2', 'admin_3'] as $name) {
            $registry->registerTool($this->tool($name), fn(): string => $name);
        }
        $filter = new ToolListFilter(new ToolGate(new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return str_starts_with($tool, 'echo');
            }
        }, new CurrentIdentity()), null, fn(): Registry => $registry);

        foreach ([null, 'after:echo', base64_encode('7')] as $requestCursor) {
            $page = [$this->tool('echo'), $this->tool('admin_1')];
            $event = $this->event(new Response(3, new ListToolsResult($page, 'after:admin_1')), $requestCursor);
            $filter($event);

            $result = $event->getResponse()->result;
            $this->assertInstanceOf(ListToolsResult::class, $result);
            $this->assertSame(['echo'], array_map(fn(Tool $tool): string => $tool->name, $result->tools));
            $this->assertSame('after:admin_1', $result->nextCursor, (string)$requestCursor);
        }
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
     * @param string|null $cursor The cursor of the request.
     */
    private function event(Response $response, ?string $cursor = null): ResponseEvent
    {
        $request = (new ListToolsRequest($cursor))->withId(1);

        return new ResponseEvent($response, $request, $this->createStub(SessionInterface::class));
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, null, ['type' => 'object'], null, null);
    }
}
