<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Authorization;

/**
 * The default authorizer: every tool is allowed.
 *
 * Authentication is enforced before any tool is reached (`Mcp.identity.required`),
 * so on a private server only identified callers get here.
 */
final class AllowAllToolAuthorizer implements ToolAuthorizerInterface
{
    /**
     * @inheritDoc
     */
    public function canCall(mixed $identity, string $tool): bool
    {
        return true;
    }
}
