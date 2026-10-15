# Connecting clients

## Claude Code over HTTP

```bash
claude mcp add --transport http my-app https://example.com/mcp \
  --header "Authorization: Bearer <token>"
```

Check that it connected with `claude mcp list`, or `/mcp` inside Claude Code.
The server must be reachable from your machine, and its host must be allowed
(`http.allowedHosts`; by default the host of `App.fullBaseUrl`, which only restricts anything when it is set explicitly, so set `http.allowedHosts` for a real deployment).

For a server running on your own machine you can use the local URL, for
example `https://my-app.ddev.site/mcp`.

## Claude Code over stdio

```bash
claude mcp add my-app -- bin/cake mcp server
```

See [stdio](stdio.md).

## claude.ai

In claude.ai go to Settings, Connectors, Add custom connector, and enter the
URL of your endpoint. Where your account allows custom request headers you can
send the Bearer token there. Connectors that sign the user in through OAuth
need the OAuth support planned for 2.1.

## MCP Inspector

The Inspector is a small web app for trying a server by hand: list the tools,
call them and see the raw JSON-RPC messages, which helps when a client says
only that the connection failed. It needs Node.js 22.19 or later.
`bin/cake mcp inspector` runs version 2 of the Inspector (`@modelcontextprotocol/inspector@2`).

`bin/cake mcp inspector` opens it already pointed at your server:

```bash
bin/cake mcp inspector                       # starts `bin/cake mcp server` over stdio
bin/cake mcp inspector --http --header "Authorization: Bearer <token>"
bin/cake mcp inspector --url https://staging.example.com/mcp --header "Authorization: Bearer <token>"
```

- Without options it uses stdio and starts `bin/cake mcp server` (with
  `stdio.identity` and `stdio.trusted`). The Inspector starts it with only a
  few environment variables (`PATH`, `HOME`, `USER` and similar), so if your
  app reads its settings from the environment, pass them by name with
  `--env DATABASE_URL --env DEBUG`. Apps configured through `config/.env` or
  `app_local.php` need nothing.
- `--http` uses the endpoint at `App.fullBaseUrl` plus `Mcp.route`. If
  `App.fullBaseUrl` is not set in the console, pass the URL with `--url`.
- `--header` adds a request header, as `"Name: Value"`. Repeat it for more.
- `--dry-run` prints the Inspector configuration and command instead of
  starting it. Header and environment values are shown as `***`.

The configuration goes to a temporary file only you can read, and it is
deleted when the Inspector stops, also when you stop it with Ctrl+C. On Linux
and macOS that needs PHP's `pcntl` extension, usual in the CLI; without it the
command warns and prints the path to delete. If the PHP process is killed
outright (for example `kill -9`), remove `mcp-inspector-*.json` from the
temporary directory yourself. If the token is wrong, the endpoint answers
401 and the Inspector offers an OAuth sign-in, which this version does not
support: check the token instead.

You can also start the Inspector yourself with
`npx @modelcontextprotocol/inspector`, choose the Streamable HTTP transport,
enter the URL and add the `Authorization: Bearer <token>` header.
