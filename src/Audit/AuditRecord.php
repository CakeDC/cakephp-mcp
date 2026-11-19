<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Audit;

/**
 * One audited MCP call: who made it, what it asked for and how it ended.
 */
final class AuditRecord
{
    public const OK = 'ok';

    public const TOOL_ERROR = 'tool_error';

    public const DENIED = 'denied';

    public const INVALID = 'invalid';

    public const ERROR = 'error';

    public const UNAUTHORIZED = 'unauthorized';

    public const INSUFFICIENT_SCOPE = 'insufficient_scope';

    /**
     * Longest name or request id kept, in characters (both come from the client).
     */
    public const MAX_TEXT = 255;

    /**
     * @param string $method JSON-RPC method, such as `tools/call`.
     * @param string|null $name Tool or prompt name, or the resource URI.
     * @param string $outcome One of the outcome constants.
     * @param string|int|null $identity Identifier of the caller, null when there is none.
     * @param string|null $client OAuth client of the access token.
     * @param string|null $session MCP session id.
     * @param string|int|null $request JSON-RPC request id.
     * @param array<mixed> $arguments Arguments, already redacted and truncated.
     * @param string|null $error Error message, for the outcomes that are not `ok`.
     * @param int|null $durationMs Duration, null when it is not known.
     */
    public function __construct(
        public readonly string $method,
        public readonly ?string $name,
        public readonly string $outcome,
        public readonly string|int|null $identity,
        public readonly ?string $client,
        public readonly ?string $session,
        public readonly string|int|null $request,
        public readonly array $arguments,
        public readonly ?string $error,
        public readonly ?int $durationMs,
    ) {
    }

    /**
     * $value cut to MAX_TEXT characters; integers and null as they are.
     *
     * @param string|int|null $value A name or request id from the client.
     * @return string|int|null
     */
    public static function cap(string|int|null $value): string|int|null
    {
        return is_string($value) ? mb_substr($value, 0, self::MAX_TEXT) : $value;
    }

    /**
     * @return array{method: string, name: string|null, outcome: string, identity: string|int|null, client: string|null, session: string|null, request: string|int|null, arguments: array<mixed>, error: string|null, durationMs: int|null}
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'name' => $this->name,
            'outcome' => $this->outcome,
            'identity' => $this->identity,
            'client' => $this->client,
            'session' => $this->session,
            'request' => $this->request,
            'arguments' => $this->arguments,
            'error' => $this->error,
            'durationMs' => $this->durationMs,
        ];
    }
}
