# Configuration

All settings live under the `Mcp` key in Configure. You only write what you
want to change; everything else keeps its default. These are the defaults
(`CakeDC\Mcp\McpConfig::DEFAULTS`):

```php
'Mcp' => [
    'route' => '/mcp',
    'server' => ['name' => 'CakePHP MCP Server', 'version' => '1.0.0', 'instructions' => null],
    'discovery' => ['scanDirs' => ['src/Mcp'], 'excludeDirs' => [], 'cache' => '_cake_mcp_'],
    'session' => ['cache' => 'mcp_sessions', 'ttl' => 3600],
    'http' => ['allowedHosts' => null, 'allowedOrigins' => []],
    'identity' => ['required' => true, 'attribute' => 'identity', 'scopes' => null],
    'authorization' => ['authorizer' => AllowAllToolAuthorizer::class, 'rbac' => []],
    'stdio' => ['identity' => null, 'trusted' => true],
],
```

## Keys

- `route`: path of the HTTP endpoint. `false` (or an empty string) connects no route.
- `server.name`, `server.version`: reported to clients in the `initialize` result.
- `server.instructions`: optional text telling the client's model how to use your server. `null` sends none.
- `discovery.scanDirs`: directories (relative to `ROOT`) scanned for attributes. Missing directories are skipped.
- `discovery.excludeDirs`: directories to skip while scanning.
- `discovery.cache`: name of the cache config for the discovery result. Set it to `null` or an empty string to disable the cache.
- `session.cache`: name of the cache config that stores handshake-era sessions.
- `session.ttl`: session lifetime in seconds.
- `http.allowedHosts`: hosts accepted in the `Host` header (DNS-rebinding protection). `null` means the host of `App.fullBaseUrl` plus `localhost`, `127.0.0.1` and `[::1]`. A list replaces that.
- `http.allowedOrigins`: origins accepted for browser clients, also used for CORS. Empty by default.
- `identity.required`: answer 401 when the request has no identity.
- `identity.attribute`: request attribute that holds the identity.
- `identity.scopes`: optional `fn (mixed $identity): array` returning scope strings for the SDK access token.
- `authorization.authorizer`: class name or instance of a `ToolAuthorizerInterface`.
- `authorization.rbac`: options for `RbacToolAuthorizer` (the `cakedc/auth` Rbac config).
- `stdio.identity`: closure returning the identity for `bin/cake mcp server`.
- `stdio.trusted`: skip per-tool authorization for stdio.

## Lists replace defaults

Associative arrays merge with the defaults, but a list replaces the default
list. So this keeps `excludeDirs` and `cache` as they are and only swaps the
scan directories:

```php
Configure::write('Mcp.discovery.scanDirs', ['src/Mcp', 'plugins/Shop/src/Mcp']);
```

The default `src/Mcp` is not scanned any more unless you list it again.

## Caches

If the cache configs named in `discovery.cache` and `session.cache` do not
exist, the plugin creates file caches for them under `CACHE/mcp/` (one hour
default duration). Define your own configs with the same names (for example on
Redis) and the plugin leaves them alone.

The discovery cache is always off in debug mode. The session cache must be
shared by every PHP worker; see [Troubleshooting](troubleshooting.md).

## Logging

The plugin logs through Cake's `Log` with the `mcp` scope. Route those messages
to their own file with a scoped log config:

```php
'Log' => [
    'mcp' => [
        'className' => FileLog::class,
        'path' => LOGS,
        'file' => 'mcp',
        'scopes' => ['mcp'],
    ],
],
```
