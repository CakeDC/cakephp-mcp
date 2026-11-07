<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Audit;

use CakeDC\Mcp\McpConfig;
use InvalidArgumentException;

/**
 * The `Mcp.audit` settings, validated strictly: an unknown key or a wrong type
 * stops the server with a message that names the key.
 */
final class AuditSettings
{
    /**
     * Methods that can be audited.
     *
     * @var list<string>
     */
    public const METHODS = ['tools/call', 'prompts/get', 'resources/read'];

    /**
     * @param bool $enabled Whether calls are recorded.
     * @param list<string> $methods Audited JSON-RPC methods.
     * @param bool $log Write the log line (the `Mcp.audit` event fires either way).
     * @param bool $arguments Record the call arguments.
     * @param list<string> $redact Argument names whose value is hidden, lower case.
     * @param int $maxLength Longest string value kept, in characters.
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly array $methods,
        public readonly bool $log,
        public readonly bool $arguments,
        public readonly array $redact,
        public readonly int $maxLength,
    ) {
    }

    /**
     * Reads and validates `Mcp.audit`.
     *
     * @return self
     * @throws \InvalidArgumentException For an invalid setting, named in the message.
     */
    public static function fromConfig(): self
    {
        $audit = McpConfig::get('audit');
        if (!is_array($audit)) {
            throw new InvalidArgumentException('"Mcp.audit" must be an array.');
        }
        foreach (array_keys($audit) as $key) {
            if (!array_key_exists($key, McpConfig::DEFAULTS['audit'])) {
                throw new InvalidArgumentException(sprintf('"Mcp.audit.%s" is not a setting.', $key));
            }
        }

        $methods = $audit['methods'];
        if (!is_array($methods) || !array_is_list($methods) || array_diff($methods, self::METHODS) !== []) {
            throw new InvalidArgumentException(sprintf(
                '"Mcp.audit.methods" must be a list of %s.',
                implode(', ', self::METHODS),
            ));
        }
        $redact = $audit['redact'];
        if (!is_array($redact) || !array_is_list($redact) || array_filter($redact, 'is_string') !== $redact) {
            throw new InvalidArgumentException('"Mcp.audit.redact" must be a list of argument names.');
        }
        $maxLength = $audit['maxLength'];
        if (!is_int($maxLength) || $maxLength < 1) {
            throw new InvalidArgumentException('"Mcp.audit.maxLength" must be a positive integer.');
        }

        /** @var list<string> $methods */
        /** @var list<string> $redact */
        return new self(
            self::flag($audit['enabled'], 'enabled'),
            $methods,
            self::flag($audit['log'], 'log'),
            self::flag($audit['arguments'], 'arguments'),
            array_map('strtolower', $redact),
            $maxLength,
        );
    }

    /**
     * @param string $method A JSON-RPC method.
     * @return bool Whether calls of $method are recorded.
     */
    public function audits(string $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    /**
     * @param mixed $value The setting.
     * @param string $key Its key inside `Mcp.audit`.
     * @return bool
     */
    private static function flag(mixed $value, string $key): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('"Mcp.audit.%s" must be true or false.', $key));
        }

        return $value;
    }
}
