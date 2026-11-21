<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Server;

use CakeDC\Mcp\Attribute\McpWhen;
use InvalidArgumentException;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\RegistryInterface;

/**
 * Removes from a loaded registry the elements whose `#[McpWhen]` does not hold on a server.
 */
final class Conditions
{
    /**
     * @param \Mcp\Capability\RegistryInterface $registry A loaded registry.
     * @param string $server The MCP server being built.
     * @return list<string> What was removed: `<kind> <name> (<why>)`.
     * @throws \InvalidArgumentException When a condition cannot be evaluated; the message names the element.
     */
    public static function prune(RegistryInterface $registry, string $server): array
    {
        $skipped = [];
        $kinds = [
            'tool' => [$registry->getTools(), $registry->getTool(...), $registry->unregisterTool(...)],
            'resource' => [
                $registry->getResources(),
                fn(string $uri): ElementReference => $registry->getResource($uri, false),
                $registry->unregisterResource(...),
            ],
            'resource template' => [
                $registry->getResourceTemplates(),
                $registry->getResourceTemplate(...),
                $registry->unregisterResourceTemplate(...),
            ],
            'prompt' => [$registry->getPrompts(), $registry->getPrompt(...), $registry->unregisterPrompt(...)],
        ];
        foreach ($kinds as $kind => [$page, $get, $unregister]) {
            $references = $page->references;
            // By name: discovery follows the file system, and `mcp check` output should not.
            ksort($references, SORT_STRING);
            foreach ($references as $key => $element) {
                $key = (string)$key;
                // The method's condition adds to its class's: every one must hold.
                foreach (HandlerAttributes::all($get($key), McpWhen::class) as $condition) {
                    try {
                        $allowed = $condition->allows($server);
                    } catch (InvalidArgumentException $exception) {
                        throw new InvalidArgumentException(
                            sprintf('%s %s: %s', $kind, $key, $exception->getMessage()),
                        );
                    }
                    if (!$allowed) {
                        $unregister($key);
                        $skipped[] = sprintf('%s %s (%s)', $kind, self::name($element, $key), $condition->describe());
                        break;
                    }
                }
            }
        }

        return $skipped;
    }

    /**
     * @param mixed $element The definition from the registry page.
     * @param string $key Its key (a name or a URI).
     * @return string The element's name, for messages.
     */
    private static function name(mixed $element, string $key): string
    {
        return is_object($element) && isset($element->name) && is_string($element->name) ? $element->name : $key;
    }
}
