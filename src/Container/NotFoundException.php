<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
