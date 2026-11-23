<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Schema;

/**
 * Finds what some MCP hosts dislike in a tool input schema: type arrays, arrays whose items have no type, and
 * optional parameters without a description.
 */
final class SchemaLint
{
    /**
     * @param array<string, mixed> $tool One tool of a decoded `tools/list` result.
     * @return list<string> One message per problem, starting with the parameter name.
     */
    public static function problems(array $tool): array
    {
        $schema = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];

        return self::walk($schema, '');
    }

    /**
     * The problems of the properties of $schema, and of the objects nested in them (`filter.date_from`) or in
     * their items (`rows[].tags`).
     *
     * @param array<mixed> $schema An object schema.
     * @param string $prefix The path of $schema, ending with `.`, or `` for the input schema.
     * @return list<string>
     */
    private static function walk(array $schema, string $prefix): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        $problems = [];
        foreach ($properties as $name => $property) {
            if (!is_array($property)) {
                continue;
            }
            $path = $prefix . $name;
            $type = $property['type'] ?? null;
            if (is_array($type)) {
                $problems[] = sprintf('%s: type union %s', $path, implode('|', array_map('strval', $type)));
            }
            if ($type === 'array' && ($property['items'] ?? []) === []) {
                $problems[] = sprintf('%s: array without item type', $path);
            }
            $description = $property['description'] ?? null;
            if (!in_array($name, $required, true) && (!is_string($description) || trim($description) === '')) {
                $problems[] = sprintf('%s: optional without a description', $path);
            }
            $problems = [...$problems, ...self::walk($property, $path . '.')];
            if (is_array($property['items'] ?? null)) {
                $problems = [...$problems, ...self::walk($property['items'], $path . '[].')];
            }
        }

        return $problems;
    }
}
