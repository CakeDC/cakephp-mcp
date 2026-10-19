<?php
declare(strict_types=1);

namespace TestApp\Service;

class Greeter
{
    public function __construct(public readonly Clock $clock, public readonly string $greeting = 'Hello')
    {
    }
}
