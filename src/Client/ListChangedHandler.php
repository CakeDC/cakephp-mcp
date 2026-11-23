<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Mcp\Client\Handler\Notification\NotificationHandlerInterface;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;

/**
 * Drops the cached lists a `notifications/tools/list_changed`, `notifications/resources/list_changed` or
 * `notifications/prompts/list_changed` from the server says are stale, and the cached reads of the resource a
 * `notifications/resources/updated` names.
 */
final class ListChangedHandler implements NotificationHandlerInterface
{
    /**
     * @var array<string, list<string>>
     */
    private const LISTS = [
        'notifications/tools/list_changed' => ['tools/list'],
        // A resource the list no longer has is not read from the cache either.
        'notifications/resources/list_changed' => ['resources/list', 'resources/templates/list', 'resources/read'],
        'notifications/prompts/list_changed' => ['prompts/list'],
    ];

    /**
     * @param \CakeDC\Mcp\Client\ListCache $cache The client's list cache.
     */
    public function __construct(private ListCache $cache)
    {
    }

    /**
     * @inheritDoc
     */
    public function supports(Notification $notification): bool
    {
        return isset(self::LISTS[$notification::getMethod()]) || $notification instanceof ResourceUpdatedNotification;
    }

    /**
     * @inheritDoc
     */
    public function handle(Notification $notification): void
    {
        if ($notification instanceof ResourceUpdatedNotification) {
            // The reads of that resource only.
            $this->cache->forget('resources/read', $notification->uri);

            return;
        }
        foreach (self::LISTS[$notification::getMethod()] ?? [] as $method) {
            $this->cache->forget($method);
        }
    }
}
