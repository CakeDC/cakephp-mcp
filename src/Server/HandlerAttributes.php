<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Server;

use Mcp\Capability\Registry\ElementReference;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Reads the plugin's own attributes (`#[McpWhen]`, `#[McpApp]`) from the handler of a registered element.
 */
final class HandlerAttributes
{
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
        $handler = $reference->handler;
        if (is_string($handler) && class_exists($handler)) {
            $handler = [$handler, '__invoke'];
        }
        if (!is_array($handler)) {
            return null;
        }
        $owner = is_object($handler[0]) ? $handler[0]::class : $handler[0];
        if (!class_exists($owner)) {
            return null;
        }
        try {
            $attributes = (new ReflectionMethod($owner, $handler[1]))->getAttributes($class);
            if ($attributes === []) {
                $attributes = (new ReflectionClass($owner))->getAttributes($class);
            }
        } catch (ReflectionException) {
            return null;
        }

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
