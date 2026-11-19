<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Authorization;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolCallGuard;
use CakeDC\Mcp\Authorization\ToolGate;
use CakeDC\Mcp\Identity\CurrentIdentity;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Server\Session\SessionInterface;

class ToolCallGuardTest extends TestCase
{
    private ToolCallGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $onlyEcho = new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $tool === 'echo';
            }
        };
        $this->guard = new ToolCallGuard(new ToolGate($onlyEcho, new CurrentIdentity()));
    }

    public function testStepsAsideForAllowedCallsAndOtherMethods(): void
    {
        $this->assertFalse($this->guard->supports((new CallToolRequest('echo', []))->withId(1)));
        $this->assertFalse($this->guard->supports((new ListToolsRequest())->withId(2)));
    }

    public function testAnswersDeniedCallsLikeAnUnknownTool(): void
    {
        $request = (new CallToolRequest('admin_only', []))->withId(7);

        $this->assertTrue($this->guard->supports($request));
        $error = $this->guard->handle($request, $this->createStub(SessionInterface::class));

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame(Error::INVALID_PARAMS, $error->code);
        $this->assertSame('Tool not found: "admin_only".', $error->message);
        $this->assertSame(7, $error->id);
    }
}
