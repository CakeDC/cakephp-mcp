# Troubleshooting

## The tool list is empty

- A class in a scan directory fails to load (a syntax error, a missing
  parent class or interface). The SDK silently skips discovery and returns
  nothing, so run `php -l` on the files in `src/Mcp/` and load the classes from
  a script or test to see the real error.
- `Mcp.discovery.scanDirs` points to the wrong place. Paths are relative to
  `ROOT`, and a directory that does not exist is ignored.
- The discovery cache is stale. Outside debug mode run
  `bin/cake cache clear _cake_mcp_` after every deploy of new tools.

## 403 Invalid Host header

The `Host` of the request is not allowed. By default the host of
`App.fullBaseUrl` and localhost are. Make sure `App.fullBaseUrl` is set
correctly in production, or list the hosts yourself:

```php
Configure::write('Mcp.http.allowedHosts', ['example.com', 'localhost']);
```

## 403 from CSRF

Cake's CSRF middleware is rejecting the POST. Add the `skipCheckCallback` shown
in [Installation](installation.md), and make sure it matches your
`Mcp.route`.

## 401 on every request

The authenticator is not reading the header. Check that the client sends
`Authorization: Bearer <token>`, that `tokenPrefix` matches (`Bearer`), and
that your web server passes the `Authorization` header to PHP (Apache needs
`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` in some setups). Also
confirm the `AuthenticationMiddleware` runs before the request reaches the MCP
route.

## AuthorizationRequiredException from cakephp/authorization

This should not happen: the MCP controller marks the request as authorized
because authorization is done per tool. If you see it, check that you run the
latest version of this plugin and that no other middleware calls the
authorization service on the MCP route.

## Server-sent events stall behind nginx

The plugin sends `X-Accel-Buffering: no` on event streams so nginx does not
buffer them. If a stream still stalls, a proxy or CDN in front is ignoring the
header. Disable response buffering for the MCP path there.

## The session is lost between requests

With the 2025 handshake protocol, the `Mcp-Session-Id` returned by
`initialize` must work on the next request, which may be handled by another PHP
worker. The `mcp_sessions` cache therefore has to be shared by all workers. Do
not point it at the Array engine. The default file cache works on one server;
use Redis or Memcached when you run several.

## A tool failed and the client only says "Error while executing tool"

That is intentional: exception messages are not sent to clients. Check your
logs (the `mcp` scope), or throw `Mcp\Exception\ToolCallException` when you
want the client to see a message. See [Writing tools](tools.md).

## HTTP 500 with "Internal error"

Something failed outside the SDK, for example the server could not be built
because the configuration is invalid. The response is always the JSON body
`{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}`,
never an HTML page, even in debug mode. The full error is in your log under the
`mcp` scope.
