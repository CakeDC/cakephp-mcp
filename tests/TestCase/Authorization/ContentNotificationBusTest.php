<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Authorization;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ContentGate;
use CakeDC\Mcp\Authorization\ContentNotificationBus;
use CakeDC\Mcp\Authorization\ResourceAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Identity\CurrentIdentity;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Subscription\InMemoryNotificationBus;

class ContentNotificationBusTest extends TestCase
{
    public function testAStreamKeepsTheIdentityItWasOpenedWith(): void
    {
        $authorizer = new class implements ToolAuthorizerInterface, ResourceAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return true;
            }

            public function canReadResource(mixed $identity, string $uri): bool
            {
                return $identity === 'alice';
            }
        };
        foreach (['alice' => 'bob', 'bob' => 'alice'] as $opener => $later) {
            $identity = new CurrentIdentity();
            $identity->set($opener);
            $inner = new InMemoryNotificationBus();
            $bus = new ContentNotificationBus($inner, new ContentGate($authorizer, $identity));
            $cursor = $bus->cursor();

            // Another request of the same worker (a coroutine server) sets its identity while the stream waits.
            $identity->set($later);
            $inner->publish(new ResourceUpdatedNotification('invoices://1'));
            [$notifications] = $bus->since($cursor);

            $this->assertCount($opener === 'alice' ? 1 : 0, $notifications, 'opened by ' . $opener);
        }
    }
}
