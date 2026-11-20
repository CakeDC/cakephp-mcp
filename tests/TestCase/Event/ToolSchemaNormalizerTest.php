<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Event;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Event\ToolSchemaNormalizer;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;

class ToolSchemaNormalizerTest extends TestCase
{
    public function testNormalizesEveryToolAndKeepsIdAndCursor(): void
    {
        $tool = new Tool('report', 'Report', ['type' => 'object', 'properties' => [
            'date_from' => ['type' => ['null', 'string'], 'default' => null],
        ]], 'desc', null, meta: ['ui' => ['visibility' => ['model']]]);
        $event = $this->event(new Response(7, new ListToolsResult([$tool], 'cursor-2')));

        (new ToolSchemaNormalizer())($event);

        $result = $event->getResponse()->result;
        $this->assertInstanceOf(ListToolsResult::class, $result);
        $this->assertSame(['type' => 'string'], $result->tools[0]->inputSchema['properties']['date_from']);
        $this->assertSame(['ui' => ['visibility' => ['model']]], $result->tools[0]->meta);
        $this->assertSame('Report', $result->tools[0]->title);
        $this->assertSame('desc', $result->tools[0]->description);
        $this->assertSame('cursor-2', $result->nextCursor);
        $this->assertSame(7, $event->getResponse()->getId());
    }

    public function testLeavesOtherResponsesAlone(): void
    {
        $response = new Response(4, ['anything' => true]);
        $event = $this->event($response);

        (new ToolSchemaNormalizer())($event);

        $this->assertSame($response, $event->getResponse());
    }

    /**
     * @param \Mcp\Schema\JsonRpc\Response<mixed> $response
     */
    private function event(Response $response): ResponseEvent
    {
        return new ResponseEvent($response, (new ListToolsRequest())->withId(1), $this->createStub(SessionInterface::class));
    }
}
