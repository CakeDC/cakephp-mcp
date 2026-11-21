# Changelog

## 2.2.0 (2026-11-20)

Behaviour changes a 2.1 application can notice, without changing its config or code:

- The tool registry is loaded when the server is built, before the caller is authenticated. A loader added in `Mcp.server.configure` that registers tools depending on who calls now sees no identity; decide per caller in an authorizer instead. (2.1 already did this with the audit log on.)
- `initialize` and `server/discover` announce exactly what the server has: a server with only tools no longer announces `prompts` or `resources`. With an event bus set in `Mcp.server.configure`, `tools.listChanged` is no longer announced.
- `McpTestTrait::mcpCallTool()` sends a progress token (`_meta.progressToken`) and a `logging/setLevel` of `debug` before the call, so a tool's notifications can be checked (see `docs/testing.md`).
- A cache policy set in `Mcp.server.configure` is sent private on `tools/list` when the authorizer is not `AllowAllToolAuthorizer`, even when it says `CacheScope::Public`: the list depends on who asks.
- With a registry set in `Mcp.server.configure`, the audit log asks that registry whether a tool exists: a call to a tool it does not have is recorded as `invalid`, where 2.1 could record `denied`.
- `McpConfig::isEndpoint()` reads the path as the router does, so `/%6Dcp` is the `/mcp` endpoint (2.1 answered false and the CSRF check refused it), and once routing has run it follows the route that matched: a route the application connects itself to the plugin's controller is an endpoint.
- A call whose authorizer throws while the audit records it is still recorded (with the outcome its error gives); 2.1 dropped that record.
- `bin/cake mcp check` can fail where 2.1 passed: on a tool whose `_meta.ui.resourceUri` points to a resource that is not registered as an app resource (with its MIME type), on such a tool when the MCP Apps extension is not enabled, and on a key under `Mcp.clients` that is not a client setting. Its `Authorizer` line warns about an Rbac rule for plugin `CakeDC/Mcp` with a controller the plugin does not have.

- Authorization of resources and prompts: an authorizer that also implements `ResourceAuthorizerInterface` (`canReadResource()`) or `PromptAuthorizerInterface` (`canGetPrompt()`) decides who reads which resource and gets which prompt. A denied one is left out of `resources/list`, `resources/templates/list` and `prompts/list`, reading, subscribing to, unsubscribing from, getting or completing it answers like an unknown one, a denied template also denies every URI it serves, a `subscriptions/listen` stream carries no update of a resource the caller cannot read, and with cache hints those lists and `resources/read` are sent private. `RbacToolAuthorizer` implements both, as controllers `Resources` (the URI as action) and `Prompts`, only once a rule names plugin `CakeDC/Mcp` and that controller, so 2.1 rule sets change nothing. The `mcp check` `Authorizer` line says whether resources and prompts are covered. See `docs/authorization.md`.
- `McpTestTrait` for prompts, resources and completions: `mcpGetPrompt()`, `mcpReadResource()`, `mcpComplete()`, `mcpListPrompts()`, `mcpListResources()`, `mcpListResourceTemplates()`, and `assertMcpPromptMessages()`, `assertMcpPromptContains()`, `assertMcpPromptError()`, `assertMcpPromptDenied()`, `assertMcpResourceText()`, `assertMcpResourceContains()`, `assertMcpResourceError()`, `assertMcpResourceDenied()`, `assertMcpCompletionValues()`, and the `Listed` and `NotListed` assertions for prompts, resources and templates. The tests `bake mcp_resource` and `bake mcp_prompt` write use them.
- `docs/resources-and-prompts.md`: resources, templates, prompts, completions and content types with examples, their authorization and testing.
- MCP client, Client ID Metadata Document: each user mode client serves one at `<Mcp.client.route>/<name>/client.json`, and `Mcp.clients.<name>.registration` (`auto` by default, `metadata`, `dynamic`) uses its URL as `client_id` when the authorization server says `client_id_metadata_document_supported` and `App.fullBaseUrl` is public https on a name that resolves to public addresses only (a development site such as `*.ddev.site`, or a `.test` name, registers dynamically); otherwise dynamic registration as before. A configured `clientId` always wins. `McpConfig::rbacPermissions()` lets anyone fetch the document. The `mcp check` `Clients` line says which registration each client uses.
- MCP client, list cache: `Mcp.clients.<name>.cache`, a Cake cache config name, keeps list results that carry 2026-07-28 cache hints for their ttl, `private` ones per user (user mode) or per client (app mode) and always per credential they were asked with (never served for another token, nor after a reconnection with another account), `public` ones per client; a `list_changed` notification drops them. Off by default. `listResourceTemplates()` is new on clients.
- MCP client: `Mcp.clients.<name>.token` may be a callable returning the token, called for each request (for tokens that rotate); `mcp check` does not call it.
- MCP client: `Mcp.client.proxy` sends every client request through an outbound proxy, the addresses remote servers name included; their name checks stay, the address pin is dropped because the proxy resolves the name. A configured `url` on this machine is reached directly, `NO_PROXY` is not followed, and the SOCKS scheme is `socks5h` (the proxy resolves names). `mcp check` shows it. Learned addresses under local names (`.localhost`, `.local`, `.internal`, `.home.arpa`...) are now refused.
- MCP client: `Mcp.clients.<name>.allowPrivateNetwork` lets the OAuth endpoints learned for that client resolve to private, loopback or link-local addresses (plain http also needs `allowInsecure`); other clients are not affected and `mcp check` warns.
- Config mistakes of `Mcp.servers` and `Mcp.clients` entries are logged as errors to the loggers without a scope (the application skeleton's `error` log) as well as the `mcp` scope, once per PHP process (once per request under PHP-FPM). The `mcp check` `Cache hints` line reads a policy set in `server.configure`.
- Named servers on routes with parameters (`'route' => '/mcp/t/{tenant}'`): one config and one set of tools for every value; tools read it with `$request->getParam()` or the injectable `CakeDC\Mcp\Server\McpRoute`; in `oauth` and `external` modes each value is its own resource with its own protected resource metadata URL, so a token for one value is refused for another (a configured resource or audience must hold every parameter, or the server is not served, the mistake is logged and `mcp check` fails; `mcp check` warns in host mode, which binds nothing to the value; tools must still check that the identity belongs to it); sessions are kept apart per value, audit records carry a `route` field, `mcp check` checks the pattern and `McpTestTrait::mcpUseServer($server, $values)` sets them in tests. Parameters are for named servers only: a default `Mcp.route` with one is not served (every request fails with the reason logged) and `mcp check` fails on it. See `docs/configuration.md`.
- Named servers with `'route' => false` are served over stdio only (`bin/cake mcp server --server <name>`), with no HTTP routes.
- `Mcp.server.icons`: icons in the server info.
- `find_tools` matches whole words in any language (a query in Spanish no longer matches single letters), also searches the names and descriptions of tool arguments, takes a query of at most 200 characters, and caps its result at 64 KiB of tools with `hasMore`.
- `McpTestTrait` with `Mcp.apps.enabled` on tells the server it renders apps, so `tools/list` and the `Listed` assertions see app-only tools as an MCP Apps host does.
- `McpTestTrait`: `assertMcpStructuredContentSubset()`, `assertMcpStructuredContentCount()`, `assertMcpStructuredContentType()`, `assertMcpCompletionContains()`, and `assertMcpPromptListedFor()`, `assertMcpPromptNotListedFor()`, `assertMcpResourceListedFor()`, `assertMcpResourceNotListedFor()`.
- An `Mcp-*` header that is not valid UTF-8 is answered with `400` and a JSON-RPC `-32600` error naming the header, instead of a `500`.
- Docs: the sandbox `domain` of an MCP App page is left to the host, and set from its documentation.
- MCP client: `ping()`, `serverInfo()`, `capabilities()`, `instructions()` and `protocolVersion()`; a `$limit` on the list calls; one retry, never more, of a request the server refused before running it (a header mismatch, `-32020`, after fetching every page of the tool list again; an expired session, on a new one); answers to the server's `ping`; sessions closed when the PHP process ends.
- MCP client: dynamic registration sends `application_type` (`web`, or `native` for a callback on this machine) and `scope`.
- MCP client: `'auth' => 'client_credentials'` gets the application's own OAuth token with `clientId` and `clientSecret` from the configured `issuer` or `tokenUrl`, keeps it in the connection store until it expires (and only for the issuer, token URL, client id and scopes it was asked with), and renews it once on a `401`.
- MCP client, scope step-up: a `403 insufficient_scope` marks the user's grant (`needsMoreScope()`, `stepUpScopes()`), and the next connect asks for the scopes already granted plus the challenged ones. The mark is kept in the `step_up_scope` column of the client tables migration. A step-up is tried once per scope: the grant keeps what its sign-in asked for (`requested_scope`), and a challenge for scopes already asked for marks nothing, so there is no redirect loop. Tables created by a pre-release of 2.2 lack that column: `mcp check` says so (roll back and migrate again).
- MCP client discovery: a server whose unauthenticated probe needs no sign-in is still discovered through its well-known URLs; a server of the 2025-03-26 revision without protected resource metadata uses its origin as the authorization server; a token answer without `scope` keeps the scopes asked for.
- The client side of the official MCP conformance suite runs in CI next to the server side (`tests/Conformance/run-client.sh`, no Docker needed), with its expected failures listed with their reasons. See `docs/conformance.md`.
- `ToolValidation::checkPrompt()`: the Validator check for the arguments of a prompt, reported as the error of `prompts/get`.
- `docs/features.md`: an overview of what the plugin offers, with a link to the page for each part.
- MCP client (`Mcp.clients`): call the tools, resources and prompts of other MCP servers with `McpClients::get('name')`, over HTTP with a token or headers, or a local server over stdio, with `tools.only` and `tools.except`, `timeout` and `protocolVersion` per client. User mode (`'auth' => 'user'`) calls a server on behalf of each user after an OAuth sign-in: discovery (RFC 9728, RFC 8414), dynamic client registration, PKCE, connect and callback routes, tokens stored encrypted in the plugin's tables (migration shipped), refreshed before expiry (one request at a time per user) and revoked on `disconnect()`. Addresses a remote server names go through a guard against server-side request forgery. A configured `clientSecret` with discovery needs `issuer`, and is only sent to that authorization server. `mcp check` reports clients on a new `Clients` line. See `docs/mcp-client.md`.
- MCP Apps (`Mcp.apps.enabled`): `AppView::render()` turns a Cake template into an app page, in a layout that inlines the official MCP Apps client (`@modelcontextprotocol/ext-apps` 2.0.4, vendored in `resources/ext-apps` with its license); `#[McpApp]` links a tool to its app; app-only tools are left out of `tools/list` for clients that do not render apps (only with `Mcp.apps.enabled` on; a 2.1 hook that enables the extension keeps working), tools with an app never go behind the tool catalog, and their audit records carry `app=1`, refused calls included; `mcp check` reports apps on a new `Apps` line; `bake mcp_app` creates an app. See `docs/apps.md`.
- Tool catalog (`Mcp.catalog`): chosen tools leave `tools/list` and are reached with `find_tools` and `call_tools`; every inner call goes through the authorizer, the tool scopes (a `403 insufficient_scope` over HTTP), the input schema and the audit (`via=call_tools`); a tool that asks the user for input ends its call with a tool error instead of making the client re-send the batch. `mcp check` reports it on a new `Catalog` line, and its `Tool schemas` line covers catalogued tools.
- `McpTestTrait` runs tools that report progress or log through the SDK's `ClientGateway` (they used to get no response in tests) and records their notifications: `mcpNotifications()`, `assertMcpProgressSent()`, `assertMcpNotificationSent()`. A tool that asks the client something fails with a clear message.
- Docs: progress and logs from a tool, and what buffers server-sent events (PHP output buffering, compression, proxies).
- `#[McpWhen]` registers a tool, resource, resource template or prompt only when a config value is on (the strings `"false"`, `"0"`, `"off"`, `"no"` and `""` are off) or a callback (given the server name) returns true; on a method it adds to the class's condition; `mcp check` lists what was skipped on a new `Conditions` line.
- `bin/cake mcp check` warns when `authorization.rbac` has rules but another authorizer is set, and when `auth.scopes` is set in host mode.
- Several MCP servers in one application (`Mcp.servers`): each with its route, metadata route, tools, auth and authorizer; security sections are replaced whole (a named server's `auth` needs `mode` and its `authorization` needs `authorizer`, and unknown keys fail), `identity` is never inherited, an inherited `auth` gets the named server's own resource, and the rest is inherited key by key. `mcp check` checks every server and fails on a shared route, resource or external audience; a `null` or empty security section and a server name that is not a slug fail for that server only (it is logged and its routes are not connected; the application, the other servers and `mcp check` keep working, and an invalid entry of `Mcp.clients` stops that client only); `mcp check`, `mcp server` and `mcp inspector` take `--server` (`mcp check --server` still runs the `Servers` line); `McpTestTrait::mcpUseServer()`.
- `bin/cake mcp check` reports the cache hints on a new `Cache hints` line: it says when `tools/list` is sent private because of the authorizer, and warns when `resources/read` is public.
- `Mcp.schema.normalize`, off by default: when on, `tools/list` drops `"null"` from the `type` and `enum` of optional parameters whose default is `null`, and that default, for hosts that reject type arrays. Arguments are still validated against the original schema. See Input schemas in `docs/tools.md`.
- `bin/cake mcp check` warns, on a new `Tool schemas` line, about type arrays, arrays without an item type and optional parameters without a description.
- `bin/cake mcp check` fails, on a new `Discovery` line, when `Mcp.discovery.scanDirs` is set and `symfony/finder` is missing: the SDK then skips discovery and the server has no tools.
- `Mcp.server.cache` sets the cache hints of the 2026-07-28 protocol (`ttl` and `scope`, sent as `ttlMs` and `cacheScope`), as a default and per method. Off by default (private, not cached). With an authorizer other than `AllowAllToolAuthorizer`, `tools/list` is always sent private. A named server drops the inherited hints with `'cache' => null` or `[]`.
- Bake commands `mcp_tool`, `mcp_resource` and `mcp_prompt` (with `cakephp/bake`) create an invokable class in `src/Mcp/` and its test.
- `McpTestTrait`: `assertMcpStructuredContentPath()` and `assertMcpStructuredContentHas()` check one value of `structuredContent` by dot path; `assertMcpToolListedFor()` and `assertMcpToolNotListedFor()` check what another identity sees in `tools/list`.
- `ToolValidation::check()` validates a tool's arguments with a Cake `Validator` and throws a tool error with one line per field.
- `ToolError::message()` builds the error a tool throws to send the model a message it can act on.
- Docs: Results in `docs/tools.md`: what a string, an array, a list and a `CallToolResult` become, `outputSchema` and `structuredContent`, and a compact JSON text for large results.
- Docs: Input schemas in `docs/tools.md` (`int[]` rather than `list<int>`, `#[Schema]`, optional strings), and why `symfony/finder` is required in `docs/installation.md`.

## 2.1.1 (2026-11-07)

- `bin/cake mcp check` lists the tools the server offers and warns when there is none.
- `bin/cake mcp check` checks the authorizer: with `RbacToolAuthorizer`, it warns when there are no rules, or none for plugin `CakeDC/Mcp` and controller `Tools`, because then no one can call a tool.
- `bin/cake mcp check` warns, in `oauth` and `external` modes, about tools that need no scope and about names in `Mcp.auth.scopes.tools` that are not tools.
- `bin/cake mcp check` warns when no Log engine takes the errors of the `mcp` scope (the skeleton's `debug` and `error` engines do not), so a failing tool would leave no trace.
- `bin/cake mcp check` decides whether the audit lines reach a Log engine as `Log::write()` does: an engine of the `mcp` scope, or one given as an object, now counts.
- `bin/cake mcp check` prints only its results: listing the tools no longer writes the SDK's info lines to the console (warnings and errors still go to the `mcp` log).
- Docs: testing the endpoint with `OAuthTestTrait` links to the oauth plugin's note on the migrations the test database needs.

## 2.1.0 (2026-11-07)

- Audit log of MCP calls (`Mcp.audit`), off by default: allowed, denied, failed and refused calls with the identity, client, tool, arguments and duration, to the `mcp_audit` log scope and the `Mcp.audit` event. See the Audit log section of `docs/configuration.md`, which also shows how to store the records in a table.
- `bin/cake mcp check` reports the audit log, and warns when it is on and no Log engine lists the `mcp_audit` scope.
- Fix: a `403 insufficient_scope` reached clients as `401`, because PHP turns any response with a `WWW-Authenticate` header into a 401 when, as in Cake's `ResponseEmitter`, the status line is sent first. The endpoint now keeps the 403 (see the troubleshooting guide).
- Docs: rate limiting the MCP route with CakePHP's `RateLimitMiddleware` (per token, per user or per address).
- Docs: the Logging section said the skeleton's `debug` and `error` engines receive the `mcp` messages; they have `'scopes' => null` and do not.
- Tests: the rate limiting example is skipped on CakePHP before 5.3, which has no `RateLimitMiddleware`.
- Docs: claude.ai custom connectors are now under Customize > Connectors > Add custom connector.

## 2.0.0 (2026-11-07)

Complete rewrite for CakePHP 5 on top of the official `mcp/sdk`, with OAuth 2.1 for remote MCP clients. Version
1.0.0 on Packagist was this same code tagged by mistake: require `^2.0`. The 1.x line (`1.next-cake4`) is the
CakePHP 4 plugin.

### Server

- Remote MCP over Streamable HTTP at a Cake route (`/mcp` by default), serving both protocol eras (handshake with sessions, and the stateless 2026-07-28 revision).
- `bin/cake mcp server` for stdio.
- `bin/cake mcp inspector` opens the MCP Inspector already configured for this server, over stdio or HTTP.
- Tool discovery through `#[McpTool]`, `#[McpResource]` and `#[McpPrompt]` attributes in `src/Mcp/`, with constructor injection from Cake's container (autowired).
- Sessions and the discovery cache stored in Cake cache configs.
- Authentication delegated to the host: the identity set by `cakephp/authentication` becomes the SDK access token and the injectable `CurrentIdentity`.
- Per-tool authorization with `ToolAuthorizerInterface`; `RbacToolAuthorizer` for `cakedc/auth` permission rules.
- `server.title`, `server.description` and `server.websiteUrl` config keys, reported in the server info.
- `Mcp.server.configure` hands the SDK builder to the host for anything the plugin does not configure itself; the plugin's own settings and the authorizer still apply.
- `McpTestTrait` calls the application's tools in memory from a Cake test case, acting as any identity, with assertions for results, tool errors, JSON-RPC errors, denials and `tools/list`.
- `ToolError::invalid()` turns Cake validation errors (an entity or a `Validator` result) into a tool error the model can read and act on.
- Checked against the official MCP conformance suite for both protocol revisions, behind nginx and php-fpm (`tests/Conformance/run.sh`, also in CI).
- DNS-rebinding protection defaults to the `App.fullBaseUrl` host. That only protects when `App.fullBaseUrl` is set explicitly (the Cake 5 skeleton derives it from the request `Host` otherwise); set `Mcp.http.allowedHosts`, which is required for servers with `identity.required` set to `false`.
- Removed `bin/mcp-server` and `CakeDC\Mcp\Server\CakeServer`.
- Requires `mcp/sdk` ^0.9.

### OAuth 2.1 for remote clients

- `Mcp.auth` with three modes: `host` (default, as 2.0), `oauth` (access tokens of `cakedc/cakephp-oauth-server` in the same application) and `external` (JWT access tokens of Auth0, Keycloak, Microsoft Entra ID, WorkOS or any OpenID Connect issuer, through `firebase/php-jwt` and `psr/cache`). The `Mcp.auth` section is validated strictly and fails closed.
- Protected resource metadata (RFC 9728) at `/.well-known/oauth-protected-resource` plus the MCP route, and the `401` challenge that points MCP clients to it.
- Scope policy per method and per tool (`Mcp.auth.scopes`), with `implies` and step-up through `403 insufficient_scope`.
- `McpConfig::isEndpoint()` recognises the MCP route and its metadata path, for the CSRF skip callback and to keep other bearer-token authenticators off them.
- `bin/cake mcp check` inspects the endpoint configuration (authentication, resource, metadata route, allowed hosts, CSRF, authenticators, including the `Auth.Authenticators` of `cakedc/users`) without network calls.
- `McpTestTrait::mcpActingAs()` takes scopes and claims, and `assertMcpInsufficientScope()` asserts a refused call. In `oauth` mode the scopes are expanded with the `implies` of the declared resource, as the authorization server does.
- `McpConfig::rbacPermissions()` returns the `bypassAuth` rule `cakedc/users` hosts need for the endpoint and its metadata.
- oauth mode needs `cakedc/cakephp-oauth-server` 1.0.0 or newer (it brings `cakedc/oauth-server`), CakePHP 5.1.10+ and `cakephp/authentication` 3.3.6+.
- `Mcp.http.maxBodyBytes` (default 4 MiB, the SDK's own limit) bounds the request body. The same value is passed to the SDK transport and to its `AuthorizationMiddleware`.
- A POST over that limit is answered with `413` and a JSON-RPC error (code `-32600`) whose message and `data.maxBodyBytes` name the limit and suggest a smaller payload, before authentication. Before, `oauth` and `external` modes answered it with a `400` Bearer `invalid_request` challenge.
- `bin/cake mcp check` understands a Token authenticator of `cakedc/cakephp-oauth-server` 1.0.0 that protects several resources (`OAuthServer.users.resource` as a list), and passes when one of them is the MCP endpoint at the MCP route.

### Contributors

- Contributors: the PHPUnit suite now runs on SQLite with the oauth packages. It needs the sibling clones `../oauth-server` and `../cakephp-oauth-server` (path repositories); both are public repositories, and CI checks them out at their `1.0.0` tags.
- Contributors: `tests/Conformance/run.sh` (and so CI) runs the newest published alpha of the official conformance suite instead of a pinned version; `CONFORMANCE_PACKAGE` still pins one for a reproducible run.
