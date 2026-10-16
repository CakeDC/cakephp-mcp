# Changelog

## 2.1.0-beta1 (unreleased)

OAuth 2.1 for remote MCP clients. Existing applications keep working: `host` mode behaves as 2.0.

- `Mcp.auth` with three modes: `host` (default, as 2.0), `oauth` (access tokens of `cakedc/cakephp-oauth-server` in the same application) and `external` (JWT access tokens of Auth0, Keycloak, Microsoft Entra ID, WorkOS or any OpenID Connect issuer, through `firebase/php-jwt` and `psr/cache`). The `Mcp.auth` section is validated strictly and fails closed.
- Protected resource metadata (RFC 9728) at `/.well-known/oauth-protected-resource` plus the MCP route, and the `401` challenge that points MCP clients to it.
- Scope policy per method and per tool (`Mcp.auth.scopes`), with `implies` and step-up through `403 insufficient_scope`.
- `McpConfig::isEndpoint()` recognises the MCP route and its metadata path, for the CSRF skip callback and to keep other bearer-token authenticators off them.
- `bin/cake mcp check` inspects the endpoint configuration (authentication, resource, metadata route, allowed hosts, CSRF, authenticators, including the `Auth.Authenticators` of `cakedc/users`) without network calls.
- `McpTestTrait::mcpActingAs()` takes scopes and claims, and `assertMcpInsufficientScope()` asserts a refused call. In `oauth` mode the scopes are expanded with the `implies` of the declared resource, as the authorization server does.
- `McpConfig::rbacPermissions()` returns the `bypassAuth` rule `cakedc/users` hosts need for the endpoint and its metadata.
- oauth mode needs `cakedc/cakephp-oauth-server` and `cakedc/oauth-server` 1.0 (beta, public, not on Packagist yet: see the install notes), CakePHP 5.1.10+ and `cakephp/authentication` 3.3.6+.
- Contributors: the PHPUnit suite now runs on SQLite with the oauth packages. It needs the sibling clones `../oauth-server` and `../cakephp-oauth-server` (path repositories); both are public repositories, and CI checks them out at their `1.0.0-beta1` tags.

## 2.0.0-beta1 (unreleased)

Complete rewrite for CakePHP 5 on top of the official `mcp/sdk`.

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
- Temporary: `mcp/sdk` is pinned to a fixed commit aliased as 0.9.0 until the SDK tags that release; host apps must add the same requirement and allow dev stability (see the install notes).
