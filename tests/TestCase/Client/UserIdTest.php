<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use ArrayObject;
use Authentication\Identity;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\UserId;
use InvalidArgumentException;
use stdClass;

class UserIdTest extends TestCase
{
    public function testScalars(): void
    {
        $this->assertSame('7', UserId::of(7));
        $this->assertSame('a-uuid', UserId::of('a-uuid'));
    }

    public function testIdentityEntityAndArray(): void
    {
        $this->assertSame('5', UserId::of(new Identity(new ArrayObject(['id' => 5]))));
        $this->assertSame('6', UserId::of(new Entity(['id' => 6])));
        $this->assertSame('8', UserId::of(['id' => 8, 'name' => 'Ana']));
        $this->assertSame('9', UserId::of(new ArrayObject(['id' => 9])));
    }

    public function testNoIdFails(): void
    {
        foreach ([null, '', [], new stdClass(), ['name' => 'x'], new Entity([]), true] as $user) {
            try {
                UserId::of($user);
                $this->fail('No exception for ' . get_debug_type($user));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('user', $e->getMessage());
            }
        }
    }
}
