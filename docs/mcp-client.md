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
- `listResources()`, `readResource($uri)`: resources and their contents.
- `listPrompts()`, `getPrompt($name, $arguments)`: prompts and their messages.
- `close()`: ends the connection now. It is opened on the first call and kept for the rest of the request (or the command).

Results are the SDK's classes. A server or network error is thrown as the SDK's
exception (`Mcp\Exception\ExceptionInterface`, for example
`Mcp\Exception\RequestException` or `ConnectionException`).

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
   PKCE with S256 must be supported.
2. Without a `clientId`, the application registers itself there once (dynamic
   client registration, RFC 7591) as a public client, and keeps the
   registration for every user. The callback URL it registers is built from
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
   response must come from the same issuer (`iss`, RFC 9207), and the sign-in
   must finish within 10 minutes. The code is exchanged and the tokens are
   stored.

Calls then send the user's access token. It is refreshed when it expires
within `Mcp.client.refreshLeeway` seconds (60), and once more if the server
answers `401`. When the refresh is refused, the grant is deleted and the user
connects again.

Only one request refreshes a user's grant at a time: many authorization
servers end the whole grant when the same refresh token is used twice. Other
requests of that user wait for the new token, up to 10 seconds, and then fail
with a `ClientException` (the user stays connected).

The connect and callback routes are only connected when a client uses user
mode. With cakedc/users, `McpConfig::rbacPermissions()` then also lets any
logged-in user reach them, so add it to `config/permissions.php` as the
[OAuth guide](oauth.md) shows for the endpoint.

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
is running stays disconnected.

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
discovered. Configure its endpoints, as above, to use it.

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
configured endpoints and the addresses a remote server names. It is an `http`,
`https`, `socks5` or `socks5h` URL with no path; anything else fails with a
message naming the setting. Its user name and password are sent to the proxy
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
- `timeout` is in seconds, for the connection and for each request (default 30).
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
