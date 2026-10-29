<?php
declare(strict_types=1);

namespace CakeDC\Mcp\OAuth;

use InvalidArgumentException;
use Psr\Cache\InvalidArgumentException as CacheInvalidArgumentException;

/**
 * A cache key PSR-6 does not allow.
 */
final class CacheKeyException extends InvalidArgumentException implements CacheInvalidArgumentException
{
}
