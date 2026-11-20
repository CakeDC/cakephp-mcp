<?php
declare(strict_types=1);

namespace TestApp\McpWhen;

/**
 * Conditions for `#[McpWhen(callback: ...)]`.
 */
class Flags
{
    /**
     * @param string $server The MCP server being built.
     */
    public static function onlyBeta(string $server): bool
    {
        return $server === 'beta';
    }
}
