<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Attribute;

use Attribute;
use Cake\Core\Configure;
use InvalidArgumentException;

/**
 * Registers a tool, resource, resource template or prompt only when a condition holds: a config value is on
 * (`config: 'Features.reports'`) or a static callback returns true (`callback: [Flags::class, 'reports']`,
 * called with the server name). Read at every server build, so a change takes effect on the next request.
 *
 * It is for configuration and feature flags. What depends on who is calling belongs in the authorizer.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class McpWhen
{
    /**
     * @param string|null $config A Configure path: the element is registered when its value is truthy. The strings
     *   "false", "0", "off", "no" and "" (any case, around spaces) are off, as `env()` values are strings.
     * @param array{0: class-string, 1: string}|null $callback A static method: registered when it returns true.
     * @throws \InvalidArgumentException When neither or both are given.
     */
    public function __construct(
        public readonly ?string $config = null,
        public readonly ?array $callback = null,
    ) {
        if (($config === null) === ($callback === null)) {
            throw new InvalidArgumentException('#[McpWhen] needs either config or callback, not both or neither.');
        }
    }

    /**
     * @param string $server The MCP server being built.
     * @return bool Whether the element is registered on $server.
     * @throws \InvalidArgumentException When the callback cannot be called.
     */
    public function allows(string $server): bool
    {
        if ($this->config !== null) {
            $value = Configure::read($this->config);
            // An env() value is a string: "false", "off" or "no" in a .env file means off.
            if (is_string($value)) {
                return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
            }

            return (bool)$value;
        }
        if (!is_callable($this->callback)) {
            throw new InvalidArgumentException(sprintf(
                '#[McpWhen] callback %s is not callable: give [Class::class, \'staticMethod\'].',
                json_encode($this->callback),
            ));
        }

        return (bool)call_user_func($this->callback, $server);
    }

    /**
     * @return string Why the element is not registered, for `bin/cake mcp check`.
     */
    public function describe(): string
    {
        if ($this->config !== null) {
            return sprintf('%s is off', $this->config);
        }

        return sprintf('%s::%s() returned false', ...array_map('strval', (array)$this->callback));
    }
}
