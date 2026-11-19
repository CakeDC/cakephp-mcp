<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Identity;

use ArrayAccess;

/**
 * The host identity of the MCP call being served. Shared in the container, so a
 * tool can inject it to know who is calling.
 */
final class CurrentIdentity
{
    private mixed $identity = null;

    /**
     * @param mixed $identity The identity, or null.
     * @return void
     */
    public function set(mixed $identity): void
    {
        $this->identity = $identity;
    }

    /**
     * @return mixed
     */
    public function get(): mixed
    {
        return $this->identity;
    }

    /**
     * @return bool
     */
    public function has(): bool
    {
        return $this->identity !== null;
    }

    /**
     * @return string|int|null
     */
    public function getIdentifier(): string|int|null
    {
        return self::identifierOf($this->identity);
    }

    /**
     * The identifier of any identity shape: objects with `getIdentifier()`
     * (cakephp/authentication), then arrays and `ArrayAccess` (entities,
     * `ArrayObject`) through their `id`.
     *
     * @param mixed $identity The identity.
     * @return string|int|null
     */
    public static function identifierOf(mixed $identity): string|int|null
    {
        $id = null;
        if (is_object($identity) && method_exists($identity, 'getIdentifier')) {
            $id = $identity->getIdentifier();
        } elseif (is_array($identity) || $identity instanceof ArrayAccess) {
            $id = $identity['id'] ?? null;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }
}
