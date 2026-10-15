<?php
declare(strict_types=1);

namespace TestApp\McpGreeting;

use Mcp\Capability\Attribute\McpTool;
use TestApp\Service\Greeter;

class GreetingTools
{
    public function __construct(private readonly Greeter $greeter)
    {
    }

    /**
     * Greets someone with the injected greeter.
     */
    #[McpTool(name: 'greet')]
    public function greet(string $name): string
    {
        return $this->greeter->greeting . ', ' . $name;
    }
}
