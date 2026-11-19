<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Log;

use Cake\Log\Log;
use Psr\Log\AbstractLogger;
use Stringable;
use Throwable;

/**
 * The PSR-3 logger handed to the MCP SDK: forwards to Cake's `Log` under the
 * `mcp` scope, so a host can route MCP logs with a scoped log config.
 */
final class McpLogger extends AbstractLogger
{
    public const SCOPE = 'mcp';

    /**
     * @param mixed $level PSR-3 level.
     * @param \Stringable|string $message The message.
     * @param array<mixed> $context Context; `scope` is extended with `mcp`.
     * @return void
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $context['scope'] = array_merge((array)($context['scope'] ?? []), [self::SCOPE]);
        $message = (string)$message;

        // Cake log engines only interpolate {placeholders}, so anything else in the
        // context (the SDK passes the exception and the tool name) would be lost.
        $extra = [];
        foreach ($context as $key => $value) {
            if ($key === 'scope' || str_contains($message, '{' . $key . '}')) {
                continue;
            }
            if ($value instanceof Throwable) {
                $extra[] = "\n" . (string)$value;
            } elseif (is_scalar($value) || $value instanceof Stringable) {
                $extra[] = ' ' . $key . '=' . $value;
            }
        }
        // Throwables go last so the one-line key=value pairs stay next to the message.
        usort($extra, fn(string $a, string $b): int => ($a[0] === "\n") <=> ($b[0] === "\n"));

        Log::write(is_string($level) ? $level : 'info', $message . implode('', $extra), $context);
    }
}
