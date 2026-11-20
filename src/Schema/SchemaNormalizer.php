<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Schema;

/**
 * Simplifies the optional parameters of a tool input schema, for hosts that reject type arrays.
 *
 * Only top-level properties that are not required change: `"null"` leaves a `type` list (a single type left
 * becomes a plain string), `null` leaves an `enum`, and a `default` of `null` is dropped. Leaving out an optional
 * argument already means "no value", so the advertised schema loses nothing a client needs.
 */
final class SchemaNormalizer
{
    /**
     * @param array<string, mixed> $inputSchema A tool input schema.
     * @return array<string, mixed>
     */
    public static function normalize(array $inputSchema): array
    {
        $properties = $inputSchema['properties'] ?? null;
        if (!is_array($properties) || $properties === []) {
            return $inputSchema;
        }
        $required = is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [];

        foreach ($properties as $name => $property) {
            if (!is_array($property) || in_array($name, $required, true)) {
                continue;
            }
            $properties[$name] = self::optional($property);
        }
        $inputSchema['properties'] = $properties;

        return $inputSchema;
    }

    /**
     * @param array<string, mixed> $property The schema of an optional parameter.
     * @return array<string, mixed>
     */
    private static function optional(array $property): array
    {
        if (is_array($property['type'] ?? null) && in_array('null', $property['type'], true)) {
            $types = array_values(array_filter($property['type'], fn(mixed $type): bool => $type !== 'null'));
            $property['type'] = count($types) === 1 ? $types[0] : $types;
        }
        if (is_array($property['enum'] ?? null) && in_array(null, $property['enum'], true)) {
            $property['enum'] = array_values(array_filter($property['enum'], fn(mixed $value): bool => $value !== null));
        }
        if (array_key_exists('default', $property) && $property['default'] === null) {
            unset($property['default']);
        }

        return $property;
    }
}
