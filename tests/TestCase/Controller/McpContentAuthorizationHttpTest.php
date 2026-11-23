<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Test\Support\McpHttpTrait;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Builder;
use Mcp\Server\Subscription\NotificationBusInterface;
use TestApp\ContentAuthorizer;
use TestApp\TestTokens;

/**
 * Over HTTP, in the 2026-07-28 revision: a denied resource or prompt answers exactly like an unknown one.
 */
class McpContentAuthorizationHttpTest extends TestCase
{
    use IntegrationTestTrait;
    use McpHttpTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Mcp.discovery.scanDirs', ['src/McpContent']);
        Configure::write('Mcp.authorization.authorizer', ContentAuthorizer::class);
    }

    public function testADeniedResourceReadsLikeAnUnknownUri(): void
    {
        $denied = $this->send('resources/read', ['uri' => 'app://salaries'], 'app://salaries');
        $unknown = $this->send('resources/read', ['uri' => 'app://salaries2'], 'app://salaries2');

        $this->assertArrayHasKey('error', $denied);
        $this->assertSame(
            str_replace('app://salaries2', 'app://salaries', (string)json_encode($unknown, JSON_UNESCAPED_SLASHES)),
            (string)json_encode($denied, JSON_UNESCAPED_SLASHES),
        );
    }

    public function testADeniedPromptGetsLikeAnUnknownOne(): void
    {
        $denied = $this->send('prompts/get', ['name' => 'payroll'], 'payroll');
        $unknown = $this->send('prompts/get', ['name' => 'payroll2'], 'payroll2');

        $this->assertArrayHasKey('error', $denied);
        $this->assertSame(
            str_replace('payroll2', 'payroll', (string)json_encode($unknown)),
            (string)json_encode($denied),
        );
    }

    public function testListsLeaveDeniedElementsOut(): void
    {
        $resources = $this->send('resources/list', []);
        $prompts = $this->send('prompts/list', []);

        $this->assertNotContains('app://salaries', array_column($resources['result']['resources'] ?? [], 'uri'));
        $this->assertContains('app://rules', array_column($resources['result']['resources'] ?? [], 'uri'));
        $this->assertNotContains('payroll', array_column($prompts['result']['prompts'] ?? [], 'name'));
    }

    public function testListsAndReadsFilteredByTheAuthorizerAreNeverPublic(): void
    {
        Configure::write('Mcp.server.cache', ['ttl' => 60000, 'scope' => 'public']);

        foreach (['resources/list' => [], 'resources/templates/list' => [], 'prompts/list' => []] as $method => $params) {
            $this->assertSame('private', $this->send($method, $params)['result']['cacheScope'] ?? null, $method);
        }
        $read = $this->send('resources/read', ['uri' => 'app://rules'], 'app://rules');
        $this->assertSame('private', $read['result']['cacheScope'] ?? null);
    }

    public function testRbacWithoutContentRulesKeepsPublicHints(): void
    {
        Configure::write('Mcp.authorization.authorizer', RbacToolAuthorizer::class);
        Configure::write('Mcp.authorization.rbac', ['permissions' => [
            ['role' => 'editor', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => '*'],
        ]]);
        Configure::write('Mcp.server.cache', ['ttl' => 60000, 'scope' => 'public']);

        $this->assertSame('public', $this->send('prompts/list', [])['result']['cacheScope'] ?? null);
        $this->assertSame('public', $this->send('resources/list', [])['result']['cacheScope'] ?? null);
    }

    public function testAListenStreamCarriesNoUpdateOfADeniedResource(): void
    {
        $uris = ['app://rules', 'app://salaries', 'orders://1001', 'orders://2001'];
        // A bus that already holds an update of each, from the cursor a stream starts at.
        $bus = new class (array_map(fn(string $uri) => new ResourceUpdatedNotification($uri), $uris)) implements
            NotificationBusInterface
        {
            /**
             * @param list<\Mcp\Schema\JsonRpc\Notification> $held
             */
            public function __construct(private array $held)
            {
            }

            public function publish(Notification $notification): void
            {
                $this->held[] = $notification;
            }

            public function cursor(): int
            {
                return 0;
            }

            public function since(int $cursor): array
            {
                return [array_slice($this->held, $cursor), count($this->held)];
            }
        };
        Configure::write('Mcp.server.configure', function (Builder $builder) use ($bus): void {
            $builder->setNotificationBus($bus)->setSubscriptionLifetime(0.3);
        });

        // The stream writes and flushes its frames as they come.
        $stream = '';
        ob_start(function (string $chunk) use (&$stream): string {
            $stream .= $chunk;

            return '';
        });
        try {
            $this->postMcp(
                $this->mcpMessage(1, 'subscriptions/listen', ['notifications' => ['resourceSubscriptions' => $uris]]),
                $this->mcpHeaders('subscriptions/listen', null, TestTokens::EDITOR),
            );
            $stream .= (string)$this->_response?->getBody();
        } finally {
            ob_end_clean();
        }
        $this->assertResponseOk();
        $updated = [];
        foreach (explode("\n", $stream) as $line) {
            $frame = str_starts_with($line, 'data: ') ? json_decode(substr($line, 6), true) : null;
            if (is_array($frame) && ($frame['method'] ?? null) === 'notifications/resources/updated') {
                $updated[] = $frame['params']['uri'] ?? null;
            }
        }

        $this->assertSame(['app://rules', 'orders://1001'], $updated);
    }

    /**
     * @param string $method The method.
     * @param array<string, mixed> $params Its params.
     * @param string|null $name The Mcp-Name header.
     * @return array<string, mixed>
     */
    private function send(string $method, array $params, ?string $name = null): array
    {
        $this->postMcp($this->mcpMessage(1, $method, $params), $this->mcpHeaders($method, $name, TestTokens::EDITOR));
        $json = $this->responseJson();
        // The ids and status are the same; what differs is what is compared.
        unset($json['id']);

        return $json + ['status' => $this->_response?->getStatusCode()];
    }
}
