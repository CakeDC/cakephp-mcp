<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;

/**
 * Keeps the status of an authentication challenge that is not a 401.
 *
 * PHP sets the status to 401 whenever a `WWW-Authenticate` header is sent, unless the status is set again
 * afterwards. Cake's ResponseEmitter sends the status line before the headers, so a `403 insufficient_scope`
 * with its Bearer challenge reaches the client as 401. keep() sets the status once more right before PHP
 * sends the headers, with header_register_callback() (one callback per request: it replaces one an
 * application registered earlier in the same request).
 */
final class ChallengeStatus
{
    /**
     * Replaces header_register_callback() in tests.
     *
     * @internal
     * @var \Closure|null
     */
    public static ?Closure $register = null;

    /**
     * @param \Psr\Http\Message\ResponseInterface $response The response about to be emitted.
     * @return void
     */
    public static function keep(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status === 401 || !$response->hasHeader('WWW-Authenticate')) {
            return;
        }
        $callback = static function () use ($status): void {
            http_response_code($status);
        };
        if (self::$register !== null) {
            (self::$register)($callback);

            return;
        }
        header_register_callback($callback);
    }
}
