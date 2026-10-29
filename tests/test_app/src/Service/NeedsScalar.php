<?php
declare(strict_types=1);

namespace TestApp\Service;

class NeedsScalar
{
    public function __construct(public readonly string $required)
    {
    }
}
