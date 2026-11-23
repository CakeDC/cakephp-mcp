<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

use Cake\Log\Log;
use CakeDC\Mcp\Log\McpLogger;
use Throwable;

/**
 * The cause of a request that failed in the HTTP layer (TLS, DNS, a refused connection), logged at debug level.
 *
 * The client's exception only names the host: the cause's message may quote the URL, whose query or user info can
 * carry a secret. Here every URL in it is cut down to its scheme and host.
 *
 * @internal
 */
final class HttpFailure
{
    /**
     * @param \Throwable $cause What the HTTP layer threw.
     * @param string $host The host the request was for.
     * @return void
     */
    public static function log(Throwable $cause, string $host): void
    {
        Log::debug(
            sprintf(
                'MCP client: the request to %s failed in the HTTP layer: %s (code %s): %s',
                $host,
                $cause::class,
                (string)$cause->getCode(),
                self::redact($cause->getMessage()),
            ),
            ['scope' => [McpLogger::SCOPE]],
        );
    }

    /**
     * @param string $message A message that may quote URLs.
     * @return string The message with each URL reduced to scheme and host, at most 500 bytes.
     */
    public static function redact(string $message): string
    {
        $redacted = (string)preg_replace(
            '~\b([a-z][a-z0-9+.-]*://)(?:[^\s/?#@]*@)?([^\s/?#@\'"<>]+)[^\s\'"<>]*~i',
            '$1$2/...',
            $message,
        );

        return substr($redacted, 0, 500);
    }
}
