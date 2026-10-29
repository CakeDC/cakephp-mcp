# Testing your tools

`CakeDC\Mcp\TestSuite\McpTestTrait` calls your tools from a regular Cake test
case, without HTTP. Each call builds your application (bootstrap and routes)
and its container, then the MCP server the same way the endpoint does: tool
discovery, constructor injection and your authorizer. So a test covers the
tool, its dependencies and who may call it.

```php
namespace App\Test\TestCase\Mcp;

use Authentication\Identity;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;

class ArticleToolsTest extends TestCase
{
    use McpTestTrait;

    protected array $fixtures = ['app.Articles', 'app.Users'];

    public function testEditorsCreateDrafts(): void
    {
        $this->mcpActingAs(new Identity($this->fetchTable('Users')->get(1)));

        $this->mcpCallTool('create_draft', ['title' => 'Hello', 'body' => 'First post']);

        $this->assertMcpToolSucceeded();
        $this->assertMcpResultContains('id');
    }

    public function testInvalidDraftsExplainWhatToFix(): void
    {
        $this->mcpActingAs(new Identity($this->fetchTable('Users')->get(1)));

        $this->mcpCallTool('create_draft', ['title' => '', 'body' => 'No title']);

        $this->assertMcpToolError('- title:');
    }

    public function testReadersCannotCreateDrafts(): void
    {
        $this->mcpActingAs(new Identity($this->fetchTable('Users')->get(2)));

        $this->assertMcpToolNotListed('create_draft');
        $this->mcpCallTool('create_draft', ['title' => 'Hello', 'body' => 'Nope']);
        $this->assertMcpToolDenied();
    }
}
```

## The identity

`mcpActingAs()` sets who makes the next calls. Pass it in the shape your
middleware produces: `cakephp/authentication` wraps the user in
`Authentication\Identity`, and `cakephp/authorization` wraps that in
`Authorization\IdentityDecorator`. A raw entity works for tools and authorizers
that only read fields, but not for code that calls `can()` or
`getOriginalData()`.

Tools see the identity through `CurrentIdentity`, and through the SDK access
token built as the endpoint builds it (identifier as subject, scopes from
`Mcp.identity.scopes`). Without `mcpActingAs()` the calls are anonymous.

### Scopes

`mcpActingAs($identity, $scopes = null, $claims = [])` also takes the scopes of
the access token and extra claims for it (they win over `sub`). With `$scopes`
given, and `Mcp.auth.mode` set to `oauth` or `external`, the `Mcp.auth.scopes`
policy is applied first, as the HTTP endpoint would, and `Mcp.identity.scopes`
is not used. In `oauth` mode the scopes are first expanded with the `implies`
of the resource in `OAuthServer.resources`, as the authorization server does
for the endpoint, so a token with `content:publish` also holds what that
scope implies there. A call the policy refuses is checked with
`assertMcpInsufficientScope()`:

```php
Configure::write('Mcp.auth.scopes.tools.publish_post', ['content:publish']);

$this->mcpActingAs($user, ['content:read']);
$this->mcpCallTool('publish_post', ['id' => 1]);
$this->assertMcpInsufficientScope(['content:publish']);
```

Pass the scopes the call needs to `assertMcpInsufficientScope()`, or nothing to
accept any. It is only meaningful with scopes given to `mcpActingAs()`; without
them, the policy is not applied. For the HTTP endpoint with real tokens see
[OAuth](oauth.md#testing).

Only tool calls get the `insufficient_scope` answer. When the policy refuses
any other request, such as the `tools/list` behind `mcpListTools()`,
`assertMcpToolListed()` and `assertMcpToolNotListed()`, the test fails with
`The access token lacks scopes for ...` instead of returning the `403` shape.

The trait does not go through your HTTP authentication, so it does not check
tokens or `Mcp.identity.required`. Cover that with an integration test of the
endpoint (`IntegrationTestTrait`, a `POST` to `/mcp` with your
`Authorization` header). Both traits can be used in the same test case.

## Calls and results

| Method | Does |
| --- | --- |
| `mcpCallTool($name, $arguments)` | Calls a tool and keeps its response. Returns the raw JSON-RPC response. |
| `mcpListTools()` | Returns the definitions of the tools the current identity sees. |
| `mcpResultText()` | The text of the last result, one line per text item. |
| `mcpResponse()` | The raw JSON-RPC response of the last call. |

## Assertions

| Assertion | Passes when the last call |
| --- | --- |
| `assertMcpToolSucceeded()` | returned a result that is not a tool error. On failure it shows what the server logged, including the exception of a tool that threw. |
| `assertMcpToolError(?$contains)` | returned a tool error, the kind the model reads (`ToolCallException`, `ToolError::invalid()`). |
| `assertMcpJsonRpcError(?$code, ?$contains)` | was answered with a JSON-RPC error: `-32602` for arguments that do not match the input schema, `-32603` for an unexpected exception. |
| `assertMcpToolDenied()` | was rejected by your authorizer. It also checks that the tool exists, because a denied tool is answered like an unknown one and a typo would pass otherwise. |
| `assertMcpInsufficientScope(?$scopes)` | was refused because the token lacks scopes, the way the endpoint answers `403 insufficient_scope`. With `$scopes` it also checks the scopes the call needs (any order). |
| `assertMcpResultContains($text)` | returned text containing `$text`. |
| `assertMcpStructuredContent($expected)` | returned this `structuredContent`. Key order does not matter, value types do. |

`assertMcpToolListed($name)` and `assertMcpToolNotListed($name)` check what the
current identity sees in `tools/list`. They make their own call.
`assertMcpToolNotListed()` also checks that the tool exists, for the same
reason as `assertMcpToolDenied()`.

## Replacing services

The trait uses Cake's `ContainerStubTrait`, so `mockService()` replaces a
service for the tools:

```php
$this->mockService(PaymentGateway::class, fn () => new FakePaymentGateway());
$this->mcpCallTool('refund_order', ['number' => 'A-1042']);
```

To build the server from another application class, call
`configApplication()` as you would for an integration test.
