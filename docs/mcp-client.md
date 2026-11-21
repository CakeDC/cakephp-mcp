# Calling other MCP servers

The plugin is also an MCP client. Your application can list and call the tools,
resources and prompts of other MCP servers: a SaaS server with your own API key,
a local server started as a process, or a server where each of your users signs
in with their own account.

Clients are named in `Mcp.clients` and reached with `McpClients::get()`:

```php
use CakeDC\Mcp\Client\McpClients;

$result = McpClients::get('github')->callTool('search_issues', ['query' => 'is:open label:bug']);
```

The client is built on the SDK's client (`Mcp\Client`) and its HTTP and stdio
transports. HTTP goes through Cake's HTTP client, so nothing else needs
installing.

(For connecting Claude and other clients to your own MCP server, see
[Connecting clients](clients.md).)

## App mode: one set of credentials

The application calls the server as itself, with a token or headers you
configure:

```php
// config/app_local.php
'Mcp' => [
    'clients' => [
        'github' => [
            'url' => 'https://api.githubcopilot.com/mcp/',
            'token' => env('GITHUB_MCP_TOKEN'),
        ],
        'stripe' => [
            'url' => 'https://mcp.stripe.com',
            'headers' => ['Authorization' => 'Bearer ' . env('STRIPE_SECRET_KEY')],
            'timeout' => 20,
        ],
    ],
],
```

`token` is sent as `Authorization: Bearer <token>`; `headers` are sent as they
are. A `token` that is set but empty (an `env()` that is not defined) fails
with a message instead of sending no credentials.

For a token that rotates, or that you read from a vault, give `token` a
callable that returns the token string. It is called for each HTTP request the
client sends, so a new token is used as soon as the callable returns it. When
it returns something that is not a non-empty string, the call fails with a
`ClientException` naming the client. `bin/cake mcp check` never calls it (its
`Clients` line says `token from a callable`). A string is always a token, even
one that is also the name of a PHP function.

```php
'github' => [
    'url' => 'https://api.githubcopilot.com/mcp/',
    'token' => fn(): string => Vault::read('github-mcp-token'),
],
```

Config files that are cached or exported cannot hold a closure; set such a
client in `config/bootstrap.php` or in your `Application::bootstrap()` with
`Configure::write()`.

```php
$github = McpClients::get('github');

foreach ($github->listTools() as $tool) {
    echo $tool->name, ': ', $tool->description, "\n";
}
$result = $github->callTool('get_issue', ['owner' => 'cakephp', 'repo' => 'cakephp', 'issue_number' => 1]);
if ($result->isError) {
    // The tool failed; $result->content says why.
}
```

The calls:

- `listTools()`: the server's tools (every page), without the ones your tool filters leave out.
- `callTool($name, $arguments)`: a `Mcp\Schema\Result\CallToolResult` (`content`, `structuredContent`, `isError`).
- `listResources()`, `listResourceTemplates()`, `readResource($uri)`: resources, resource templates and contents.
- `listPrompts()`, `getPrompt($name, $arguments)`: prompts and their messages.
- `ping()`: checks that the server answers (`ping`, or `server/discover` in the 2026-07-28 revision).
- `serverInfo()`, `capabilities()`, `instructions()`: what the server said about itself when the connection
  started (`Mcp\Schema\Implementation`, `Mcp\Schema\ServerCapabilities`, its instructions for the model).
- `close()`: ends the connection now. It is opened on the first call and kept for the rest of the request (or the command), and closed when the PHP process ends (a server of the handshake revisions gets its `DELETE`).

The list calls take an optional `$limit` (`listTools(20)`): they stop fetching
pages once they have that many, and return at most that many.

A request the server refused before running it is sent once more, never more:
after a header mismatch (`-32020`, the headers of the 2026-07-28 revision do
not match the body), the tool list is fetched again first, every page of it
(100 at most), so the SDK learns
the headers each tool needs, as the specification advises; after an expired
session (HTTP `404` in the handshake revisions), on a new session. Any other
error is thrown as it is, so a call that may have run is never run twice. In
user mode and with `client_credentials`, a `401` the server gives before it
accepts any request of the call is sent once more with a new token; a `401`
after that (to the request that resumes a stream, say) is thrown. The client
also answers the `ping` a server of the handshake revisions sends.

Results are the SDK's classes. A server or network error is thrown as the SDK's
exception (`Mcp\Exception\ExceptionInterface`, for example
`Mcp\Exception\RequestException` or `ConnectionException`).

The client logs to the `mcp` scope. Headers and tokens are never logged, and
the configured `url` is logged without its query. At `debug` level the bodies
sent and received are logged as they are, so keep `debug` off for the `mcp`
scope in production when tool arguments or results are private.

### The application's own OAuth token

Some servers give machine clients an OAuth client of their own (the client
credentials grant of OAuth 2.1), instead of a fixed token:

```php
'reports' => [
    'url' => 'https://mcp.reports.example/mcp',
    'auth' => 'client_credentials',
    'clientId' => env('REPORTS_CLIENT_ID'),
    'clientSecret' => env('REPORTS_CLIENT_SECRET'),
    'issuer' => 'https://login.reports.example',   // or 'tokenUrl' => 'https://login.reports.example/oauth/token'
    'scopes' => ['reports:read'],
],
```

The application asks the token endpoint for a token with `grant_type`
`client_credentials`, its scopes and the `resource` parameter (the server's
`url`), authenticating with its secret (`client_secret_basic`, or
`client_secret_post` when that is the only one the authorization server
lists). The token endpoint is the configured `tokenUrl`, or the one in the
metadata of the configured `issuer`, which must name itself exactly as
`issuer`: the secret only ever goes to the authorization server you named,
never to one the MCP server names. The token is kept in the connection store,
encrypted like the user grants (run the plugin's migrations), and used until it
expires within `Mcp.client.refreshLeeway` seconds; when the server refuses it
(`401`), a new one is asked for once. The token is kept for the `issuer`,
`tokenUrl`, `clientId` and `scopes` it was asked with: once you change one of
them, the stored token is no longer used and a new one is asked for. `bin/cake mcp check` shows such clients
on its `Clients` line, without their secret.

## A local server over stdio

`command` starts the server as a process and talks to it on stdin and stdout:

```php
'Mcp' => [
    'clients' => [
        'files' => [
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-filesystem', ROOT . 'webroot' . DS . 'files'],
            'env' => ['NODE_ENV' => 'production'],
        ],
    ],
],
```

The process gets `PATH`, `HOME`, `USER`, the locale and temp directory
variables, and what you put in `env`. Nothing else of your application's
environment (database passwords, keys) is passed on. `cwd` sets its working
directory.

## User mode: each user signs in

With `auth` set to `user`, the server is called on behalf of each user, with
the access token that user got by signing in to the server's own authorization
server (OAuth 2.1). This is how servers like Linear, Notion or Sentry work.

```php
'Mcp' => [
    'clients' => [
        'linear' => [
            'url' => 'https://mcp.linear.app/mcp',
            'auth' => 'user',
        ],
    ],
],
```

```php
$linear = McpClients::get('linear')->for($this->Authentication->getIdentity());

if (!$linear->isConnected()) {
    return $this->redirect($linear->connectUrl('/settings/integrations'));
}
$issues = $linear->callTool('list_issues', ['assignee' => 'me']);
```

`for()` takes a user id, an identity (`getIdentifier()`), or a user entity or
array with an `id`. The connection offers the same calls as app mode, plus:

- `isConnected()`: whether the user has a grant the client can use.
- `connectUrl($redirect)`: the plugin's connect page for this client. Send the logged-in user there; after signing in they come back to `$redirect` (a path on your site) with a flash message.
- `disconnect()`: revokes the user's tokens at the authorization server and deletes them.

When the user is not connected, or the server stopped accepting their grant, a
call throws `CakeDC\Mcp\Client\Exception\NotConnectedException`: catch it and
offer `connectUrl()` again.

### What happens when a user connects

1. `GET /mcp-client/linear/connect` (the path is `Mcp.client.route`) needs a
   logged-in user (the `identity` request attribute of cakephp/authentication;
   `Mcp.client.identityAttribute` to change it). It finds the server's
   authorization server: the server's `401` challenge and its protected
   resource metadata (RFC 9728), then the authorization server's metadata
   (RFC 8414). The issuer must name itself exactly as the server said, and
   PKCE with S256 must be supported. A server that lets the unauthenticated
   probe through (it asks for a sign-in only on later calls) is still
   discovered through its well-known metadata URLs. When a token answer has no
   `scope`, the scopes asked for are kept as the ones granted (RFC 6749). A
   server of the 2025-03-26 revision may
   publish no protected resource metadata: then its origin (`url` without the
   path) is taken as the authorization server, as that revision says, and its
   metadata must be at `<origin>/.well-known/oauth-authorization-server` (or
   the OpenID Connect path) and name the origin as its issuer.
2. Without a `clientId`, the application gets a client id in one of two
   ways, chosen by `registration` (see
   [Client ID Metadata Document](#client-id-metadata-document) below). With a
   Client ID Metadata Document, the client id is the URL of a document the
   application serves, and nothing is registered or stored. Otherwise the
   application registers itself there once (dynamic client registration, RFC
   7591) as a public client, and keeps the registration for every user. The
   registration names the scopes it will ask for (`scope`) and an
   `application_type`: `web`, or `native` when the callback is on this machine
   (`localhost`), as the MCP specification asks for OpenID Connect servers. The callback URL it registers is built from
   `App.fullBaseUrl`, which by default comes from the `Host` header of the
   request: set `App.fullBaseUrl` in production, or a request with another
   host registers the application again with that callback. When the
   authorization server forgets the registration, a token request answered
   with `invalid_client` deletes it and the next connect registers again. If
   it fails some other way (the authorize page refuses the client, say),
   delete it yourself, for example with
   `(new TableConnectionStore())->deleteRegistration('linear', $issuer)` or
   by deleting the rows of `mcp_client_registrations` whose `client` is that
   client's name.
3. The browser goes to the authorization server with PKCE, a `state` and the
   `resource` parameter (RFC 8707). The sign-in in progress is kept in the
   user's session. The scopes asked for are the ones the server names (its
   challenge, or its metadata), plus `offline_access` when the authorization
   server or the server lists it: servers in the OpenID Connect style (Auth0,
   Okta, Microsoft Entra ID) only issue a refresh token for it. A configured
   `scopes` is sent as written, so add `offline_access` there yourself if you
   set it.
4. The authorization server sends the browser to
   `GET /mcp-client/linear/callback`. The `state` must match the one in the
   session (it is used once), the same user must still be logged in, the
   response must come from the same issuer (`iss`, RFC 9207; an error answer
   too, whose error is not shown otherwise), and the sign-in must finish
   within 10 minutes. The code is exchanged and the tokens are
   stored.

Calls then send the user's access token. It is refreshed when it expires
within `Mcp.client.refreshLeeway` seconds (60), and once more if the server
answers `401`. When the refresh is refused, the grant is deleted and the user
connects again.

Only one request refreshes a user's grant at a time: many authorization
servers end the whole grant when the same refresh token is used twice. Other
requests of that user wait for the new token, up to 10 seconds, and then fail
with a `ClientException` (the user stays connected).

### Client ID Metadata Document

The MCP authorization specification prefers a Client ID Metadata Document
(draft-ietf-oauth-client-id-metadata-document) to dynamic registration: the
client id is an https URL, and the authorization server fetches the client's
details from it. The plugin serves one for each user mode client at
`GET /mcp-client/<name>/client.json` (under `Mcp.client.route`), with no login:

```json
{
    "client_id": "https://app.example.com/mcp-client/linear/client.json",
    "client_name": "CakePHP MCP Client",
    "redirect_uris": ["https://app.example.com/mcp-client/linear/callback"],
    "grant_types": ["authorization_code", "refresh_token"],
    "response_types": ["code"],
    "token_endpoint_auth_method": "none",
    "token_endpoint_auth_methods_supported": ["none"],
    "application_type": "web"
}
```

It never holds a secret: the client signs in with PKCE and no client
authentication. `client_name` is `Mcp.client.name`, and the URLs are built
from `App.fullBaseUrl`, so set it in production. The route must be reachable
without a session: with cakedc/users, `McpConfig::rbacPermissions()` has a rule
for it.

`Mcp.clients.<name>.registration` picks how a client without a configured
`clientId` gets one:

- `auto` (the default): the document, when the authorization server's
  metadata says `client_id_metadata_document_supported: true` and
  `App.fullBaseUrl` is https on a public host, so the authorization server
  can fetch it: not `localhost`, a local or reserved name (`.internal`,
  `.local`, `.test`, `.example`, `.invalid`), a private address, or a name
  that does not resolve or resolves to a private or loopback address (a
  development site such as `shop.ddev.site` resolves to your machine).
  Otherwise dynamic registration, as before. A site with public addresses
  that the authorization server still cannot reach (a staging site behind a
  VPN) needs `dynamic`: the sign-in would end on the authorization server's
  error page.
- `metadata`: always the document. The sign-in fails with a message when the
  base URL is not public https or the authorization server does not support
  it. The name is not resolved, so this also works for a private
  authorization server that reaches a site on the same network.
- `dynamic`: always dynamic registration.

A configured `clientId` always wins over both. `bin/cake mcp check` says on
its `Clients` line which one each client uses, as far as it can tell without
asking the authorization server.

The connect, callback and `client.json` routes are only connected when a
client uses user mode. With cakedc/users, `McpConfig::rbacPermissions()` then
also lets any logged-in user reach the first two, and anyone the document, so add it to `config/permissions.php` as the
[OAuth guide](oauth.md) shows for the endpoint.

### When the server asks for more scope

A server can refuse a call because the user's token lacks a scope: it answers
`403` with a `WWW-Authenticate: Bearer error="insufficient_scope", scope="..."`
challenge. The call still throws, and the user's grant is marked with the
scopes they already had plus the ones the server asked for. `needsMoreScope()`
says so, `stepUpScopes()` lists them, and the next connect of that user
(`connectUrl()`) asks the authorization server for all of them, so nothing
granted before is lost. Configured `scopes` are no limit here: the scopes the
server names are added to them. The mark keeps 50 scopes and 2048 bytes at
most, the ones the user already had first:

```php
$linear = McpClients::get('linear')->for($identity);
try {
    $linear->callTool('delete_issue', ['id' => $id]);
} catch (ExceptionInterface $e) {
    if ($linear->needsMoreScope()) {
        return $this->redirect($linear->connectUrl('/issues'));
    }
    throw $e;
}
```

The mark is kept with the grant (column `step_up_scope` of
`mcp_client_connections`) and cleared when the user connects again. The user stays connected meanwhile.

### Storing tokens

By default grants are stored in two tables of the plugin,
`mcp_client_connections` and `mcp_client_registrations`. Rows are found by a
SHA-256 key of the client name and the user id (or the issuer), so user ids
that differ only in case or accents never share a grant, whatever the
database's collation, and ids and issuers of any length fit. Create the tables
when you use user mode:

```
bin/cake migrations migrate -p CakeDC/Mcp
```

Access tokens, refresh tokens and client secrets are stored encrypted with
`Security::encrypt()`, keyed with `Mcp.client.encryptionKey` or, when it is not
set, your `Security.salt`. Changing that key disconnects every user (their
grants can no longer be read, so they sign in again). With your own key, the
salt can change without that.

To keep grants elsewhere, implement
`CakeDC\Mcp\Client\Store\ConnectionStoreInterface` and set its class in
`Mcp.client.store`. Keep tokens and secrets unreadable at rest, and make
`claimRefresh()` atomic (one conditional update, for example), since it is what
keeps two requests from refreshing the same grant. `saveRefreshed()` stores what
a refresh produced only while the request still holds that claim (one
conditional update on the claim id), so a user who disconnects while a refresh
is running stays disconnected. `save()` ends any claim on the grant it
replaces, so a refresh of the old grant that finishes after the user signed in
again stores nothing, and two requests that save a user's first grant at the
same time must both succeed (the last one wins). `markStepUp()` updates only
the step-up mark of a grant that is still there: never the tokens, and never a
row the user deleted by disconnecting.

After it claims the refresh, a request reads the grant again: when another
request refreshed it in the meantime, it uses that token and never sends the
refresh token that request already spent.

### A server whose owner gave you a client

Some authorization servers do not register clients automatically. Register
the callback URL with them (`https://your-app.example.com/mcp-client/<name>/callback`)
and set what they give you:

```php
'notion' => [
    'url' => 'https://mcp.notion.com/mcp',
    'auth' => 'user',
    'clientId' => env('NOTION_CLIENT_ID'),
    'clientSecret' => env('NOTION_CLIENT_SECRET'),
    // The authorization server that gave you the client, as its metadata names itself
    // (the "issuer" of its /.well-known/oauth-authorization-server document).
    'issuer' => env('NOTION_ISSUER'),
    'scopes' => ['read'],
],
```

With a `clientSecret` and discovery, `issuer` is required. The MCP server names
its authorization server on every sign-in, so without it the secret would go to
whatever authorization server the MCP server names, a compromised one included.
With `issuer` set, the client only signs in when the MCP server names that
issuer, compared exactly; otherwise the sign-in fails with an error naming both.
Nothing is sent to the other server.

For an authorization server on your own network, set its endpoints too. They
are then trusted like `url` and nothing is discovered:

```php
'intranet' => [
    'url' => 'https://mcp.intranet.example/mcp',
    'auth' => 'user',
    'authorizationUrl' => 'https://sso.intranet.example/oauth/authorize',
    'tokenUrl' => 'https://sso.intranet.example/oauth/token',
    'revocationUrl' => 'https://sso.intranet.example/oauth/revoke',
    'clientId' => 'cake-app',
    'clientSecret' => env('SSO_CLIENT_SECRET'),
    // What the server names itself in its metadata, so the client can check sign-in responses.
    'issuer' => 'https://sso.intranet.example/realms/corp',
],
```

Without `issuer`, the client cannot know which server a sign-in response comes
from and does not check its `iss`; with configured endpoints that is the
server you wrote, so set it when you know it. With discovery, `issuer` also
pins the authorization server, as above.

## Which addresses the client calls

There are two levels of trust:

- **Addresses you write in `Mcp.clients`** (`url`, `authorizationUrl`,
  `tokenUrl`, `revocationUrl`, the well-known metadata paths of `url`, and a
  metadata URL in the server's challenge on the same origin as `url`: scheme,
  host and port) are trusted, internal hosts included, so a server on your
  network or on `localhost` can be discovered. Plain `http` is accepted only for this
  machine (`localhost`, a `.localhost` name, a loopback address); for another
  host set `'allowInsecure' => true` on the client, and only on a network you
  trust, since tokens travel in the clear.
- **Addresses a remote server hands the client** (the metadata URL in its
  challenge on another origin, the authorization server, its token, registration and revocation
  endpoints, and any redirect) go through a guard against server-side request
  forgery: https only, a host name (not an IP address, not `localhost` or a
  name under `.localhost`, `.local`, `.localdomain`, `.internal`,
  `.home.arpa` or `.arpa`) whose every address is public, the connection
  pinned to the address that was checked, no proxy from the environment,
  and answers capped at `Mcp.client.maxResponseBytes` (1 MiB), uncompressed
  (a compressed answer is refused, so the cap applies to what is read). A redirect is
  followed only for metadata, and checked again; requests that carry a code,
  a secret or a token never follow one.

An authorization server on a private address is therefore refused when it is
discovered. Configure its endpoints, as above, to use it, or set
`allowPrivateNetwork` on that client:

```php
'intranet-tools' => [
    'url' => 'https://mcp.corp.example/mcp',
    'auth' => 'user',
    'allowPrivateNetwork' => true,
],
```

With it, the OAuth endpoints learned for that client (its authorization
server, token, registration and revocation endpoints and the metadata URLs)
may be names that resolve to private, loopback or link-local addresses, and
names under `.internal`, `.local` and the other local names, and `localhost`
itself (with `allowInsecure` too, an authorization server on this machine in
development). A name the DNS has no record of is looked up as the system does,
so `localhost` and the names in `/etc/hosts` work. IP addresses and other single-label names stay refused, the connection is still pinned to the
address that was checked, and plain `http` stays refused unless the client
also has `allowInsecure`. Other clients are not affected. Only set it for a
server you trust as much as your own network: a compromised server could make
the application call internal addresses. `bin/cake mcp check` warns on its
`Clients` line for each client that sets it.

### Behind an outbound proxy

On a network where the application reaches the internet only through a
proxy, set `Mcp.client.proxy`:

```php
'Mcp' => [
    'client' => [
        'proxy' => env('MCP_CLIENT_PROXY'), // 'http://user:password@proxy.corp.example:3128'
    ],
],
```

Every HTTP request of every client then goes through it: the MCP sessions,
configured endpoints and the addresses a remote server names. The one
exception is a configured `url` on this machine (`localhost`, `127.0.0.1`,
`::1`), which is reached directly: through the proxy its token would cross the
network in clear and land on the proxy's own `localhost`. `NO_PROXY` in the
environment is not followed. It is an `http`, `https` or `socks5h` URL with no
path; anything else fails with a message naming the setting. Use `socks5h`,
not `socks5`: with `socks5` curl resolves the host name in the application,
which is what the proxy is there to avoid. Its user name and password are sent to the proxy
only, and `bin/cake mcp check` shows the proxy on its `Clients` line without
them. Default `null`: no proxy, and a proxy from the environment
(`https_proxy`) is not used for the addresses a remote server names.

What changes in the guard for those addresses: the proxy resolves the host
name, so the connection is no longer pinned to an address the application
checked, and a name the application itself cannot resolve is allowed. What
stays: https only, no IP addresses, no `localhost` or other local names, no
user name or fragment, and a name that does resolve on the application's side
to a private address is still refused. The proxy can still reach hosts of your
own network that the application cannot tell apart from public ones, so
restrict what the proxy itself lets through where that matters.

## Caching lists

A server on the 2026-07-28 revision can say how long a list result stays
fresh (`ttlMs`) and who may share it (`cacheScope`, `private` or `public`).
Set `cache` on a client to keep such results in a Cake cache config:

```php
'github' => [
    'url' => 'https://api.githubcopilot.com/mcp/',
    'token' => env('GITHUB_MCP_TOKEN'),
    'cache' => 'default',
],
```

`listTools()`, `listResources()`, `listResourceTemplates()` and
`listPrompts()` then use a cached page while it is fresh, without asking the
server. A `private` result is kept per user in user mode and per client in app
mode, and always per credential it was asked with: the user's access token,
the configured `token` or `headers`, or what a `token` callable returns (which
is then called once more for each list). So it is never served for another
token, to another user, or after a user reconnects with another account. A
`public` one is shared by every user of that client. A result without hints, or with a ttl of 0, is not
kept. A `notifications/tools/list_changed` (or the resources and prompts ones)
from the server drops that client's entries for those lists, for every user.
In user mode a cached list is only used for a user who is still connected. A
tool list whose tools have `x-mcp-header` annotations is never cached, because
the SDK learns the headers it must send for those tools from the listing it
fetches. `cache` must name an existing cache config, or the client fails with
a message. Default `null`: nothing is cached, as before.

## Tool filters, timeouts and protocol version

```php
'github' => [
    'url' => 'https://api.githubcopilot.com/mcp/',
    'token' => env('GITHUB_MCP_TOKEN'),
    'tools' => [
        'only' => ['get_*', 'list_*', 'search_*'],
        'except' => ['get_secret_*'],
    ],
    'timeout' => 15,
    'protocolVersion' => '2025-06-18',
],
```

- `tools.only` and `tools.except` are shell patterns (`fnmatch()`, case sensitive). `except` wins. A tool they leave out is not listed, and calling it throws `CakeDC\Mcp\Client\Exception\ClientException` without sending anything.
- `timeout` is in seconds, for the connection and for each request (default 30). In user mode it is also the limit for all the requests of one sign-in step together (the discovery and registration of `connect`, the token exchange of `callback`), so a slow authorization server holds a web worker that long at most.
- `protocolVersion` asks for a revision of the protocol; leave it out to let the SDK negotiate.

## Presets for well-known servers

These snippets are starting points; check each provider's documentation for
its current address, scopes and how to get a token.

```php
'Mcp' => [
    'clients' => [
        // GitHub: a personal access token or a GitHub App token.
        'github' => ['url' => 'https://api.githubcopilot.com/mcp/', 'token' => env('GITHUB_MCP_TOKEN')],
        // Stripe: a restricted API key.
        'stripe' => ['url' => 'https://mcp.stripe.com', 'token' => env('STRIPE_RESTRICTED_KEY')],
        // Linear, Sentry and Notion: each user signs in.
        'linear' => ['url' => 'https://mcp.linear.app/mcp', 'auth' => 'user'],
        'sentry' => ['url' => 'https://mcp.sentry.dev/mcp', 'auth' => 'user'],
        'notion' => ['url' => 'https://mcp.notion.com/mcp', 'auth' => 'user'],
        // A filesystem server on this machine.
        'files' => [
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-filesystem', ROOT . 'storage'],
        ],
    ],
],
```

## Checking the setup

`bin/cake mcp check` lists the clients on its `Clients` line, and fails when a
client config is invalid, when a user mode client is configured but the
plugin routes are not loaded, or when the default store's tables are missing.

## Testing code that uses a client

Give `McpClients` a registry built on test doubles, and reset it after the
test. The HTTP client comes from a `CakeDC\Mcp\Client\HttpClientFactoryInterface`,
so a PSR-18 double can answer for the server:

```php
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\ClientRegistry;
use CakeDC\Mcp\Client\HttpClientFactoryInterface;
use CakeDC\Mcp\Client\McpClients;
use Psr\Http\Client\ClientInterface;

protected function setUp(): void
{
    parent::setUp();
    $double = $this->myPsr18Double(); // answers the JSON-RPC requests your code makes
    McpClients::setRegistry(new ClientRegistry(new class ($double) implements HttpClientFactoryInterface {
        public function __construct(private ClientInterface $client)
        {
        }

        public function create(ClientConfig $config): ClientInterface
        {
            return $this->client;
        }
    }));
}

protected function tearDown(): void
{
    McpClients::reset();
    parent::tearDown();
}
```

`ClientRegistry` also takes a `ConnectionStoreInterface` (an in-memory store
for user mode tests) and the `GuardedHttp` layer, whose transport
(`GuardedTransportInterface`) and DNS (`HostResolverInterface`) can be doubles
too, so tests never reach the network.
