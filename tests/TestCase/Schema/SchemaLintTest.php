<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Schema;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Schema\SchemaLint;

class SchemaLintTest extends TestCase
{
    public function testReportsUnionsUntypedArraysAndMissingDescriptions(): void
    {
        $problems = SchemaLint::problems(['name' => 'report', 'inputSchema' => ['type' => 'object', 'properties' => [
            'date_from' => ['type' => ['null', 'string'], 'description' => 'First day.'],
            'statuses' => ['type' => 'array', 'items' => [], 'description' => 'Ids.'],
            'page' => ['type' => 'integer'],
        ]]]);

        $this->assertSame([
            'date_from: type union null|string',
            'statuses: array without item type',
            'page: optional without a description',
        ], $problems);
    }

    public function testCleanAndEmptySchemasHaveNoProblems(): void
    {
        $this->assertSame([], SchemaLint::problems([
            'name' => 'a',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ]));
        $this->assertSame([], SchemaLint::problems(['name' => 'b', 'inputSchema' => [
            'type' => 'object',
            'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer']]],
            'required' => ['ids'],
        ]]));
    }
}
