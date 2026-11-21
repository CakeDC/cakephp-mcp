<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\Http;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\Exception\ClientException;
use CakeDC\Mcp\Client\Http\Deadline;

class DeadlineTest extends TestCase
{
    public function testEachRequestGetsWhatIsLeftOfTheWhole(): void
    {
        $clock = new class {
            public float $now = 1000.0;
        };
        $deadline = new Deadline(10.0, fn(): float => $clock->now);

        $this->assertSame(5.0, $deadline->timeout(5.0, 'https://auth.example.com/x'));
        $clock->now += 4.0;
        $this->assertSame(6.0, $deadline->timeout(30.0, 'https://auth.example.com/x'));
        $clock->now += 6.0;
        try {
            $deadline->timeout(30.0, 'https://auth.example.com/x?secret=1');
            $this->fail('No exception once the time is up');
        } catch (ClientException $e) {
            $this->assertSame(
                'The server at auth.example.com took too long: the requests of this step may take 10 seconds in all.',
                $e->getMessage(),
            );
        }
    }
}
