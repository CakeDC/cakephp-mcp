<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Catalog;

use Mcp\Server\Transport\Http\OAuth\ScopePolicy;

/**
 * The scopes a JSON-RPC payload needs once `call_tools` is counted for what it runs: the policy reads only the
 * `name` of a `tools/call`, so each catalogued tool a `call_tools` names is added as a `tools/call` of its own.
 */
final class CatalogScopes
{
    /**
     * @param \Mcp\Server\Transport\Http\OAuth\ScopePolicy $policy The server's scope policy.
     * @param mixed $payload A decoded JSON-RPC message, or a batch of them.
     * @param list<string> $patterns `Mcp.catalog.tools`: names outside the catalog add nothing (they are refused).
     * @return list<string> Sorted.
     */
    public static function required(ScopePolicy $policy, mixed $payload, array $patterns): array
    {
        $messages = is_array($payload) && array_is_list($payload) ? $payload : [$payload];
        $inner = [];
        foreach ($messages as $message) {
            if (
                !is_array($message)
                || ($message['method'] ?? null) !== 'tools/call'
                || ($message['params']['name'] ?? null) !== ToolCatalog::CALL
                || !is_array($message['params']['arguments']['calls'] ?? null)
            ) {
                continue;
            }
            foreach ($message['params']['arguments']['calls'] as $call) {
                $name = is_array($call) ? $call['name'] ?? null : null;
                if (is_string($name) && CatalogPatterns::matches($patterns, $name)) {
                    $inner[] = ['jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => ['name' => $name]];
                }
            }
        }
        $required = $policy->requiredFor($inner === [] ? $payload : [...$messages, ...$inner]);
        sort($required);

        return array_values(array_unique($required));
    }
}
