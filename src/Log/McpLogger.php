<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Log;

use Cake\Log\Log;
use Psr\Log\AbstractLogger;
use Stringable;

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
        Log::write(is_string($level) ? $level : 'info', $message, $context);
    }
}
