<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Tool;

use Cake\TestSuite\TestCase;
use Cake\Validation\Validator;
use CakeDC\Mcp\Tool\ToolValidation;
use Mcp\Exception\ToolCallException;

class ToolValidationTest extends TestCase
{
    public function testValidArgumentsPass(): void
    {
        ToolValidation::check(['quantity' => 3, 'email' => 'buyer@example.com'], $this->validator());

        $this->addToAssertionCount(1);
    }

    public function testInvalidArgumentsThrowOneLinePerField(): void
    {
        try {
            ToolValidation::check(['quantity' => 0, 'email' => 'x'], $this->validator());
            $this->fail('No exception was thrown.');
        } catch (ToolCallException $exception) {
            $lines = explode("\n", $exception->getMessage());
            $this->assertSame('The arguments are invalid.', $lines[0]);
            $this->assertContains('- quantity: Between 1 and 100', $lines);
            $this->assertStringStartsWith('- email: ', $lines[2]);
        }
    }

    public function testCustomFirstLine(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("The order was not placed.\n- quantity: Between 1 and 100");

        ToolValidation::check(['quantity' => 500], $this->validator(), 'The order was not placed.');
    }

    public function testUpdateRulesWithNewRecordFalse(): void
    {
        $validator = (new Validator())->requirePresence('id', 'update', 'The id is required to update.');

        ToolValidation::check([], $validator);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('- id: The id is required to update.');
        ToolValidation::check([], $validator, newRecord: false);
    }

    private function validator(): Validator
    {
        return (new Validator())
            ->range('quantity', [1, 100], 'Between 1 and 100')
            ->email('email');
    }
}
