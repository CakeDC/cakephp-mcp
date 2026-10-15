# Configuration

All settings live under the `Mcp` key in Configure. You only write what you
want to change; everything else keeps its default. These are the defaults
(`CakeDC\Mcp\McpConfig::DEFAULTS`):

```php
'Mcp' => [
    'route' => '/mcp',
    'server' => [
        'name' => 'CakePHP MCP Server',
        'version' => '1.0.0',
        'title' => null,
        'description' => null,
        'websiteUrl' => null,
        'instructions' => null,
        'configure' => null,
    ],
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
- `server.title`, `server.description`, `server.websiteUrl`: optional, also reported to clients. `null` sends none.
- `server.instructions`: optional text telling the client's model how to use your server. `null` sends none.
- `server.configure`: optional callable that receives the SDK `Mcp\Server\Builder` before the server is built. See [Using the SDK builder](#using-the-sdk-builder).
- `discovery.scanDirs`: directories (relative to `ROOT`) scanned for attributes. Missing directories are skipped.
- `discovery.excludeDirs`: directories to skip while scanning.
- `discovery.cache`: name of the cache config for the discovery result. Set it to `null` or an empty string to disable the cache.
- `session.cache`: name of the cache config that stores handshake-era sessions.
- `session.ttl`: session lifetime in seconds.
- `http.allowedHosts`: hosts accepted in the `Host` header (DNS-rebinding protection). `null` means the host of `App.fullBaseUrl` plus `localhost`, `127.0.0.1` and `[::1]`, which only protects when `App.fullBaseUrl` is set explicitly (the Cake 5 skeleton derives it from the request `Host` otherwise, and then every host is accepted). A list replaces that. Set it explicitly in production; it is required for servers with `identity.required` set to `false`.
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

## Using the SDK builder

`server.configure` gives you the SDK builder, for what the plugin does not
configure itself: tools registered in code instead of with attributes, resource
templates, a cache policy, request state for multi round-trip tools:

```php
use Mcp\Server\Builder;

Configure::write('Mcp.server.configure', function (Builder $builder): void {
    $builder->addTool(fn (): string => date('c'), name: 'server_time', description: 'Current server time.');
});
```

The plugin applies its own settings after the callable, so these always win:
the server info (a `setServerInfo()` call in the callable is ignored; use the
`server.*` keys), container, logger, event dispatcher, session store, and
discovery when `discovery.scanDirs` has a directory. The authorizer applies to
every tool, including the ones added here.

If you set a cache policy, keep `tools/list` private unless every caller sees
the same tools. With an authorizer, the list depends on who asks, and a
`CacheScope::Public` hint lets shared caches serve one user's list to others.

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
use Cake\Log\Engine\FileLog;

'Log' => [
    'mcp' => [
        'className' => FileLog::class,
        'path' => LOGS,
        'file' => 'mcp',
        'scopes' => ['mcp'],
    ],
],
```

Engines without `scopes` (the default `[]`) catch every scope, so the default
`debug` and `error` engines of the skeleton also receive the `mcp` messages. To
keep them out, give those engines `'scopes' => null`: an engine with `null`
scopes only handles messages that have no scope.
