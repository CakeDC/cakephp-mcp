<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Server;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Test\Support\DelegatingRegistry;
use CakeDC\Mcp\Test\Support\McpHttpTrait;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use Mcp\Server\Builder;
use TestApp\TestTokens;

/**
 * A registry of the hook's own (any RegistryInterface, as 2.1 allowed) still serves, with the features that work
 * on the registry: the tool catalog, #[McpApp] links and the audit.
 */
class HookRegistryTest extends TestCase
{
    use IntegrationTestTrait;
    use McpHttpTrait;
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            $builder->setRegistry(new DelegatingRegistry());
        });
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testTheServerServesTheToolsOfARegistryOfItsOwn(): void
    {
        $this->mcpActingAs(['id' => 1]);

        $this->assertContains('whoami', array_column($this->mcpListTools(), 'name'));
        $this->mcpCallTool('echo', ['text' => 'hi']);
        $this->assertMcpToolSucceeded();
        $this->assertMcpResultContains('hi');
    }

    public function testTheCatalogRunsOnARegistryOfItsOwn(): void
    {
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['echo']]);

        $this->assertNotContains('echo', array_column($this->mcpListTools(), 'name'));
        $this->mcpCallTool('call_tools', ['calls' => [['name' => 'echo', 'arguments' => ['text' => 'hi']]]]);
        $this->assertMcpToolSucceeded();
        $this->assertMcpResultContains('hi');
    }

    public function testAppsAreLinkedInARegistryOfItsOwn(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/McpApps']);
        Configure::write('Mcp.apps.enabled', true);

        $tools = array_column($this->mcpListTools(), null, 'name');

        $this->assertSame('ui://test/inbox', $tools['show_inbox']['_meta']['ui']['resourceUri'] ?? null);
    }

    public function testTheAuditTellsAnUnknownToolFromADeniedOne(): void
    {
        Configure::write('Mcp.audit', ['enabled' => true, 'log' => false]);
        Configure::write('Mcp.authorization.authorizer', new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool === 'echo';
            }
        });
        $records = [];
        $listener = function (EventInterface $event) use (&$records): void {
            $records[] = $event->getData();
        };
        EventManager::instance()->on('Mcp.audit', $listener);
        try {
            $this->callTool('no_such_tool', [], TestTokens::EDITOR);
            $this->callTool('whoami', [], TestTokens::EDITOR);
        } finally {
            EventManager::instance()->off('Mcp.audit', $listener);
        }

        $this->assertSame(['invalid', 'denied'], array_column($records, 'outcome'));
    }
}
