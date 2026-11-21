<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Server;

use CakeDC\Mcp\Attribute\McpWhen;
use InvalidArgumentException;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;

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
            // Keyed by the element's own name or URI: a registry of the hook's own may return its pages as lists.
            $references = [];
            foreach ($page->references as $key => $element) {
                $references[self::key($element, $key)] = $element;
            }
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
     * The name of a tool or prompt, the URI of a resource or the URI template of a template.
     *
     * @internal
     * @param mixed $element A definition from a registry page.
     * @param int|string $key Its key in the page, used when the definition has none of these.
     * @return string
     */
    public static function key(mixed $element, int|string $key): string
    {
        return match (true) {
            $element instanceof Tool, $element instanceof Prompt => $element->name,
            $element instanceof ResourceDefinition => $element->uri,
            $element instanceof ResourceTemplate => $element->uriTemplate,
            default => (string)$key,
        };
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
