# Changelog

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
