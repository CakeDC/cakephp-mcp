<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Authorization;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;
use CakeDC\Mcp\Authorization\ResourceAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use PHPUnit\Framework\AssertionFailedError;
use TestApp\ContentAuthorizer;

/**
 * An authorizer that implements ResourceAuthorizerInterface or PromptAuthorizerInterface decides who reads which
 * resource and gets which prompt, on every path: lists, reads (also through a template), subscriptions, gets and
 * completions. One that implements neither keeps the 2.1 behaviour.
 */
class ContentAuthorizationTest extends TestCase
{
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
        Configure::write('Mcp.discovery.scanDirs', ['src/McpContent']);
        Configure::write('Mcp.authorization.authorizer', ContentAuthorizer::class);
        $this->mcpActingAs(['id' => 1, 'role' => 'user']);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testADeniedResourceIsNotListed(): void
    {
        $this->assertMcpResourceNotListed('app://salaries');
        $this->assertMcpResourceListed('app://rules');
    }

    public function testReadingADeniedResourceAnswersLikeAnUnknownOne(): void
    {
        $denied = $this->mcpReadResource('app://salaries');
        $unknown = $this->mcpReadResource('app://salaries2');

        $this->assertArrayHasKey('error', $denied);
        $this->assertSame(
            str_replace('app://salaries2', 'app://salaries', (string)json_encode($unknown['error'], JSON_UNESCAPED_SLASHES)),
            (string)json_encode($denied['error'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function testResourceDeniedAssertion(): void
    {
        $this->mcpReadResource('app://salaries');
        $this->assertMcpResourceDenied();

        $this->mcpReadResource('app://rules');
        try {
            $this->assertMcpResourceDenied();
            $this->fail('A resource that was read is not denied.');
        } catch (AssertionFailedError $e) {
            $this->assertStringNotContainsString('A resource that was read', $e->getMessage());
        }
    }

    public function testResourceDeniedHoldsForADeniedResourceWhoseHandlerFails(): void
    {
        Configure::write('Mcp.authorization.authorizer', new class implements ToolAuthorizerInterface, ResourceAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return true;
            }

            public function canReadResource(mixed $identity, string $uri): bool
            {
                return $uri !== 'app://broken';
            }
        });
        $this->mcpReadResource('app://broken');

        // It exists (its handler fails), and the authorizer denied it.
        $this->assertMcpResourceDenied();
    }

    public function testResourceDeniedFailsForAnUnknownUri(): void
    {
        $this->mcpReadResource('app://typo');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('not registered');
        $this->assertMcpResourceDenied();
    }

    public function testAConcreteUriOfATemplateIsCheckedAsRead(): void
    {
        $this->mcpReadResource('orders://2001');
        $this->assertMcpResourceDenied();

        $this->mcpReadResource('orders://1001');
        $this->assertMcpResourceText('Order 1001');
    }

    public function testTheAuthorizerReadsTheTemplateVariableAsTheHandlerDoes(): void
    {
        // A template variable takes any text but `/`: denying the exact string `orders://2001` would let these
        // through, so the test authorizer reads the number the way the handler does (docs/authorization.md).
        foreach (['orders://02001', 'orders://2001?x=1', 'orders://2001 ', 'orders://2001#f'] as $uri) {
            $this->mcpReadResource($uri);
            $this->assertMcpResourceDenied($uri);
        }
    }

    public function testADeniedTemplateIsNotListedNorCompleted(): void
    {
        $this->assertMcpResourceTemplateListed('orders://{number}');
        $this->mcpComplete(['type' => 'ref/resource', 'uri' => 'orders://{number}'], 'number', '10');
        $this->assertMcpCompletionValues(['1001', '1002']);

        $this->mcpActingAs(['id' => 2, 'role' => 'guest']);
        $this->assertMcpResourceTemplateNotListed('orders://{number}');
        $denied = $this->mcpComplete(['type' => 'ref/resource', 'uri' => 'orders://{number}'], 'number', '10');
        $unknown = $this->mcpComplete(['type' => 'ref/resource', 'uri' => 'nothing://{number}'], 'number', '10');
        $this->assertArrayHasKey('error', $denied);
        $this->assertSame(
            str_replace('nothing://', 'orders://', (string)json_encode($unknown['error'], JSON_UNESCAPED_SLASHES)),
            (string)json_encode($denied['error'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function testTheConcreteUrisOfADeniedTemplateAreDenied(): void
    {
        $this->mcpActingAs(['id' => 2, 'role' => 'guest']);

        $this->mcpReadResource('orders://1001');
        $this->assertMcpResourceDenied();

        $denied = $this->mcpRequest('resources/subscribe', ['uri' => 'orders://1001']);
        $unknown = $this->mcpRequest('resources/subscribe', ['uri' => 'nothing://1001']);
        $this->assertArrayHasKey('error', $denied);
        $this->assertSame(
            str_replace('nothing://1001', 'orders://1001', (string)json_encode($unknown['error'], JSON_UNESCAPED_SLASHES)),
            (string)json_encode($denied['error'], JSON_UNESCAPED_SLASHES),
        );

        $denied = $this->mcpComplete(['type' => 'ref/resource', 'uri' => 'orders://1001'], 'number', '10');
        $unknown = $this->mcpComplete(['type' => 'ref/resource', 'uri' => 'nothing://1001'], 'number', '10');
        $this->assertArrayHasKey('error', $denied);
        $this->assertSame(
            str_replace('nothing://1001', 'orders://1001', (string)json_encode($unknown['error'], JSON_UNESCAPED_SLASHES)),
            (string)json_encode($denied['error'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function testRbacDeniesAConcreteUriWhenItsTemplateHasNoRule(): void
    {
        $orders = fn($user, $role, $request): bool => str_starts_with((string)$request->getParam('action'), 'orders://1');
        Configure::write('Mcp.authorization.authorizer', RbacToolAuthorizer::class);
        Configure::write('Mcp.authorization.rbac', ['permissions' => [
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => '*'],
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Resources', 'action' => '*',
                'allowed' => $orders],
        ]]);

        $this->mcpReadResource('orders://1001');
        $this->assertMcpResourceDenied();

        Configure::write('Mcp.authorization.rbac', ['permissions' => [
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => '*'],
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Resources',
                'action' => ['orders://{number}']],
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Resources', 'action' => '*',
                'allowed' => $orders],
        ]]);
        $this->mcpReadResource('orders://1001');
        $this->assertMcpResourceText('Order 1001');
        $this->mcpReadResource('orders://2001');
        $this->assertMcpResourceDenied();
    }

    public function testSubscribingToADeniedResourceAnswersLikeAnUnknownOne(): void
    {
        $denied = $this->mcpRequest('resources/subscribe', ['uri' => 'app://salaries']);
        $unknown = $this->mcpRequest('resources/subscribe', ['uri' => 'app://salaries2']);

        $this->assertArrayHasKey('error', $denied);
        $this->assertSame(
            str_replace('app://salaries2', 'app://salaries', (string)json_encode($unknown['error'], JSON_UNESCAPED_SLASHES)),
            (string)json_encode($denied['error'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function testADeniedPromptIsNotListedAndAnswersLikeAnUnknownOne(): void
    {
        $this->assertMcpPromptNotListed('payroll');
        $this->assertMcpPromptListed('greet');

        $denied = $this->mcpGetPrompt('payroll');
        $this->assertMcpPromptDenied();
        $unknown = $this->mcpGetPrompt('payroll2');
        $this->assertSame(
            str_replace('payroll2', 'payroll', (string)json_encode($unknown['error'], JSON_UNESCAPED_SLASHES)),
            (string)json_encode($denied['error'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function testPromptDeniedFailsForAPromptThatWasGot(): void
    {
        $this->mcpGetPrompt('greet', ['name' => 'Ada']);

        $this->expectException(AssertionFailedError::class);
        $this->assertMcpPromptDenied();
    }

    public function testCompletingADeniedPromptAnswersLikeAnUnknownOne(): void
    {
        $this->mcpActingAs(['id' => 2, 'role' => 'guest']);

        $denied = $this->mcpComplete(['type' => 'ref/prompt', 'name' => 'greet'], 'name', 'A');
        $unknown = $this->mcpComplete(['type' => 'ref/prompt', 'name' => 'greet2'], 'name', 'A');

        $this->assertArrayHasKey('error', $denied);
        $this->assertSame(
            str_replace('greet2', 'greet', (string)json_encode($unknown['error'], JSON_UNESCAPED_SLASHES)),
            (string)json_encode($denied['error'], JSON_UNESCAPED_SLASHES),
        );
    }

    public function testUnsubscribingFromADeniedResourceAnswersLikeAnUnknownOne(): void
    {
        $denied = $this->mcpRequest('resources/unsubscribe', ['uri' => 'app://salaries']);
        $unknown = $this->mcpRequest('resources/unsubscribe', ['uri' => 'app://salaries2']);

        $this->assertArrayHasKey('error', $unknown);
        $this->assertSame(
            str_replace('app://salaries2', 'app://salaries', (string)json_encode($unknown['error'], JSON_UNESCAPED_SLASHES)),
            (string)json_encode($denied['error'] ?? $denied, JSON_UNESCAPED_SLASHES),
        );
    }

    public function testOnANamedServer(): void
    {
        Configure::write('Mcp.authorization.authorizer', RbacToolAuthorizer::class);
        Configure::write('Mcp.servers', ['content' => [
            'route' => '/mcp/content',
            'discovery' => ['scanDirs' => ['src/McpContent']],
            'authorization' => ['authorizer' => ContentAuthorizer::class],
        ]]);
        $this->mcpUseServer('content');

        $this->assertMcpResourceNotListed('app://salaries');
        $this->assertMcpPromptNotListed('payroll');
        $this->mcpReadResource('app://salaries');
        $this->assertMcpResourceDenied();
    }

    public function testAToolOnlyAuthorizerKeepsThe21Behaviour(): void
    {
        Configure::write('Mcp.authorization.authorizer', RbacToolAuthorizer::class);
        Configure::write('Mcp.authorization.rbac', ['permissions' => [
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => '*'],
        ]]);
        $this->mcpActingAs(null);

        $this->assertMcpResourceListed('app://salaries');
        $this->assertMcpPromptListed('payroll');
        $this->mcpReadResource('app://salaries');
        $this->assertMcpResourceText('{"total": 100}');
        $this->mcpGetPrompt('payroll');
        $this->assertMcpPromptContains('Run the payroll.');
    }

    public function testRbacDecidesForResourcesAndPromptsOnlyWithRulesForThem(): void
    {
        Configure::write('Mcp.authorization.authorizer', RbacToolAuthorizer::class);
        Configure::write('Mcp.authorization.rbac', ['permissions' => [
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => '*'],
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Resources', 'action' => ['app://rules']],
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Prompts', 'action' => 'greet'],
        ]]);

        $this->assertMcpResourceListed('app://rules');
        $this->assertMcpResourceNotListed('app://salaries');
        $this->assertMcpResourceTemplateNotListed('orders://{number}');
        $this->mcpReadResource('orders://1001');
        $this->assertMcpResourceDenied();
        $this->assertMcpPromptListed('greet');
        $this->assertMcpPromptNotListed('payroll');
    }

    public function testTheToolCatalogWorksNextToAnAuthorizerForResourcesAndPrompts(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/Mcp', 'src/McpContent']);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['echo']]);

        $this->assertMcpToolListed('find_tools');
        $this->assertMcpResourceNotListed('app://salaries');
        $this->assertMcpResourceListed('app://rules');
    }

    public function testTheToolCatalogWorksNextToRbacRulesForResources(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/Mcp', 'src/McpContent']);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['echo']]);
        Configure::write('Mcp.authorization.authorizer', RbacToolAuthorizer::class);
        Configure::write('Mcp.authorization.rbac', ['permissions' => [
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => '*'],
            ['role' => 'user', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Resources', 'action' => ['app://rules']],
        ]]);

        $this->assertMcpToolListed('find_tools');
        $this->assertMcpResourceNotListed('app://salaries');
    }
}
