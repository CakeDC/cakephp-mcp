# Testing your tools

`CakeDC\Mcp\TestSuite\McpTestTrait` calls your tools from a regular Cake test
case, without HTTP. Each call builds your application and its container, then
the MCP server the same way the endpoint does: tool discovery, constructor
injection and your authorizer. So a test covers the tool, its dependencies and
who may call it.

```php
namespace App\Test\TestCase\Mcp;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\TestSuite\McpTestTrait;

class ArticleToolsTest extends TestCase
{
    use McpTestTrait;

    protected array $fixtures = ['app.Articles', 'app.Users'];

    public function testEditorsCreateDrafts(): void
    {
        $editor = $this->fetchTable('Users')->get(1);
        $this->mcpActingAs($editor);

        $this->mcpCallTool('create_draft', ['title' => 'Hello', 'body' => 'First post']);

        $this->assertMcpToolSucceeded();
        $this->assertMcpResultContains('id');
    }

    public function testInvalidDraftsExplainWhatToFix(): void
    {
        $this->mcpActingAs($this->fetchTable('Users')->get(1));

        $this->mcpCallTool('create_draft', ['title' => '', 'body' => 'No title']);

        $this->assertMcpToolError('- title:');
    }

    public function testReadersCannotCreateDrafts(): void
    {
        $this->mcpActingAs($this->fetchTable('Users')->get(2));

        $this->assertMcpToolNotListed('create_draft');
        $this->mcpCallTool('create_draft', ['title' => 'Hello', 'body' => 'Nope']);
        $this->assertMcpToolDenied();
    }
}
```

## The identity

`mcpActingAs()` sets who makes the next calls, in the shape your
authentication produces: an entity, an `ArrayObject`, an array. Tools see it
through `CurrentIdentity`, and through the SDK access token built as the
endpoint builds it (identifier as subject, scopes from `Mcp.identity.scopes`).
Without `mcpActingAs()` the calls are anonymous.

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
| `assertMcpToolSucceeded()` | returned a result that is not a tool error. |
| `assertMcpToolError(?$contains)` | returned a tool error, the kind the model reads (`ToolCallException`, `ToolError::invalid()`). |
| `assertMcpJsonRpcError(?$code, ?$contains)` | was answered with a JSON-RPC error: `-32602` for arguments that do not match the input schema, `-32603` for an unexpected exception. |
| `assertMcpToolDenied()` | was rejected by your authorizer. It also checks that the tool exists, because a denied tool is answered like an unknown one and a typo would pass otherwise. |
| `assertMcpResultContains($text)` | returned text containing `$text`. |
| `assertMcpStructuredContent($expected)` | returned this `structuredContent`. Key order does not matter, value types do. |

`assertMcpToolListed($name)` and `assertMcpToolNotListed($name)` check what the
current identity sees in `tools/list`. They make their own call.

## Replacing services

The trait uses Cake's `ContainerStubTrait`, so `mockService()` replaces a
service for the tools:

```php
$this->mockService(PaymentGateway::class, fn () => new FakePaymentGateway());
$this->mcpCallTool('refund_order', ['number' => 'A-1042']);
```

To build the server from another application class, call
`configApplication()` as you would for an integration test.
