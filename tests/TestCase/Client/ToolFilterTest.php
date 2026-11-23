<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ToolFilter;

class ToolFilterTest extends TestCase
{
    public function testNoPatternsAllowEverything(): void
    {
        $filter = new ToolFilter([], []);

        $this->assertTrue($filter->allows('anything'));
    }

    public function testOnly(): void
    {
        $filter = new ToolFilter(['get_*', 'search'], []);

        $this->assertTrue($filter->allows('get_issue'));
        $this->assertTrue($filter->allows('search'));
        $this->assertFalse($filter->allows('delete_issue'));
        $this->assertFalse($filter->allows('search_all'));
    }

    public function testExcept(): void
    {
        $filter = new ToolFilter([], ['delete_*']);

        $this->assertTrue($filter->allows('get_issue'));
        $this->assertFalse($filter->allows('delete_issue'));
    }

    public function testExceptWinsOverOnly(): void
    {
        $filter = new ToolFilter(['*_issue'], ['delete_*']);

        $this->assertTrue($filter->allows('get_issue'));
        $this->assertFalse($filter->allows('delete_issue'));
        $this->assertFalse($filter->allows('get_repo'));
    }

    public function testPatternsAreCaseSensitiveAndWholeName(): void
    {
        $filter = new ToolFilter(['get'], []);

        $this->assertFalse($filter->allows('GET'));
        $this->assertFalse($filter->allows('get_issue'));
    }
}
