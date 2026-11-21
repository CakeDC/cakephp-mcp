<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

/**
 * A tool object that keeps state for the life of the server: it answers how many times it was called.
 */
final class CountingTool
{
    private int $calls = 0;

    /**
     * @return string The number of calls to this object.
     */
    public function bump(): string
    {
        return (string)++$this->calls;
    }
}
