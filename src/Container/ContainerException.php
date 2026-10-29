<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

final class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
