<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use CakeDC\Mcp\Client\Http\HostResolverInterface;

/**
 * DNS for tests: a fixed map of host => addresses. Hosts it does not know resolve to nothing.
 */
final class StaticHostResolver implements HostResolverInterface
{
    /**
     * @var list<string>
     */
    public array $asked = [];

    /**
     * @param array<string, list<string>> $hosts Host => addresses.
     */
    public function __construct(private array $hosts = [])
    {
    }

    /**
     * @param string $host The host.
     * @param list<string> $addresses Its addresses.
     * @return void
     */
    public function add(string $host, array $addresses): void
    {
        $this->hosts[$host] = $addresses;
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $host): array
    {
        $this->asked[] = $host;

        return $this->hosts[$host] ?? [];
    }
}
