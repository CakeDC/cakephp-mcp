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
    'http' => ['allowedHosts' => null, 'allowedOrigins' => [], 'maxBodyBytes' => 4194304],
    'identity' => ['required' => true, 'attribute' => 'identity', 'scopes' => null],
    'authorization' => ['authorizer' => AllowAllToolAuthorizer::class, 'rbac' => []],
    'auth' => ['mode' => 'host'],
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
- `http.allowedHosts`: hosts accepted in the `Host` header (DNS-rebinding protection). `null` means the host of `App.fullBaseUrl` plus `localhost`, `127.0.0.1` and `[::1]`, which only protects when `App.fullBaseUrl` is set explicitly (the CakePHP 4 skeleton derives it from the request `Host` otherwise, and then every host is accepted). A list replaces that. Set it explicitly in production; it is required for servers with `identity.required` set to `false`.
- `http.allowedOrigins`: origins accepted for browser clients, also used for CORS. Empty by default.
- `http.maxBodyBytes`: the largest request body the endpoint reads, in bytes. Default `4194304` (4 MiB, the SDK's own limit). It is passed to the SDK transport and to its `AuthorizationMiddleware`, and a larger POST is answered with `413` and a JSON-RPC error (code `-32600`, `data.maxBodyBytes`) that names the limit, before authentication. Raise it when your tools take large arguments, such as base64 uploads. A value that is not a positive integer makes the endpoint answer `500 Internal error`.
- `identity.required`: answer 401 when the request has no identity.
- `identity.attribute`: request attribute that holds the identity.
- `identity.scopes`: optional `fn (mixed $identity): array` returning scope strings for the SDK access token.
- `authorization.authorizer`: class name or instance of a `ToolAuthorizerInterface`.
- `authorization.rbac`: options for `RbacToolAuthorizer` (the `cakedc/auth` Rbac config).
- `auth`: how the endpoint authenticates remote clients; only `host` in 1.x. See [Mcp.auth](#mcpauth).
- `stdio.identity`: closure returning the identity for `bin/cake mcp server`.
- `stdio.trusted`: skip per-tool authorization for stdio.
- `audit`: the audit log of MCP calls, off by default. See [Audit log](#audit-log).

## Mcp.auth

- `auth.mode`: `host`, the only mode of the 1.x line (CakePHP 4): your authentication puts the identity in the request, and the plugin reads it (see [Authentication](authentication.md)).

The `oauth` and `external` modes of 2.x (OAuth 2.1 access tokens of
`cakedc/cakephp-oauth-server`, or JWT access tokens of a third-party
authorization server) need cakedc/cakephp-mcp 2.x on CakePHP 5. Any other
value of `auth.mode` makes the plugin bootstrap throw, so the application (and
every `bin/cake` command, `mcp check` included) stops with that message, and an
endpoint that still sees such a value answers `500 Internal error`: a security
setting that cannot be honoured never opens anything.

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

The `debug` and `error` engines of the CakePHP 4 application skeleton have
`'scopes' => false`: they only take messages without a scope, so the `mcp`
messages (and the `mcp_audit` lines of the [audit log](#audit-log)) reach only
the engines that list those scopes. An engine with no `scopes` key at all (or
`'scopes' => []` or `null`) takes every scope.

## Audit log

`Mcp.audit` records who called what: every audited call, allowed, denied or
failed, and the calls that authentication refuses. It is off by default.

```php
'Mcp' => [
    'audit' => [
        'enabled' => true,
    ],
],
```

- `audit.enabled`: record the calls. Default `false`.
- `audit.methods`: the methods recorded, among `tools/call`, `prompts/get` and `resources/read`. Default `['tools/call']`. Listing tools, `initialize`, `ping` and notifications are never recorded.
- `audit.log`: write one log line per call under the `mcp_audit` scope. Default `true`. With `false` only the `Mcp.audit` event is dispatched, for applications that store the records themselves.
- `audit.arguments`: record the call arguments. Default `true`. Arguments can hold personal data, depending on your tools: turn this off, or list the sensitive ones in `audit.redact`.
- `audit.redact`: argument names whose value becomes `"[redacted]"`, at any depth, ignoring case. Default `['password', 'token', 'secret']`. Like every list, yours replaces the default.
- `audit.maxLength`: longest string value kept, in characters; longer ones are cut and end with `...`. Default `200`. The arguments of a record are also capped at 2 KB of JSON, and replaced by `{"truncated": true}` above that.

An invalid setting stops the endpoint (`500 Internal error`, with the setting
named in the `mcp` log) and makes `bin/cake mcp check` fail.

### What a record holds

| Field | Value |
|---|---|
| `method` | `tools/call`, `prompts/get` or `resources/read` |
| `name` | the tool or prompt, or the resource URI, cut to 255 characters |
| `outcome` | see below |
| `identity` | the identifier of the caller, `null` without one |
| `client` | the client of the access token (`client_id`, `azp`, or a single `aud`); always `null` over HTTP in host mode, the only mode of 1.x. Kept so the records have the same fields as in 2.x |
| `session` | the MCP session id |
| `request` | the JSON-RPC request id, cut to 255 characters |
| `arguments` | the arguments, redacted and cut |
| `error` | the error message, for an outcome that is not `ok`; for `tool_error`, the first text the tool returned (kept even with `audit.arguments` off: a tool may repeat its input there) |
| `durationMs` | how long the call took, `null` when it is not known |

| Outcome | When | Log level |
|---|---|---|
| `ok` | the call worked | `info` |
| `tool_error` | the tool answered with an error (`ToolCallException`, `ToolError::invalid()`) | `notice` |
| `invalid` | an unknown tool or invalid arguments | `notice` |
| `denied` | the authorizer denied the tool | `warning` |
| `error` | the tool threw, or another JSON-RPC error | `warning` |
| `unauthorized` | `401` from authentication, before the server runs | `warning` |

A denied tool still gets the same answer as an unknown one (see
[Authorization](authorization.md#what-a-denied-caller-sees)); only the record
tells them apart.

Limits:

- A refused call (`unauthorized`) has no client, no session and no caller.
- A tool that waits for an answer from the client (elicitation, sampling):
  over HTTP it finishes in a later request, so its `durationMs` is `null`;
  over stdio it finishes in the same process, and `durationMs` includes the
  time the person took to answer. In the stateless revision every round that
  still waits for input is skipped, and the call is recorded once, when it
  finishes.
- To tell a denied tool from an unknown one, the audit hands the SDK the
  tool registry it keeps (tools are then loaded when the server is built).
  If `server.configure` sets a registry of its own, an unknown tool that the
  authorizer refuses is recorded as `denied`.
- The access token never appears in a record.
- Over stdio the identity is `stdio.identity`, and a trusted server
  (`stdio.trusted`) denies nothing.

### The log line

```
MCP tools/call find_order ok 34ms identity=12 client=62938fb0 session=0f3c... request=2 args={"number":"A-1042"}
```

Send it to a file of its own with a scoped log config:

```php
use Cake\Log\Engine\FileLog;

'Log' => [
    'mcp_audit' => [
        'className' => FileLog::class,
        'path' => LOGS,
        'file' => 'mcp_audit',
        'scopes' => ['mcp_audit'],
        'levels' => ['info', 'notice', 'warning'],
    ],
],
```

The line also carries the `mcp` scope, so an `mcp` engine receives it too.
`bin/cake mcp check` warns when the audit is on and no engine takes its
lines.

### The Mcp.audit event

Each record is dispatched as the `Mcp.audit` event on the global event
manager, with the record as the event data. The event has no subject: read it
with `getData()` (Cake throws when you ask for the subject of an event that
has none). A listener that throws is logged under `mcp` and the call goes on.

```php
use Cake\Event\EventInterface;
use Cake\Event\EventManager;

EventManager::instance()->on('Mcp.audit', function (EventInterface $event): void {
    $record = $event->getData(); // method, name, outcome, identity, client, ...
});
```

### Storing records in the database

Cake has no log engine that writes to a table, so store the records from the
event. A migration for the table (`Migrations\BaseMigration` is the base class
of cakephp/migrations 5.x and 4.5+; older 4.x versions use
`Migrations\AbstractMigration`):

```php
use Migrations\BaseMigration;

class CreateMcpAuditLogs extends BaseMigration
{
    public function change(): void
    {
        $this->table('mcp_audit_logs')
            ->addColumn('method', 'string', ['limit' => 50])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('outcome', 'string', ['limit' => 20])
            ->addColumn('identity', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('client', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('session', 'string', ['limit' => 36, 'null' => true])
            ->addColumn('request', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('arguments', 'text', ['null' => true])
            ->addColumn('error', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('duration_ms', 'integer', ['null' => true])
            ->addColumn('created', 'datetime')
            ->addIndex(['identity', 'created'])
            ->addIndex(['created'])
            ->create();
    }
}
```

A table class with the `Timestamp` behavior (it fills `created`):

```php
namespace App\Model\Table;

use Cake\ORM\Table;

class McpAuditLogsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->addBehavior('Timestamp');
    }
}
```

And a listener, registered in `Application::bootstrap()`:

```php
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\ORM\TableRegistry;

EventManager::instance()->on('Mcp.audit', function (EventInterface $event): void {
    $record = (array)$event->getData();
    $logs = TableRegistry::getTableLocator()->get('McpAuditLogs');
    $logs->save($logs->newEntity([
        'method' => $record['method'],
        'name' => $record['name'],
        'outcome' => $record['outcome'],
        'identity' => $record['identity'] === null ? null : (string)$record['identity'],
        'client' => $record['client'],
        'session' => $record['session'],
        'request' => $record['request'] === null ? null : (string)$record['request'],
        'arguments' => json_encode($record['arguments']),
        'error' => $record['error'] === null ? null : mb_substr($record['error'], 0, 255),
        'duration_ms' => $record['durationMs'],
    ], ['accessibleFields' => ['*' => true]]));
});
```

Set `audit.log` to `false` if the table is the only record you want. The save
runs in the request that served the call; on a busy server, a queue job or a
log shipper keeps it out of the way.
