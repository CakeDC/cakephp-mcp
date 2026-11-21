<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Tool;

use Cake\Validation\Validator;
use Mcp\Exception\PromptGetException;

/**
 * Validates the arguments of a tool with a Cake Validator.
 *
 * The input schema already rejects arguments of the wrong shape or type. A Validator is for the rules a schema
 * cannot say: ranges that depend on the data, rules across fields, the application's own validators.
 */
final class ToolValidation
{
    /**
     * Throws a tool error, one line per field, when the arguments break a rule of the validator.
     *
     * @param array<string, mixed> $arguments The tool's arguments.
     * @param \Cake\Validation\Validator $validator The rules.
     * @param string $message The first line of the error.
     * @param bool $newRecord False to apply the validator's update rules (`'update'` presence, for example).
     * @return void
     * @throws \Mcp\Exception\ToolCallException When a rule fails; the model reads its message.
     */
    public static function check(
        array $arguments,
        Validator $validator,
        string $message = 'The arguments are invalid.',
        bool $newRecord = true,
    ): void {
        $errors = $validator->validate($arguments, $newRecord);
        if ($errors !== []) {
            throw ToolError::invalid($errors, $message);
        }
    }

    /**
     * The same check for the arguments of a prompt: the client gets the lines as the error of `prompts/get`.
     *
     * @param array<string, mixed> $arguments The prompt's arguments.
     * @param \Cake\Validation\Validator $validator The rules.
     * @param string $message The first line of the error.
     * @param bool $newRecord False to apply the validator's update rules.
     * @return void
     * @throws \Mcp\Exception\PromptGetException When a rule fails.
     */
    public static function checkPrompt(
        array $arguments,
        Validator $validator,
        string $message = 'The arguments are invalid.',
        bool $newRecord = true,
    ): void {
        $errors = $validator->validate($arguments, $newRecord);
        if ($errors !== []) {
            throw new PromptGetException(ToolError::invalid($errors, $message)->getMessage());
        }
    }
}
