<?php
declare(strict_types=1);

namespace TestApp\Service;

class CycleA
{
    public function __construct(public readonly CycleB $b)
    {
    }
}
