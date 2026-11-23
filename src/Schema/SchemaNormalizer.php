<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Schema;

/**
 * Simplifies the optional parameters of a tool input schema, for hosts that reject type arrays.
 *
 * Only properties that are not required (at any level: nested objects and the items of arrays follow the same
 * rule with their own `required`) and whose default is `null` (or absent) change: `"null"` leaves
 * a `type` list (a single type left becomes a plain string), `null` leaves an `enum`, and the `default` of `null` is
 * dropped. Leaving out such an argument already gives `null`, so the advertised schema loses nothing a client needs.
 * A nullable parameter with another default (`?string $status = 'open'`) keeps its union: there `null` is a value
 * of its own, and `bin/cake mcp check` keeps reporting it.
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
            if (!is_array($property)) {
                continue;
            }
            // The objects nested in a property, or in its items, follow the same rule with their own `required`.
            $property = self::normalize($property);
            if (is_array($property['items'] ?? null)) {
                $property['items'] = self::normalize($property['items']);
            }
            if (!in_array($name, $required, true) && ($property['default'] ?? null) === null) {
                $property = self::optional($property);
            }
            $properties[$name] = $property;
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
            $property['enum'] = array_values(array_filter($property['enum'], fn(mixed $v): bool => $v !== null));
        }
        if (array_key_exists('default', $property) && $property['default'] === null) {
            unset($property['default']);
        }

        return $property;
    }
}
