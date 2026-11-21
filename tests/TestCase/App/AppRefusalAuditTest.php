<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\App;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\McpHttpTrait;
use CakeDC\Mcp\Test\Support\TestJwt;
use Closure;

/**
 * Calls to an app-only tool that the endpoint refuses before the server (no token, a missing scope) are audited
 * with `app=1`, like the calls that reach it.
 */
class AppRefusalAuditTest extends TestCase
{
    use IntegrationTestTrait;
    use McpHttpTrait;

    /**
     * @var list<array<string, mixed>>
     */
    private array $records = [];

    private ?Closure $listener = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('mcp_oauth_test', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpApps']);
        Configure::write('Mcp.apps.enabled', true);
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            // TestJwt::token() holds notes:read only.
            'scopes' => ['tools' => ['inbox_rows' => ['notes:write'], 'show_inbox' => ['notes:write']]],
            'external' => ['issuer' => TestJwt::ISSUER, 'cache' => 'mcp_oauth_test', 'httpClient' => TestJwt::client()],
        ]);
        Configure::write('Mcp.audit', ['enabled' => true, 'log' => false]);
        $this->listener = function (EventInterface $event): void {
            $this->records[] = (array)$event->getData();
        };
        EventManager::instance()->on('Mcp.audit', $this->listener);
    }

    protected function tearDown(): void
    {
        EventManager::instance()->off('Mcp.audit', $this->listener);
        Cache::drop('mcp_oauth_test');
        parent::tearDown();
    }

    public function testAnInsufficientScopeRecordOfAnAppOnlyToolCarriesTheAppMark(): void
    {
        $this->callTool('inbox_rows', [], TestJwt::token());
        $this->callTool('show_inbox', [], TestJwt::token());

        $this->assertSame(['insufficient_scope', 'insufficient_scope'], array_column($this->records, 'outcome'));
        $this->assertSame([true, false], array_map(fn(array $record): bool => $record['app'] ?? false, $this->records));
    }

    public function testAnUnauthorizedRecordOfAnAppOnlyToolCarriesTheAppMark(): void
    {
        $this->callTool('inbox_rows');

        $this->assertResponseCode(401);
        $this->assertSame('unauthorized', $this->records[0]['outcome'] ?? null);
        $this->assertTrue($this->records[0]['app'] ?? null);
    }
}
