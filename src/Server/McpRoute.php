<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Server;

/**
 * The values of the parameters of the current server's route, for a named server on a route such as
 * `/mcp/{tenant}`: inject it into a tool (or read `$request->getParam('tenant')`). Empty for a route without
 * parameters, and over stdio.
 */
final class McpRoute
{
    /**
     * @var array<string, string>
     */
    private array $values = [];

    /**
     * @param array<string, string> $values Parameter name => value.
     * @return void
     */
    public function set(array $values): void
    {
        $this->values = $values;
    }

    /**
     * @return array<string, string> Parameter name => value.
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * @param string $name A parameter of the route.
     * @return string|null Its value in this request, or null.
     */
    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }
}
