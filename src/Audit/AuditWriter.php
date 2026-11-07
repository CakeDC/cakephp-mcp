<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Audit;

use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\Log\Log;
use CakeDC\Mcp\Log\McpLogger;
use Throwable;

/**
 * Writes audit records: one log line under the `mcp_audit` scope (plus `mcp`)
 * and the `Mcp.audit` Cake event. Never throws: the answer to the client must
 * not depend on the record.
 */
final class AuditWriter
{
    public const SCOPE = 'mcp_audit';

    public const EVENT = 'Mcp.audit';

    /**
     * Largest JSON of the recorded arguments, in bytes.
     */
    public const MAX_ARGUMENTS_BYTES = 2048;

    /**
     * Arrays nested deeper than this are replaced by `[...]`.
     */
    private const MAX_DEPTH = 9;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    private const LEVELS = [
        AuditRecord::OK => 'info',
        AuditRecord::TOOL_ERROR => 'notice',
        AuditRecord::INVALID => 'notice',
        AuditRecord::DENIED => 'warning',
        AuditRecord::ERROR => 'warning',
        AuditRecord::UNAUTHORIZED => 'warning',
        AuditRecord::INSUFFICIENT_SCOPE => 'warning',
    ];

    /**
     * @param \CakeDC\Mcp\Audit\AuditSettings $settings The audit settings.
     */
    public function __construct(private readonly AuditSettings $settings)
    {
    }

    /**
     * The arguments as they go in a record: redacted, long strings cut, deep
     * arrays cut, and replaced by `{"truncated": true}` when still too large.
     * Empty when arguments are not recorded or are not a JSON object.
     *
     * @param mixed $arguments The call arguments.
     * @return array<mixed>
     */
    public function arguments(mixed $arguments): array
    {
        if (!$this->settings->arguments || !is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return [];
        }
        $clean = $this->clean($arguments, 1);
        if (!is_array($clean)) {
            return [];
        }
        if (strlen((string)json_encode($clean, self::JSON_FLAGS)) > self::MAX_ARGUMENTS_BYTES) {
            return ['truncated' => true];
        }

        return $clean;
    }

    /**
     * Writes the log line (unless `log` is false) and dispatches `Mcp.audit`.
     *
     * @param \CakeDC\Mcp\Audit\AuditRecord $record The record.
     * @return void
     */
    public function write(AuditRecord $record): void
    {
        if ($this->settings->log) {
            try {
                // The line is the value of one placeholder: Cake replaces it in a single pass, so `{...}` in
                // client text is never interpolated.
                (new McpLogger())->log(
                    self::LEVELS[$record->outcome] ?? 'warning',
                    '{audit}',
                    ['audit' => $this->line($record), 'scope' => [self::SCOPE]],
                );
            } catch (Throwable $exception) {
                $this->report('could not write the log line', $exception);
            }
        }
        try {
            EventManager::instance()->dispatch(new Event(self::EVENT, null, $record->toArray()));
        } catch (Throwable $exception) {
            $this->report('a listener of the Mcp.audit event failed', $exception);
        }
    }

    /**
     * @param \CakeDC\Mcp\Audit\AuditRecord $record The record.
     * @return string
     */
    private function line(AuditRecord $record): string
    {
        $line = sprintf(
            'MCP %s %s %s %sms identity=%s client=%s session=%s request=%s',
            $this->field($record->method),
            $this->field($record->name),
            $this->field($record->outcome),
            $record->durationMs ?? '-',
            $this->field($record->identity),
            $this->field($record->client),
            $this->field($record->session),
            $this->field($record->request),
        );
        if ($this->settings->arguments) {
            $line .= ' args=' . json_encode((object)$record->arguments, self::JSON_FLAGS);
        }
        if ($record->error !== null) {
            $line .= ' error=' . json_encode($record->error, self::JSON_FLAGS);
        }

        return $line;
    }

    /**
     * A value of the line: as is when it is a plain token, else a JSON string, so client text
     * (a tool name, a request id) cannot add spaces, quotes or line breaks to the line.
     *
     * @param string|int|null $value The value.
     * @return string
     */
    private function field(string|int|null $value): string
    {
        if ($value === null) {
            return '-';
        }
        $value = (string)$value;

        if (preg_match('~^[A-Za-z0-9_.:/@-]+$~D', $value) === 1) {
            return $value;
        }

        return (string)json_encode($value, self::JSON_FLAGS);
    }

    /**
     * @param mixed $value A value of the arguments.
     * @param int $depth Depth of $value, 1 for the arguments themselves.
     * @return mixed
     */
    private function clean(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > $this->settings->maxLength
                ? mb_substr($value, 0, $this->settings->maxLength) . '...'
                : $value;
        }
        if (is_object($value)) {
            return $this->clean(get_object_vars($value), $depth);
        }
        if (!is_array($value)) {
            return $value;
        }
        if ($depth > self::MAX_DEPTH) {
            return '[...]';
        }
        $clean = [];
        foreach ($value as $key => $item) {
            $clean[$key] = is_string($key) && in_array(strtolower($key), $this->settings->redact, true)
                ? '[redacted]'
                : $this->clean($item, $depth + 1);
        }

        return $clean;
    }

    /**
     * Logs a failure of the audit itself under the `mcp` scope, quietly.
     *
     * @param string $what What failed.
     * @param \Throwable $exception The failure.
     * @return void
     */
    private function report(string $what, Throwable $exception): void
    {
        try {
            Log::error(sprintf('MCP audit: %s: %s', $what, $exception->getMessage()), ['scope' => [McpLogger::SCOPE]]);
        } catch (Throwable) {
            // Nothing left to tell: the call goes on.
        }
    }
}
