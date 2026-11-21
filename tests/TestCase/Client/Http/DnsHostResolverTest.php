<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\Http;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\Http\DnsHostResolver;

class DnsHostResolverTest extends TestCase
{
    public function testANameTheDnsDoesNotKnowIsLookedUpLikeTheSystemDoes(): void
    {
        // `localhost` in a Linux container, or a name only in /etc/hosts: DNS has no record of it.
        $resolver = new DnsHostResolver(fn(string $host): array => [], fn(string $host): array => ['127.0.0.1']);

        $this->assertSame(['127.0.0.1'], $resolver->resolve('localhost'));
    }

    public function testTheDnsAnswerIsUsedWhenThereIsOne(): void
    {
        $resolver = new DnsHostResolver(
            fn(string $host): array => ['93.184.216.34', '2606:2800:220:1::'],
            fn(string $host): array => ['10.0.0.1'],
        );

        $this->assertSame(['93.184.216.34', '2606:2800:220:1::'], $resolver->resolve('example.com'));
    }

    public function testAnAddressIsItsOwnAnswer(): void
    {
        $this->assertSame(['10.0.0.1'], (new DnsHostResolver())->resolve('10.0.0.1'));
    }
}
