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
    'auth' => [
        'mode' => 'host',
        'resource' => null,
        'resourceName' => null,
        'resourceDocumentation' => null,
        'scopes' => ['default' => [], 'methods' => [], 'tools' => [], 'implies' => []],
        'identity' => null,
        'external' => [
            'issuer' => null,
            'audience' => null,
            'algorithms' => ['RS256'],
            'scopeClaim' => 'scope',
            'tokenType' => null,
            'leeway' => 0,
            'cache' => 'mcp_oauth',
            'httpClient' => null,
        ],
    ],
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
- `auth`: how the endpoint authenticates remote clients. See [Mcp.auth](#mcpauth).
- `stdio.identity`: closure returning the identity for `bin/cake mcp server`.
- `stdio.trusted`: skip per-tool authorization for stdio.

## Mcp.auth

`Mcp.auth` chooses between your own authentication (`host`, the default) and
OAuth 2.1 access tokens (`oauth` and `external`). The whole story, with
examples, is in [OAuth](oauth.md). Unlike the rest of `Mcp`, this section is
validated strictly: an unknown key, a wrong type or a scope that the resource
does not declare makes the endpoint answer `500 Internal error` and logs the
setting that is wrong (read the `mcp` log scope, or run `bin/cake mcp check`).
Tools stay unreachable until it is fixed; a typo in a security setting never
opens anything.

- `auth.mode`: `host` (default, the 2.0 behaviour: your authentication puts the identity in the request), `oauth` (tokens of `cakedc/cakephp-oauth-server` in the same application) or `external` (JWT access tokens of a third-party authorization server).
- `auth.resource`: the canonical URL of the MCP endpoint, which is the audience of its tokens. `null` derives it from `App.fullBaseUrl`, `App.base` and `Mcp.route`; on a subdirectory install set `App.base` or this key, because a base path Cake detects by itself (`App.base` `false`) is not seen. Set it when the application sits behind a proxy. Only `https` is accepted (plain `http` only for localhost), without fragment or credentials. Not used in `host` mode.
- `auth.resourceName`: optional name shown in the protected resource metadata.
- `auth.resourceDocumentation`: optional documentation URL shown in the protected resource metadata.
- `auth.scopes`: the scope policy, as below. Every scope named here must be declared by the resource in `oauth` mode.
- `auth.scopes.default`: list of scopes every MCP request needs.
- `auth.scopes.methods`: JSON-RPC method to list of scopes, for example `['tools/call' => ['content:read']]`.
- `auth.scopes.tools`: tool name to list of scopes, added to the ones of the method for that tool.
- `auth.scopes.implies`: scope to list of scopes it also grants, for example `['content:write' => ['content:read']]`.
- `auth.identity`: optional `fn (Mcp\Server\Authorization\AccessToken $token, mixed $identity): mixed` that replaces the identity tools and the authorizer see. Return an array or an object; `null`, `false` or any other scalar rejects the token with 401 `invalid_token`. See [OAuth](oauth.md#identity-in-external-mode).
- `auth.external`: the settings of `external` mode, as below. Ignored in the other modes.
- `auth.external.issuer`: required in `external` mode. The issuer URL, compared verbatim with the `iss` claim (a trailing slash counts).
- `auth.external.audience`: accepted `aud` value, a list of them, or `null` for the resource URL.
- `auth.external.algorithms`: accepted signature algorithms, a list. Only asymmetric ones (`RS256`, `RS384`, `RS512`, `PS256`, `PS384`, `PS512`, `ES256`, `ES384`, `ES512`, `EdDSA`) are possible. Default `['RS256']`.
- `auth.external.scopeClaim`: the claim that holds the scopes. Default `scope` (a space separated string, or a list); Microsoft Entra ID uses `scp`.
- `auth.external.tokenType`: required `typ` header of the JWT, for example `at+jwt`. `null` accepts any.
- `auth.external.leeway`: clock skew tolerated, in seconds, from 0 to 300. Default 0.
- `auth.external.cache`: name of the Cake cache config for the discovery metadata and the signing keys. Created as a file cache when you define none. Default `mcp_oauth`.
- `auth.external.httpClient`: a PSR-18 client for discovery and key requests. `null` uses Cake's HTTP client with a 5 second timeout.

A minimal `oauth` setup:

```php
Configure::write('Mcp.auth', [
    'mode' => 'oauth',
    'scopes' => [
        'methods' => ['tools/call' => ['content:read']],
        'tools' => ['publish_post' => ['content:publish']],
    ],
]);
```

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
