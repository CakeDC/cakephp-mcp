<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Exception;

/**
 * The user has no usable grant for a user mode client: never connected, disconnected, or the server no longer
 * accepts the grant. Send them to `connectUrl()`.
 */
class NotConnectedException extends ClientException
{
}
