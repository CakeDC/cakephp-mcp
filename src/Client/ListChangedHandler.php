<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Mcp\Client\Handler\Notification\NotificationHandlerInterface;
use Mcp\Schema\JsonRpc\Notification;

/**
 * Drops the cached lists a `notifications/tools/list_changed`, `notifications/resources/list_changed` or
 * `notifications/prompts/list_changed` from the server says are stale.
 */
final class ListChangedHandler implements NotificationHandlerInterface
{
    /**
     * @var array<string, list<string>>
     */
    private const LISTS = [
        'notifications/tools/list_changed' => ['tools/list'],
        'notifications/resources/list_changed' => ['resources/list', 'resources/templates/list'],
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
        return isset(self::LISTS[$notification::getMethod()]);
    }

    /**
     * @inheritDoc
     */
    public function handle(Notification $notification): void
    {
        foreach (self::LISTS[$notification::getMethod()] ?? [] as $method) {
            $this->cache->forget($method);
        }
    }
}
