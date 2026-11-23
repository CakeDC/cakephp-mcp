<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Server;

use Mcp\Capability\Registry\ElementReference;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Reads the plugin's own attributes (`#[McpWhen]`, `#[McpApp]`) from the handler of a registered element.
 */
final class HandlerAttributes
{
    /**
     * Every `$class` attribute of an element's handler: the method's first, then its class's.
     *
     * @template T of object
     * @param \Mcp\Capability\Registry\ElementReference $reference The element.
     * @param class-string<T> $class The attribute class.
     * @return list<T>
     */
    public static function all(ElementReference $reference, string $class): array
    {
        $handler = $reference->handler;
        if (is_string($handler) && class_exists($handler)) {
            $handler = [$handler, '__invoke'];
        }
        if (!is_array($handler)) {
            return [];
        }
        $owner = is_object($handler[0]) ? $handler[0]::class : $handler[0];
        if (!class_exists($owner)) {
            return [];
        }
        try {
            $attributes = [
                ...(new ReflectionMethod($owner, $handler[1]))->getAttributes($class),
                ...(new ReflectionClass($owner))->getAttributes($class),
            ];
        } catch (ReflectionException) {
            return [];
        }

        return array_map(fn(ReflectionAttribute $attribute): object => $attribute->newInstance(), $attributes);
    }

    /**
     * The first `$class` attribute of an element's handler: on the method first, then on its class (an invokable
     * class). Closures registered in code have none.
     *
     * @template T of object
     * @param \Mcp\Capability\Registry\ElementReference $reference The element.
     * @param class-string<T> $class The attribute class.
     * @return T|null
     */
    public static function first(ElementReference $reference, string $class): ?object
    {
        return self::all($reference, $class)[0] ?? null;
    }
}
