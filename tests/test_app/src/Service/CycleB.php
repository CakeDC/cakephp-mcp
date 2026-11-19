<?php
declare(strict_types=1);

namespace TestApp\Service;

class CycleB
{
    public function __construct(public readonly CycleA $a)
    {
    }
}
