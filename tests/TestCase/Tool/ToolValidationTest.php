<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Tool;

use Cake\TestSuite\TestCase;
use Cake\Validation\Validator;
use CakeDC\Mcp\TestSuite\RecordingTransport;
use CakeDC\Mcp\Tool\ToolValidation;
use Mcp\Exception\PromptGetException;
use Mcp\Exception\ToolCallException;
use Mcp\Server;
use stdClass;

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

    public function testPromptArgumentsThrowAPromptError(): void
    {
        $this->expectException(PromptGetException::class);
        $this->expectExceptionMessage("The report was not prepared.\n- quantity: Between 1 and 100");

        ToolValidation::checkPrompt(['quantity' => 500], $this->validator(), 'The report was not prepared.');
    }

    public function testThePromptErrorReachesTheClient(): void
    {
        $transport = new RecordingTransport(array_map(fn(array $m): string => (string)json_encode($m), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '1'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/get', 'params' => [
                'name' => 'order_report',
                'arguments' => ['quantity' => '500'],
            ]],
        ]));
        Server::builder()
            ->setServerInfo('test', '1')
            ->addPrompt(function (string $quantity): array {
                ToolValidation::checkPrompt(['quantity' => (int)$quantity], $this->validator());

                return [['role' => 'user', 'content' => 'Report ' . $quantity]];
            }, 'order_report')
            ->build()
            ->run($transport);

        $this->assertStringContainsString(
            '- quantity: Between 1 and 100',
            (string)($transport->responseFor(2)['error']['message'] ?? ''),
        );
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
