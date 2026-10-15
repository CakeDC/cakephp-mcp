<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\TestSuite;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\TestSuite\McpTestTrait;
use PHPUnit\Framework\AssertionFailedError;
use TestApp\Service\Clock;
use TestApp\Service\Greeter;

class McpTestTraitTest extends TestCase
{
    // IntegrationTestTrait shares ContainerStubTrait with McpTestTrait; using both must work.
    use IntegrationTestTrait;
    use McpTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::drop('mcp_sessions');
        Cache::setConfig('mcp_sessions', ['className' => ArrayEngine::class]);
    }

    protected function tearDown(): void
    {
        Cache::drop('mcp_sessions');
        parent::tearDown();
    }

    public function testCallsAToolAndReadsItsText(): void
    {
        $this->mcpCallTool('echo', ['text' => 'hello']);

        $this->assertMcpToolSucceeded();
        $this->assertMcpResultContains('hell');
        $this->assertSame('hello', $this->mcpResultText());
    }

    public function testToolsSeeTheIdentityAndTheAccessToken(): void
    {
        $this->mcpActingAs(['id' => 7]);

        $this->mcpCallTool('whoami');

        $this->assertMcpStructuredContent(['subject' => '7', 'identifier' => 7]);
    }

    public function testAnonymousByDefault(): void
    {
        $this->mcpCallTool('whoami');

        $this->assertMcpStructuredContent(['identifier' => null, 'subject' => null]);
    }

    public function testToolErrorsAreReadable(): void
    {
        $this->mcpCallTool('create_note', ['title' => 'ab']);

        $this->assertMcpToolError('- title: Use at least 3 characters.');
        $this->expectException(AssertionFailedError::class);
        $this->assertMcpToolSucceeded();
    }

    public function testUnexpectedExceptionsAreJsonRpcErrors(): void
    {
        $this->mcpCallTool('explode');

        $this->assertMcpJsonRpcError(-32603, 'Error while executing tool');
        $this->assertStringNotContainsString('secret', (string)json_encode($this->mcpResponse()));
    }

    public function testInvalidArgumentsAreJsonRpcErrors(): void
    {
        $this->mcpCallTool('echo', []);

        $this->assertMcpJsonRpcError(-32602, 'Invalid parameters');
    }

    public function testDeniedToolsAreHiddenAndRejected(): void
    {
        Configure::write('Mcp.authorization.authorizer', $this->onlyForAdmins('admin_only'));
        $this->mcpActingAs(['id' => 1, 'role' => 'user']);

        $this->assertMcpToolNotListed('admin_only');
        $this->assertMcpToolListed('echo');
        $this->mcpCallTool('admin_only');
        $this->assertMcpToolDenied();

        $this->mcpActingAs(['id' => 2, 'role' => 'admin']);
        $this->assertMcpToolListed('admin_only');
        $this->mcpCallTool('admin_only');
        $this->assertMcpToolSucceeded();
    }

    public function testDeniedFailsForAToolThatDoesNotExist(): void
    {
        $this->mcpCallTool('no_such_tool');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('is not registered');
        $this->assertMcpToolDenied();
    }

    public function testDeniedFailsWhenTheCallSucceeded(): void
    {
        $this->mcpCallTool('echo', ['text' => 'hi']);

        $this->expectException(AssertionFailedError::class);
        $this->assertMcpToolDenied();
    }

    public function testListToolsReturnsTheDefinitions(): void
    {
        $tools = array_column($this->mcpListTools(), null, 'name');

        $this->assertSame(['readOnlyHint' => true, 'idempotentHint' => true], $tools['echo']['annotations'] ?? null);
    }

    public function testMockedServicesReachTheTools(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/Mcp', 'src/McpGreeting']);
        $this->mockService(Greeter::class, fn(): Greeter => new Greeter(new Clock(), 'Hola'));

        $this->mcpCallTool('greet', ['name' => 'Ana']);

        $this->assertSame('Hola, Ana', $this->mcpResultText());
    }

    public function testAssertionsNeedACall(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('mcpCallTool()');
        $this->assertMcpToolSucceeded();
    }

    private function onlyForAdmins(string $tool): ToolAuthorizerInterface
    {
        return new class ($tool) implements ToolAuthorizerInterface {
            public function __construct(private readonly string $tool)
            {
            }

            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool !== $this->tool || (is_array($identity) && ($identity['role'] ?? null) === 'admin');
            }
        };
    }
}
