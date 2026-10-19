<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Authorization;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolGate;
use CakeDC\Mcp\Identity\CurrentIdentity;

class ToolGateTest extends TestCase
{
    public function testAsksTheAuthorizerWithTheCurrentIdentity(): void
    {
        $identity = new CurrentIdentity();
        $identity->set(['id' => 1, 'role' => 'editor']);
        $authorizer = new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return $identity['role'] === 'editor' && $tool === 'echo';
            }
        };
        $gate = new ToolGate($authorizer, $identity);

        $this->assertTrue($gate->allows('echo'));
        $this->assertFalse($gate->allows('admin_only'));
    }

    public function testTrustedGateAllowsEverything(): void
    {
        $denyAll = new class implements ToolAuthorizerInterface {
            public function canCall(mixed $identity, string $tool): bool
            {
                return false;
            }
        };

        $this->assertTrue((new ToolGate($denyAll, new CurrentIdentity(), true))->allows('anything'));
    }
}
