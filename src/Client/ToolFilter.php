<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

/**
 * A client's `tools.only` and `tools.except` (shell patterns, `fnmatch`, case sensitive, matched against the whole
 * name). `except` wins over `only`; an empty `only` allows every tool.
 */
final class ToolFilter
{
    /**
     * @param list<string> $only Patterns of the tools allowed; empty for all.
     * @param list<string> $except Patterns of the tools refused.
     */
    public function __construct(private array $only, private array $except)
    {
    }

    /**
     * @param string $name A tool name.
     * @return bool Whether the client may list and call it.
     */
    public function allows(string $name): bool
    {
        if (self::matches($this->except, $name)) {
            return false;
        }

        return $this->only === [] || self::matches($this->only, $name);
    }

    /**
     * @param list<string> $patterns The patterns.
     * @param string $name The tool name.
     * @return bool
     */
    private static function matches(array $patterns, string $name): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
