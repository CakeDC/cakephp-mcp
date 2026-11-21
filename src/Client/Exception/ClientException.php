<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Exception;

use RuntimeException;

/**
 * A call through an MCP client that could not be made. Messages never carry a token, a secret or what a remote
 * server said in an error.
 */
class ClientException extends RuntimeException
{
}
