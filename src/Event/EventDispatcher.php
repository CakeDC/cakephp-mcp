<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Minimal PSR-14 dispatcher for the SDK's events (Cake's EventManager is not
 * PSR-14). Listeners registered for a class also receive its subclasses.
 */
final class EventDispatcher implements EventDispatcherInterface, ListenerProviderInterface
{
    /**
     * @var array<string, list<callable>>
     */
    private array $listeners = [];

    /**
     * @param string $eventClass Class or interface name.
     * @param callable $listener Receives the event.
     * @return void
     */
    public function listen(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * Yields the listeners whose registered class matches the event.
     *
     * @param object $event The event being dispatched.
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as $class => $listeners) {
            if ($event instanceof $class) {
                yield from $listeners;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function dispatch(object $event): object
    {
        foreach ($this->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }
}
