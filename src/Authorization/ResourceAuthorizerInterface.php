<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Authorization;

/**
 * Implemented by an authorizer (next to `ToolAuthorizerInterface`) that decides who may read which resource.
 * A denied resource is left out of `resources/list` and `resources/templates/list`, reading or subscribing to
 * it answers like an unknown URI, and completions for a denied template return no values. An authorizer that
 * does not implement it lets every caller read every resource, as in 2.1.
 */
interface ResourceAuthorizerInterface
{
    /**
     * Whether $identity may read $uri: a resource URI, the concrete URI read through a resource template, or a
     * template's URI (`orders://{number}`) when templates are listed or completed.
     *
     * @param mixed $identity The identity, or null when anonymous.
     * @param string $uri The URI.
     * @return bool
     */
    public function canReadResource(mixed $identity, string $uri): bool;
}
