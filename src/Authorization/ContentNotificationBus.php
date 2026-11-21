<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Authorization;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Subscription\NotificationBusInterface;

/**
 * Wraps the bus a `subscriptions/listen` stream (2026-07-28 revision) reads, so the stream never carries an update
 * of a resource the caller may not read (the rule of `resources/read`). Publishing is left as it is: what one
 * caller may not read, another may.
 *
 * @internal Set by the server factory; not part of the public API.
 */
final class ContentNotificationBus implements NotificationBusInterface
{
    /**
     * @param \Mcp\Server\Subscription\NotificationBusInterface $bus The bus the host configured.
     * @param \CakeDC\Mcp\Authorization\ContentGate $gate The gate of the caller holding the stream.
     */
    public function __construct(
        private readonly NotificationBusInterface $bus,
        private readonly ContentGate $gate,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function publish(Notification $notification): void
    {
        $this->bus->publish($notification);
    }

    /**
     * @inheritDoc
     */
    public function cursor(): int
    {
        return $this->bus->cursor();
    }

    /**
     * @inheritDoc
     */
    public function since(int $cursor): array
    {
        [$notifications, $next] = $this->bus->since($cursor);
        $readable = array_values(array_filter(
            $notifications,
            fn(Notification $notification): bool => !$notification instanceof ResourceUpdatedNotification
                || $this->gate->allowsRead($notification->uri),
        ));

        return [$readable, $next];
    }
}
