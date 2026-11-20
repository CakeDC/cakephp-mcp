<?php
declare(strict_types=1);

/**
 * A small MCP server of the SDK on stdio, for the client's stdio tests: `echo`, and `env`, which answers the value of
 * an environment variable of this process ("unset" when it has none).
 */

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$server = Server::builder()
    ->setServerInfo('stdio-fake', '1.0.0')
    ->addTool(static fn(string $text): string => 'echo: ' . $text, 'echo', null, 'Echoes the text.')
    ->addTool(
        static fn(string $name): string => getenv($name) === false ? 'unset' : (string)getenv($name),
        'env',
        null,
        'Reads an environment variable.',
    )
    ->build();

$server->run(new StdioTransport());
