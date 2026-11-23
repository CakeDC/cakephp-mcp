<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use ArrayAccess;
use Cake\Datasource\EntityInterface;
use InvalidArgumentException;

/**
 * The key a user's connections are stored under: an id given as is, an identity's `getIdentifier()`, or the `id` of
 * an entity or array.
 */
final class UserId
{
    /**
     * @param mixed $user A user id, an identity, a user entity or a user array.
     * @return string
     * @throws \InvalidArgumentException When no id can be read.
     */
    public static function of(mixed $user): string
    {
        $id = match (true) {
            is_int($user), is_string($user) => $user,
            is_object($user) && method_exists($user, 'getIdentifier') => $user->getIdentifier(),
            $user instanceof EntityInterface => $user->get('id'),
            is_array($user), $user instanceof ArrayAccess => $user['id'] ?? null,
            default => null,
        };
        if ((!is_int($id) && !is_string($id)) || $id === '') {
            throw new InvalidArgumentException(
                'The MCP client needs the user: pass an id, an identity, or a user entity or array with an "id".',
            );
        }

        return (string)$id;
    }
}
