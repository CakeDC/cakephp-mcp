<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\OAuth;

use CakeDC\Mcp\Client\Exception\ClientException;

/**
 * A sign-in step that failed. The message is the plugin's own, never what the server said; `$error` is the OAuth
 * error code when there is one (`invalid_grant`, `invalid_client`, `access_denied`...), reduced to safe characters.
 */
class OAuthException extends ClientException
{
    /**
     * @param string $message The message.
     * @param string|null $error The OAuth error code.
     */
    public function __construct(string $message, public readonly ?string $error = null)
    {
        parent::__construct($message);
    }

    /**
     * @param mixed $value An `error` field from a server.
     * @return string|null It, when it is a plausible OAuth error code (lowercase letters and underscores).
     */
    public static function code(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z_]{1,64}$/', $value) === 1 ? $value : null;
    }
}
