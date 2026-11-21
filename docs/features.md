# Features

What the plugin offers, and where each part is described. The plugin runs on
the official MCP PHP SDK (`mcp/sdk`), so everything the SDK implements is
available too; those parts are listed at the end.

## Servers

- One endpoint at `Mcp.route`, or several servers in one application, each with
  its route, tools, authentication and authorizer:
  [Several servers](configuration.md#several-servers).
- Local clients over stdio (`bin/cake mcp server`): [stdio](stdio.md).
- Cache hints for the 2026-07-28 protocol revision (`Mcp.server.cache`):
  [Configuration](configuration.md#keys).
- Anything else the SDK builder offers, through `Mcp.server.configure`:
  [Using the SDK builder](configuration.md#using-the-sdk-builder).

## Tools, resources and prompts

- Declared with the SDK's attributes on plain classes in `src/Mcp/`, with
  constructor injection from Cake's container and the caller's identity:
  [Writing tools](tools.md).
- Input schemas from PHP types, docblocks and `#[Schema]`, with
  `Mcp.schema.normalize` for hosts that reject type arrays:
  [Input schemas](tools.md#input-schemas).
- Results as text, structured content and output schemas:
  [Results](tools.md#results).
- Errors the model can act on (`ToolError::message()`, `ToolError::invalid()`)
  and validation with a Cake `Validator` (`ToolValidation::check()`, and
  `checkPrompt()` for prompt arguments): [Errors](tools.md#errors).
- Progress and log notifications from long tools:
  [Progress and logs](tools.md#progress-and-logs).
- A searchable tool catalog (`find_tools`, `call_tools`) for servers with many
  tools: [Tool catalog](tools.md#tool-catalog).
- Registration on a condition, for configuration and feature flags
  (`#[McpWhen]`): [Registering only when](tools.md#registering-only-when).
- Generated classes and tests: `bin/cake bake mcp_tool`, `mcp_resource`,
  `mcp_prompt`, `mcp_app`: [Generating classes with bake](tools.md#generating-classes-with-bake).
- Resources, resource templates, prompts, completions and every content type,
  with who may read and get them: [Resources and prompts](resources-and-prompts.md).

## MCP Apps

Interactive pages hosts show next to the conversation, rendered from Cake
templates with the official client inlined, tools linked with `#[McpApp]`, and
app-only tools: [MCP Apps](apps.md).

## Security

- Authentication with the application's own login, with OAuth 2.1 sign-in
  through `cakedc/cakephp-oauth-server`, or with access tokens of an external
  authorization server: [Authentication](authentication.md), [OAuth](oauth.md).
- Scopes per method and per tool, answered with `403 insufficient_scope` and a
  challenge the client can act on: [Mcp.auth](configuration.md#mcpauth).
- An authorizer per tool, including `RbacToolAuthorizer` over
  `config/permissions.php`, with denied tools answered like unknown ones:
  [Authorization](authorization.md).
- Per-resource and per-prompt authorization (`ResourceAuthorizerInterface`,
  `PromptAuthorizerInterface`, `RbacToolAuthorizer` rules for `Resources` and
  `Prompts`): [Resources and prompts](authorization.md#resources-and-prompts).
- An audit log of calls, refusals included: [Audit log](configuration.md#audit-log).
- Rate limiting, allowed hosts and origins, a body size limit:
  [Rate limiting](configuration.md#rate-limiting), [Configuration](configuration.md).
- `bin/cake mcp check`, which reviews the whole setup without network calls:
  [Troubleshooting](troubleshooting.md).

## MCP client

Named clients that call other MCP servers, with an application credential (a
fixed token, or a callable for tokens that rotate) or with each user's own
account through OAuth (a Client ID Metadata Document or dynamic registration),
behind an SSRF guard for addresses remote servers name, through an outbound
proxy if needed, with a cache for list results that carry cache hints:
[MCP client](mcp-client.md).

## Testing

`McpTestTrait` calls tools, gets prompts, reads resources, asks for
completions and lists them all as a given identity, with scopes, on a given
server, and asserts results, structured content, errors, denials,
missing scopes and notifications; `bin/cake mcp inspector` opens the MCP
Inspector: [Testing your tools](testing.md).

## From the SDK

Also available, from `mcp/sdk`: resource templates, completions, tool and
resource annotations, icons and `_meta`, every content type (text, image,
audio, embedded resources, resource links), pagination, protocol revisions from
2024-11-05 to 2026-07-28 (with `server/discover`), elicitation, sampling,
logging and resource subscriptions.
