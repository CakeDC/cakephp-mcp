<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Event;

use ArrayObject;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Event\EventDispatcher;
use IteratorAggregate;
use Psr\EventDispatcher\StoppableEventInterface;
use stdClass;

class EventDispatcherTest extends TestCase
{
    public function testCallsListenersForTheEventClassAndItsParents(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = [];
        $dispatcher->listen(ArrayObject::class, function () use (&$calls): void {
            $calls[] = 'exact';
        });
        $dispatcher->listen(IteratorAggregate::class, function () use (&$calls): void {
            $calls[] = 'interface';
        });
        $dispatcher->listen(stdClass::class, function () use (&$calls): void {
            $calls[] = 'unrelated';
        });
        $event = new ArrayObject();

        $this->assertSame($event, $dispatcher->dispatch($event));
        $this->assertSame(['exact', 'interface'], $calls);
    }

    public function testStopsOnceAStoppableEventIsStopped(): void
    {
        $dispatcher = new EventDispatcher();
        $event = new class implements StoppableEventInterface {
            public int $calls = 0;

            public function isPropagationStopped(): bool
            {
                return $this->calls > 0;
            }
        };
        $dispatcher->listen($event::class, fn(object $e) => $e->calls++);
        $dispatcher->listen($event::class, fn(object $e) => $e->calls++);

        $dispatcher->dispatch($event);

        $this->assertSame(1, $event->calls);
    }
}
