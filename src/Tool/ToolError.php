<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Tool;

use Cake\Datasource\EntityInterface;
use Mcp\Exception\ToolCallException;

/**
 * Builds the exceptions a tool throws to tell the model what went wrong.
 *
 * The SDK sends the message of a `ToolCallException` back as a tool error the
 * model reads, so it can fix its input and try again. Any other exception only
 * reaches the client as a generic internal error.
 */
final class ToolError
{
    /**
     * A validation failure, one line per field.
     *
     * Takes an entity with errors (for example the one of a failed save, or of
     * `PersistenceFailedException::getEntity()`), or the nested array that
     * `Validator::validate()` and `EntityInterface::getErrors()` return.
     *
     * @param \Cake\Datasource\EntityInterface|array<mixed> $errors The entity or its errors.
     * @param string $message The first line of the message.
     * @return \Mcp\Exception\ToolCallException
     */
    public static function invalid(
        EntityInterface|array $errors,
        string $message = 'The data is invalid.',
    ): ToolCallException {
        $lines = [$message];
        $fields = self::flatten($errors instanceof EntityInterface ? $errors->getErrors() : $errors);
        foreach ($fields as $field => $messages) {
            $text = implode('; ', $messages);
            $lines[] = $field === '' ? '- ' . $text : sprintf('- %s: %s', $field, $text);
        }

        return new ToolCallException(implode("\n", $lines));
    }

    /**
     * Turns Cake's nested errors into messages keyed by dotted field path. A
     * string is a message, and its key is the name of the rule that failed.
     *
     * @param array<mixed> $errors Nested errors.
     * @param string $path The path of $errors.
     * @return array<string, list<string>>
     */
    private static function flatten(array $errors, string $path = ''): array
    {
        $flat = [];
        foreach ($errors as $key => $value) {
            if (is_string($value)) {
                $flat[$path][] = $value;

                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            $child = $path === '' ? (string)$key : $path . '.' . $key;
            foreach (self::flatten($value, $child) as $field => $messages) {
                $flat[$field] = array_merge($flat[$field] ?? [], $messages);
            }
        }

        return $flat;
    }
}
