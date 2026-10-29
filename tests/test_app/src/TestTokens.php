<?php
declare(strict_types=1);

namespace TestApp;

use ArrayObject;

/**
 * Bearer tokens the test app accepts, and the identity each one resolves to.
 */
final class TestTokens
{
    public const EDITOR = 'editor-token';
    public const ADMIN = 'admin-token';

    /**
     * @param string|null $token The raw Bearer token.
     * @return \ArrayObject<string, mixed>|null
     */
    public static function identity(?string $token): ?ArrayObject
    {
        return match ($token) {
            self::EDITOR => new ArrayObject(['id' => 1, 'role' => 'editor', 'name' => 'Ed']),
            self::ADMIN => new ArrayObject(['id' => 2, 'role' => 'admin', 'name' => 'Ada']),
            default => null,
        };
    }
}
