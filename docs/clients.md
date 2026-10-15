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

The Inspector is a small web app for trying a server by hand:

```bash
npx @modelcontextprotocol/inspector
```

Choose the Streamable HTTP transport, enter the URL and add an
`Authorization: Bearer <token>` header. You can then list tools, call them and
see the raw JSON-RPC messages, which helps when a client says only that the
connection failed.
