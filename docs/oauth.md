# OAuth 2.1

With OAuth, remote MCP clients sign people in instead of carrying a hand made
token. This page covers the whole setup: choosing a mode, configuring it,
connecting clients, testing and fixing what goes wrong.

- [What OAuth gives you](#what-oauth-gives-you)
- [Choosing a mode](#choosing-a-mode)
- [oauth mode step by step](#oauth-mode-step-by-step)
- [external mode](#external-mode)
- [Scopes and tool authorization](#scopes-and-tool-authorization)
- [Connecting clients](#connecting-clients)
- [Subdirectory installs and proxies](#subdirectory-installs-and-proxies)
- [Testing](#testing)
- [Troubleshooting](#troubleshooting)

## What OAuth gives you

Clients such as claude.ai custom connectors, Claude Code and the MCP Inspector
find the sign-in on their own:

1. They call `/mcp` without a token and get a `401` with
   `WWW-Authenticate: Bearer resource_metadata="..."`.
2. That URL is the protected resource metadata (RFC 9728), served by the plugin
   at `/.well-known/oauth-protected-resource/mcp`. It names the authorization
   server.
3. The person signs in there, the client receives an access token and sends it
   as `Authorization: Bearer <token>`.

Your tools do not change. They still receive the identity through
`CurrentIdentity` and the token through `RequestContext::getAccessToken()`, as
described in [Writing tools](tools.md).

## Choosing a mode

`Mcp.auth.mode` has three values:

| Mode | Use it when | Who signs people in | Identity tools see |
| --- | --- | --- | --- |
| `host` (default) | Clients send a token your own authentication understands (2.0 behaviour). | Nobody: you issue the tokens. | What your `cakephp/authentication` setup put in the request. |
| `oauth` | The application has its own login and you want MCP clients to use it. | `cakedc/cakephp-oauth-server` in the same application: your login page, a consent page and a page where people see and revoke connections. | The application identity of the token owner, so RBAC rules keep working. |
| `external` | People already sign in at Auth0, Keycloak, Microsoft Entra ID, WorkOS or any other RFC 8414 or OpenID Connect issuer that issues JWT access tokens. | The external provider. | A small `ArrayObject` built from the token claims, or whatever `Mcp.auth.identity` returns. |

In `oauth` and `external` modes the identity attribute of your own
authentication (`Mcp.identity.attribute`) is ignored on the MCP route: the
caller is whoever the access token says. A browser session cookie alone never
runs an MCP call.

## oauth mode step by step

### 1. Install the oauth plugin

The two CakeDC OAuth packages are private repositories for now. Until they are
on Packagist, a root application with the default `stable` minimum stability
declares them as `vcs` repositories (Composer needs read access to both, for
example a GitHub token) and asks for the beta:

```json
{
    "repositories": [
        {"type": "vcs", "url": "git@github.com:CakeDC/oauth-server.git"},
        {"type": "vcs", "url": "git@github.com:CakeDC/cakephp-oauth-server.git"}
    ]
}
```

```bash
composer require "cakedc/cakephp-oauth-server:^1.0@beta"
```

When they are public they will be on Packagist and the `repositories` block
goes away.

Load the plugin in `Application::bootstrap()`:

```php
$this->addPlugin('CakeDC/OAuthServer');
```

Run its migrations:

```bash
bin/cake migrations migrate -p CakeDC/OAuthServer
```

If all your migrations live in `config/Migrations/`, copy the plugin's files
there instead, as the
[oauth plugin installation notes](https://github.com/CakeDC/cakephp-oauth-server/blob/1.next-cake5/docs/installation.md#migrations)
describe. Do not run both.

### 2. Declare the MCP endpoint as a resource

In the `OAuthServer` configuration the resource is keyed by the exact URL of
your endpoint: `App.fullBaseUrl`, the base path of the application and
`Mcp.route`. It must be the same string everywhere, because a token is issued
for one resource and refused anywhere else.

```php
// config/app_local.php
'OAuthServer' => [
    'resources' => [
        'https://example.com/mcp' => [
            'name' => 'Example MCP',
            'scopes' => [
                'content:read' => 'Read your posts',
                'content:write' => 'Edit drafts',
                'content:publish' => 'Publish posts',
            ],
            'implies' => [
                'content:publish' => ['content:write'],
                'content:write' => ['content:read'],
            ],
            'defaultScopes' => ['content:read'],
        ],
    ],
],
```

The description of each scope is what the person reads on the consent page.
The oauth plugin has the other settings (issuer, token lifetimes, client
registration) in its own documentation.

### 3. Configure `Mcp.auth`

```php
Configure::write('Mcp.auth', [
    'mode' => 'oauth',
    'scopes' => [
        'methods' => ['tools/call' => ['content:read']],
        'tools' => [
            'create_draft' => ['content:write'],
            'publish_post' => ['content:publish'],
        ],
    ],
]);
```

Every scope named in `Mcp.auth.scopes` must be declared by the resource. If one
is not, the endpoint fails closed with a `500` and logs the name of the
scope. See [Scopes and tool authorization](#scopes-and-tool-authorization) for
how the policy works and every key in [Configuration](configuration.md#mcpauth).

### 4. CSRF

MCP clients and the OAuth endpoints are not browsers and cannot send a CSRF
token. Skip the check for both, and only for them:

```php
use CakeDC\Mcp\McpConfig;
use CakeDC\OAuthServer\OAuthServer;

$csrf = new CsrfProtectionMiddleware();
$csrf->skipCheckCallback(
    fn (ServerRequestInterface $request): bool => McpConfig::isEndpoint($request)
        || OAuthServer::csrfSkip($request),
);
```

`McpConfig::isEndpoint()` is true for the MCP route and for its metadata path,
and follows `Mcp.route`, so you do not hard code `/mcp`.

### 5. Keep the oauth Token authenticator off the MCP route

The oauth plugin ships an authenticator, `CakeDC/OAuthServer.Token`, for the
other APIs of your application. On the MCP route it must not run: it answers a
token it does not accept for that path with its own `401` before this plugin
sees the request, and every MCP call fails. Load it only for other requests:

```php
public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
{
    $service = new AuthenticationService();
    $service->loadAuthenticator('Authentication.Session', ['sessionKey' => 'Auth']);

    if (!McpConfig::isEndpoint($request)) {
        $service->loadAuthenticator('OAuthToken', [
            'className' => 'CakeDC/OAuthServer.Token',
            'resource' => 'https://example.com/api',
        ]);
    }

    return $service;
}
```

If the application protects no other API with the oauth plugin, do not load
the authenticator at all.

The example gives the authenticator its own alias, `OAuthToken`. Both
`Authentication.Token` and `CakeDC/OAuthServer.Token` default to the alias
`Token`, so a host that loads both must rename one of them.

`bin/cake mcp check` reports this mistake (see below).

### 6. Check the setup

```bash
bin/cake oauth check
bin/cake mcp check
```

`bin/cake oauth check` is the oauth plugin's own check of its configuration,
tables and routes. `bin/cake mcp check` makes no network call and checks the
MCP side:

| Check | What it looks at |
| --- | --- |
| Endpoint | The URL of the endpoint, or a warning when `Mcp.route` is disabled. |
| Authentication | The mode, or the `Mcp.auth` setting that is invalid. |
| Resource | The resource URL, the metadata URL and the scopes it advertises. In `oauth` mode it fails when the resource is not declared in `OAuthServer.resources`. In `external` mode it lists the accepted audiences and warns when the resource URL is not among them. |
| Metadata route | That the plugin routes are connected, and warns when the path the application serves differs from the advertised one (subdirectory installs need a rewrite, see below). |
| Allowed hosts | That the host of the resource is in `Mcp.http.allowedHosts`. |
| CSRF | That the CSRF middleware skips POST requests to the MCP route. |
| Authenticators | In `oauth` mode, that no authenticator of the application answers access tokens on the MCP route before the plugin. |

Sample output:

```
[PASS] Endpoint: https://example.com/mcp
[PASS] Authentication: oauth mode: access tokens of cakedc/cakephp-oauth-server in this application
[PASS] Resource: https://example.com/mcp, metadata at https://example.com/.well-known/oauth-protected-resource/mcp, scopes: content:read content:write content:publish
[PASS] Metadata route: /.well-known/oauth-protected-resource/mcp
[PASS] Allowed hosts: example.com, localhost, 127.0.0.1, [::1]
[PASS] CSRF: the MCP route is skipped
[PASS] Authenticators: no authenticator answers access tokens at the MCP route first
```

A failing check looks like this, and the command exits with an error code:

```
[FAIL] Authenticators: an authenticator of the application (the CakeDC/OAuthServer.Token one) answers access tokens at /mcp before the MCP plugin sees them; leave it out for the MCP route, see McpConfig::isEndpoint()
```

### 7. The web server

The well-known paths must reach Cake. Many nginx configurations deny every
path that starts with a dot (`location ~ /\. { deny all; }`), which also blocks
`/.well-known/`. Add this before that rule:

```nginx
location ^~ /.well-known/oauth- {
    try_files $uri /index.php?$args;
}
```

Apache with Cake's `.htaccess` needs nothing.

## external mode

Use it when an identity provider you do not run issues the tokens. The plugin
validates JWT access tokens: the signature against the provider's published
keys, `iss`, `aud`, the expiry and the scopes. Install the two libraries it
needs:

```bash
composer require firebase/php-jwt psr/cache
```

Configure it:

```php
Configure::write('Mcp.auth', [
    'mode' => 'external',
    'external' => [
        'issuer' => 'https://login.example.com/',
    ],
    'scopes' => [
        'methods' => ['tools/call' => ['content:read']],
    ],
]);
```

The settings (all in [Configuration](configuration.md#mcpauth)):

- `issuer`: required, compared verbatim with the `iss` claim, so a trailing
  slash counts.
- `audience`: what `aud` must contain. The default is the resource URL
  (`Mcp.auth.resource`, or derived from `App.fullBaseUrl` and `Mcp.route`).
- `algorithms`: asymmetric algorithms only, default `['RS256']`.
- `scopeClaim`: the claim with the scopes, default `scope`.
- `tokenType`: a required `typ` header, `null` by default.
- `leeway`: tolerated clock skew in seconds, up to 300.
- `cache`: the Cake cache config for discovery metadata and keys.
- `httpClient`: a PSR-18 client, for proxies or tests.

The plugin fetches the issuer's discovery document and signing keys the first
time a token arrives, not at boot, and keeps them in the `mcp_oauth` cache
config (a file cache is created when you define none). To use Redis, define a
`mcp_oauth` cache config yourself. Nothing is fetched when you run
`bin/cake mcp check`.

### Provider notes

These are starting points. Providers change their defaults and their settings
differ per account: decode a real access token from your provider and check
its `iss`, `aud`, `typ` and scope claim against the values below before you
rely on them.

- **Auth0.** Issuer `https://YOUR_TENANT.auth0.com/` (with the trailing slash).
  Create an API in Auth0 and use its identifier as `audience`. Scopes are
  usually in the `scope` claim.
- **Keycloak.** Issuer `https://HOST/realms/REALM`. Add an audience mapper to
  the client scope so that `aud` carries the resource you set in `audience`.
- **Microsoft Entra ID.** Issuer `https://login.microsoftonline.com/TENANT_ID/v2.0`.
  Delegated scopes are usually in the `scp` claim, so set
  `'scopeClaim' => 'scp'`. The audience is typically the application ID of the
  API you registered.
- **WorkOS AuthKit.** The issuer is your AuthKit domain.

`bin/cake mcp check` prints the accepted audiences and warns when the resource
URL is not one of them. That is fine when your provider uses an API identifier
as `aud`, as long as you set `audience` to that identifier.

### Identity in external mode

Without `Mcp.auth.identity` the tools and the authorizer see an `ArrayObject`
with two keys:

```php
new ArrayObject(['id' => $sub, 'claims' => $allClaimsOfTheToken]);
```

The claims are never merged at the top level. That is deliberate: a `role`
claim set by the identity provider could otherwise pass as the role of your
application. As a result `RbacToolAuthorizer` sees no role and `cakedc/auth`
applies its `default_role`. A host that uses RBAC in external mode maps the
token to one of its own users with `Mcp.auth.identity`:

```php
use Mcp\Server\Authorization\AccessToken;

Configure::write('Mcp.auth.identity', function (AccessToken $token, mixed $identity): mixed {
    $email = $token->getClaims()['email'] ?? null;
    if (!is_string($email)) {
        return null;
    }
    $user = TableRegistry::getTableLocator()->get('Users')->find()
        ->where(['email' => $email, 'active' => true])
        ->first();

    return $user === null ? null : new Identity($user);
});
```

The callable receives the access token and the default identity, and returns
the identity to use. Returning `null` rejects the token with `401 invalid_token`,
so people unknown to your application cannot call tools. The same setting works
in `oauth` mode, where the default is the application identity of the token
owner.

## Scopes and tool authorization

`Mcp.auth.scopes` is the policy:

```php
'scopes' => [
    'default' => ['content:read'],                       // every request
    'methods' => ['tools/call' => ['content:write']],    // per JSON-RPC method
    'tools' => ['publish_post' => ['content:publish']],  // per tool, on top of the method
    'implies' => ['content:publish' => ['content:write']],
],
```

- A request needs the `default` scopes, the scopes of its method and, for
  `tools/call`, the scopes of that tool.
- `implies` makes a scope grant others, so a token with `content:publish` also
  satisfies `content:write`.
- A token that lacks scopes gets `403` with
  `WWW-Authenticate: Bearer error="insufficient_scope", scope="..."`, where
  `scope` lists the scopes the call needs. Clients use it to ask the person
  for more (step-up) instead of failing.
- `tools/list` still lists tools whose scopes the token lacks. The policy
  decides on calls.

The tool authorizer (`RbacToolAuthorizer` or yours, see
[Authorization](authorization.md)) still runs after the scope check, so a call
needs both the scope and the permission. In `oauth` mode the identity is the
application identity of the token owner, so the rules you already have keep
working.

### Which scopes the clients ask for

The metadata advertises as `scopes_supported` every scope the resource
declares in `oauth` mode (and, in `external` mode, every scope named in
`Mcp.auth.scopes`), and the `scope` of the `401` challenge lists the same.
MCP clients typically request all of them the first time they connect. If you
declare ten scopes on the resource, the person is asked to approve ten.

To keep to least privilege, declare only the scopes the MCP endpoint needs
on its resource (the scopes of other resources are not advertised here), or
declare a small default set and rely on step-up: the client asks for more only
when a call answers `insufficient_scope`.

## Connecting clients

The endpoint and the authorization server must be reachable over https from
wherever the client runs. For a server on your own machine use a tunnel when
the client is a hosted service.

### claude.ai

Add a custom connector (Settings, Connectors) with the URL of your endpoint,
for example `https://example.com/mcp`. The client has to reach it from the
internet. How a client registers with your authorization server (a client
metadata document or dynamic registration) is handled by the oauth plugin,
which supports both; see its documentation on clients for the switches.

### Claude Code

```bash
claude mcp add --transport http my-app https://example.com/mcp
```

Then run `/mcp` inside Claude Code and choose the server to sign in.

### MCP Inspector

```bash
bin/cake mcp inspector --http
```

Use the OAuth sign-in the Inspector offers when the server answers `401`.

### Consent on every connection

Redirect URIs that are loopback addresses or private schemes (the kind native
tools use, such as Claude Code or the MCP Inspector) are asked for consent on
every new connection. Refresh tokens keep an existing connection alive without
asking again. A client with an https redirect URI (claude.ai) is approved
silently once the person has consented. Redirect URIs with a private scheme are
refused unless the oauth server's `allowPrivateSchemes` is enabled; see the
oauth plugin's configuration.

## Subdirectory installs and proxies

The resource carries the base path, for example `https://example.com/app/mcp`,
but RFC 9728 puts the metadata at the host root:
`https://example.com/.well-known/oauth-protected-resource/app/mcp`. That is
outside the application, so the web server must send it to the application.
In nginx:

```nginx
location = /.well-known/oauth-protected-resource/app/mcp {
    rewrite ^ /app/.well-known/oauth-protected-resource/mcp last;
}
```

`bin/cake mcp check` warns about the mismatch (`Metadata route`) because the
path the application serves and the advertised one differ, as they do on a
subdirectory install. The warning is the reminder to add the rewrite.

Behind a proxy that terminates TLS, set `App.fullBaseUrl` to the public https
URL, or set `Mcp.auth.resource` explicitly. The resource must be the URL the
client types, not the internal one.

### Caching the metadata

The metadata answers `Cache-Control: public, max-age=300`. Cake's CSRF
middleware adds its cookie on GET requests even when your skip callback covers
the path (the callback is only consulted for state-changing methods), so the
response can carry a `Set-Cookie`. Keep shared caches from storing responses
with `Set-Cookie`: the defaults of nginx `proxy_cache`, Varnish and Cloudflare
already refuse them. Otherwise scope the CSRF middleware away from the
well-known path.

## Testing

In a Cake test case, `McpTestTrait` acts as a token with scopes without HTTP:

```php
$this->mcpActingAs($user, ['content:read']);
$this->mcpCallTool('publish_post', ['id' => 1]);
$this->assertMcpInsufficientScope(['content:read', 'content:publish']);

$this->mcpActingAs($user, ['content:publish']);
$this->mcpCallTool('publish_post', ['id' => 1]);
$this->assertMcpToolSucceeded();
```

See [Testing your tools](testing.md#scopes). To test the HTTP endpoint with
real tokens, use the oauth plugin's `OAuthTestTrait` (it issues personal
tokens, so `OAuthServer.personalTokens` must be enabled in the test
configuration) next to `IntegrationTestTrait`:

```php
use Cake\TestSuite\IntegrationTestTrait;
use CakeDC\OAuthServer\TestSuite\OAuthTestTrait;

class McpEndpointTest extends TestCase
{
    use IntegrationTestTrait;
    use OAuthTestTrait;

    public function testReaderCanListTools(): void
    {
        $token = $this->oauthTokenFor(1, ['content:read'], 'https://example.com/mcp');
        $this->configRequest([
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json, text/event-stream',
                'Content-Type' => 'application/json',
            ],
        ]);

        $this->post('/mcp', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

        $this->assertResponseOk();
    }
}
```

## Troubleshooting

**The client loops on sign-in.** Request the endpoint without a token and look
at the challenge, then fetch the metadata URL it names:

```bash
curl -i -X POST https://example.com/mcp
curl -i https://example.com/.well-known/oauth-protected-resource/mcp
```

The first answers `401` with a `resource_metadata` URL. The second answers `200`
JSON that lists your authorization server. If either is wrong, run
`bin/cake mcp check`.

**"issued for another resource" or `401 invalid_token`.** The resource URL
differs from the one the client used when it asked for the token. Compare
`App.fullBaseUrl`, the base path, the scheme (`http` or `https`) and a trailing
slash in the metadata, in `OAuthServer.resources` and in the URL the client
typed.

**The well-known path answers 403 or 404 from the web server.** The web server
is blocking dot paths or not sending them to `index.php`. See
[The web server](#7-the-web-server).

**403 `insufficient_scope`.** The token lacks scopes the call needs. The
`scope` of the `WWW-Authenticate` header lists them. A well behaved client asks
the person for more; otherwise reconnect and approve the scopes.

**Every token is rejected with "not valid at this address".** The oauth
plugin's `Token` authenticator is running on the MCP route and answers before
this plugin. Load it only when `!McpConfig::isEndpoint($request)`; see
[step 5](#5-keep-the-oauth-token-authenticator-off-the-mcp-route).
`bin/cake mcp check` fails the `Authenticators` check in this case.

**500 on `/mcp` after enabling OAuth.** The configuration is invalid (an unknown
`Mcp.auth` key, a scope the resource does not declare, a resource that is not
in `OAuthServer.resources`). The response is always the generic
`Internal error`; the reason is in the `mcp` log scope. Run
`bin/cake mcp check` to see it.

**403 "Invalid Host header".** DNS rebinding protection. The host of the
resource must be in `Mcp.http.allowedHosts`; by default only the host of
`App.fullBaseUrl` and localhost are. See [Troubleshooting](troubleshooting.md).
