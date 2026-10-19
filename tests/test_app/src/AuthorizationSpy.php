<?php
declare(strict_types=1);

namespace TestApp;

/**
 * Stands in for cakephp/authorization's service on the request, recording
 * whether the MCP controller skipped the host's authorization check.
 */
final class AuthorizationSpy
{
    public static bool $skipped = false;

    /**
     * @return void
     */
    public function skipAuthorization(): void
    {
        self::$skipped = true;
    }
}
