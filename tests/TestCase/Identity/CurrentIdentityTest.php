<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Identity;

use ArrayObject;
use Authentication\Identity;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Identity\CurrentIdentity;

class CurrentIdentityTest extends TestCase
{
    public function testIdentifierFromEveryIdentityShape(): void
    {
        $this->assertSame(7, CurrentIdentity::identifierOf(['id' => 7]));
        $this->assertSame('u-1', CurrentIdentity::identifierOf(new ArrayObject(['id' => 'u-1'])));
        $this->assertSame(9, CurrentIdentity::identifierOf(new Entity(['id' => 9])));
        $this->assertSame(11, CurrentIdentity::identifierOf(new Identity(new ArrayObject(['id' => 11]))));
    }

    public function testIdentifierIsNullForMissingOrOddValues(): void
    {
        $this->assertNull(CurrentIdentity::identifierOf(null));
        $this->assertNull(CurrentIdentity::identifierOf(['name' => 'no id']));
        $this->assertNull(CurrentIdentity::identifierOf(['id' => 1.5]));
        $this->assertNull(CurrentIdentity::identifierOf('a string'));
    }

    public function testHoldsTheIdentity(): void
    {
        $current = new CurrentIdentity();
        $this->assertFalse($current->has());

        $current->set(['id' => 3]);

        $this->assertTrue($current->has());
        $this->assertSame(['id' => 3], $current->get());
        $this->assertSame(3, $current->getIdentifier());
    }
}
