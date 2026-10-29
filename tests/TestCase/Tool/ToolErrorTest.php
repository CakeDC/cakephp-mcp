<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Tool;

use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Tool\ToolError;

class ToolErrorTest extends TestCase
{
    public function testListsEachFieldWithItsMessages(): void
    {
        $exception = ToolError::invalid([
            'title' => ['_empty' => 'This field cannot be left empty'],
            'slug' => ['unique' => 'Already taken', 'format' => 'Use lowercase letters'],
        ]);

        $this->assertSame(
            "The data is invalid.\n- title: This field cannot be left empty\n- slug: Already taken; Use lowercase letters",
            $exception->getMessage(),
        );
    }

    public function testFlatFieldMessagesKeepTheFieldName(): void
    {
        $this->assertSame(
            "The data is invalid.\n- title: Required\n- slug: Taken",
            ToolError::invalid(['title' => 'Required', 'slug' => 'Taken'])->getMessage(),
        );
    }

    public function testNestedErrorsUseDottedPaths(): void
    {
        $exception = ToolError::invalid([
            'tags' => [0 => ['name' => ['_required' => 'This field is required']]],
        ]);

        $this->assertSame("The data is invalid.\n- tags.0.name: This field is required", $exception->getMessage());
    }

    public function testReadsTheErrorsOfAnEntityAndItsAssociations(): void
    {
        $tag = new Entity();
        $tag->setError('name', ['_required' => 'This field is required']);
        $article = new Entity(['tags' => [$tag]]);
        $article->setError('title', ['_empty' => 'This field cannot be left empty']);

        $this->assertSame(
            "The data is invalid.\n- title: This field cannot be left empty\n- tags.0.name: This field is required",
            ToolError::invalid($article)->getMessage(),
        );
    }

    public function testCustomFirstLine(): void
    {
        $exception = ToolError::invalid(['title' => ['_empty' => 'Empty']], 'The article was not saved.');

        $this->assertSame("The article was not saved.\n- title: Empty", $exception->getMessage());
    }

    public function testMessageWithoutAFieldIsListedAlone(): void
    {
        $this->assertSame("The data is invalid.\n- Something is off", ToolError::invalid(['Something is off'])->getMessage());
    }

    public function testNoErrorsLeavesOnlyTheFirstLine(): void
    {
        $this->assertSame('The data is invalid.', ToolError::invalid([])->getMessage());
    }
}
