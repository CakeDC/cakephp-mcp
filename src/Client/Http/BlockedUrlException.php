<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

use CakeDC\Mcp\Client\Exception\ClientException;

/**
 * A URL learned from a remote server that the client does not call: not public https, a private address, or a
 * redirect it does not follow. Nothing was sent to it.
 */
class BlockedUrlException extends ClientException
{
}
