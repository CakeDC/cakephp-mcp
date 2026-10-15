# stdio

For MCP clients that run on the same machine as your project, the plugin
provides a command that speaks MCP over standard input and output:

```bash
bin/cake mcp server
```

## Claude Code

```bash
claude mcp add my-app -- bin/cake mcp server
```

Run it from the project directory, or give the full path to `bin/cake`. With
DDEV or Docker, wrap the command, for example
`claude mcp add my-app -- ddev exec bin/cake mcp server`.

## Identity

There is no HTTP request, so there is no authentication. By default tools see
no identity. To run tools as a particular user, give `Mcp.stdio.identity` a
closure that returns the identity:

```php
Configure::write('Mcp.stdio.identity', function (): ArrayObject {
    return new ArrayObject(['id' => 1, 'role' => 'admin']);
});
```

`Mcp.stdio.identity` must be a callable. A plain array or `ArrayObject` there is
silently ignored (the command only calls it when it is callable), so tools
would see no identity.

Return whatever shape your tools and authorizer expect (see
[Authentication](authentication.md)). The closure runs once when the command
starts.

## Trusted mode

`Mcp.stdio.trusted` defaults to `true`. A trusted stdio session skips
per-tool authorization: the developer who starts the command already has
access to the code and the database. Set it to `false` if you want the
configured authorizer applied to the identity from `stdio.identity`.

Only authorization is skipped. Everything else, including tool discovery and
dependency injection, works as over HTTP.

## Keep STDOUT clean

The protocol uses STDOUT, so anything else written there corrupts the
conversation and the client disconnects.

- Tools must never `echo`, `print` or `var_dump`.
- No Log engine may write to `php://stdout`. Cake's `ConsoleLog` writes to
  stderr by default, which is fine. If you configured a console log with
  `'stream' => 'php://stdout'`, change it or scope it away from the `mcp`
  scope for this command.
- Make sure PHP notices and deprecations are not printed to STDOUT
  (`display_errors` off for the CLI, or sent to stderr).
