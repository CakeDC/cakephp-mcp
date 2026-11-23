<?php
declare(strict_types=1);

namespace CakeDC\Mcp\OAuth;

use RuntimeException;

/**
 * The MCP resource is not declared in `OAuthServer.resources`. On a fixed route the application is misconfigured;
 * on a route with parameters it is a value nobody declared, which the endpoint does not serve.
 */
final class UndeclaredResourceException extends RuntimeException
{
}
