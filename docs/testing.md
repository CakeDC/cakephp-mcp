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
| `assertMcpStructuredContentPath($path, $expected)` | returned `$expected` at a dot path of its `structuredContent` (`order.lines.0.sku`). Value types matter, and a missing path fails. `$expected` may be a closure that gets the value: it passes when the closure returns `true`, and is not called for a missing path. |
| `assertMcpStructuredContentHas($path)` | returned a `structuredContent` with that dot path, even with a `null` value. |
| `assertMcpStructuredContentMissing($paths)` | returned a `structuredContent` without that dot path, or without any of a list of them. A key with a `null` value is there, so it fails. |
| `assertMcpStructuredContentSubset($expected)` | returned a `structuredContent` that holds `$expected`: each key of an object with the same value, other keys allowed, lists compared whole, starting at the top of the structured content. Value types matter; the order of an object's keys never does, here or in the other structured content assertions. |
| `assertMcpStructuredContentNotSubset($fragment)` | returned a `structuredContent` that does not hold `$fragment`, by the same rule: it fails exactly when `assertMcpStructuredContentSubset($fragment)` would pass. |
| `assertMcpStructuredContentLacks($pairs)` | returned a `structuredContent` where none of the key and value pairs of `$pairs` appears in any object, at any depth. |
| `assertMcpStructuredContentIgnoringOrder($expected, $path = '')` | returned `$expected` (at a dot path, or as the whole structured content) once the order of list items is ignored at every level. Value types matter and duplicates count; a missing path fails. |
| `assertMcpStructuredContentKeys($shape, $exact = false)` | returned the keys `$shape` describes, values not looked at: `['order' => ['id', 'lines' => ['*' => ['sku']]], 'total']`, where `'*'` is every item of a list (an empty list fails). With `$exact`, a described level has no other keys. |
| `assertMcpStructuredContentCount($path, $count)` | returned a list or object with `$count` items at that dot path (`''` for the whole structured content). |
| `assertMcpStructuredContentType($path, $type)` | returned a value of that JSON type at that dot path: `string`, `number` (integers count), `integer`, `boolean`, `array`, `object` or `null`. |
| `assertMcpProgressSent(?$count)` | reported progress (`notifications/progress`): at least once, or exactly `$count` times. |
| `assertMcpNotificationSent($method)` | sent a notification of `$method`, for example `notifications/message` for a log line. |

`mcpCallTool()` asks for progress (it sends a `progressToken`) and the test
session sets the log level to `debug`, so a tool that calls
`$client->progress()` or `$client->log()` on its `Mcp\Server\ClientGateway`
runs to the end and its notifications are kept: `mcpNotifications()` returns
them, all or those of one method. A tool that asks the client something
(`$client->sample()`, `$client->elicit()`) cannot be answered in a test, and
the call fails with a message saying so.

`assertMcpToolListed($name)` and `assertMcpToolNotListed($name)` check what the
current identity sees in `tools/list`. They make their own call.
`assertMcpToolNotListed()` also checks that the tool exists, for the same
reason as `assertMcpToolDenied()`. With `Mcp.apps.enabled` on, the trait tells
the server it renders apps, as an MCP Apps host does, so app-only tools are
listed to it unless the authorizer hides them.

`assertMcpToolListedFor($identity, $name)` and
`assertMcpToolNotListedFor($identity, $name)` do the same for another identity,
with default scopes, and leave the test's own identity, scopes and claims as
they were:

```php
$this->assertMcpToolListedFor(['id' => 1, 'role' => 'admin'], 'refund_order');
$this->assertMcpToolNotListedFor(['id' => 2, 'role' => 'user'], 'refund_order');
```

## Prompts, resources and completions

The same trait gets prompts, reads resources and asks for completions, through
the server as the current identity, scopes and server, like `mcpCallTool()`:

```php
public function testTheGreetingPrompt(): void
{
    $this->mcpActingAs(['id' => 1]);

    $this->mcpGetPrompt('greet', ['name' => 'Ada']);

    $this->assertMcpPromptMessages([
        ['role' => 'user', 'text' => 'Say hello to Ada.'],
        ['role' => 'assistant', 'text' => 'Hello, Ada!'],
    ]);
}

public function testAnOrderReadsThroughItsTemplate(): void
{
    $this->mcpReadResource('orders://1002');

    $this->assertMcpResourceText('Order 1002', 'text/plain');
}

public function testOrderNumbersComplete(): void
{
    $this->mcpComplete(['type' => 'ref/resource', 'uri' => 'orders://{number}'], 'number', '10');

    $this->assertMcpCompletionValues(['1001', '1002']);
}
```

| Method | Does |
| --- | --- |
| `mcpGetPrompt($name, $arguments)` | Gets a prompt and keeps the response. |
| `mcpReadResource($uri)` | Reads a resource, also a URI a resource template matches, and keeps the response. |
| `mcpComplete($ref, $argument, $value)` | Asks for the completions of a prompt argument (`['type' => 'ref/prompt', 'name' => 'greet']`) or of a template variable (`['type' => 'ref/resource', 'uri' => 'orders://{number}']`) and keeps the response. |
| `mcpListPrompts()`, `mcpListResources()`, `mcpListResourceTemplates()` | Return what the current identity sees in `prompts/list`, `resources/list` and `resources/templates/list`, every page. |

| Assertion | Passes when |
| --- | --- |
| `assertMcpPromptMessages($expected)` | the last prompt returned these messages, in order, each as `['role' => ..., 'text' => ...]` (`text` is null for a message that is not text). |
| `assertMcpPromptContains($text)` | a text message of the last prompt contains `$text`. |
| `assertMcpPromptError(?$contains)` | the last prompt was answered with a JSON-RPC error: an unknown prompt, a missing argument, a prompt that failed. |
| `assertMcpResourceText($text, ?$mimeType)` | a content of the last resource read has exactly this text, and this MIME type when given. |
| `assertMcpResourceContains($text)` | a text content of the last resource read contains `$text`. |
| `assertMcpResourceError(?$contains)` | the last resource read was answered with a JSON-RPC error: an unknown URI or a resource that failed. The SDK sends `Error while reading resource` for an exception, not its message. |
| `assertMcpCompletionValues($values)` | the last completion returned these values, in order. |
| `assertMcpCompletionContains($value)` | the values of the last completion include `$value`. |
| `assertMcpPromptListed($name)`, `assertMcpPromptNotListed($name)` | the current identity sees, or does not see, the prompt in `prompts/list`. |
| `assertMcpResourceListed($uri)`, `assertMcpResourceNotListed($uri)` | the same for a resource in `resources/list`. |
| `assertMcpResourceTemplateListed($uriTemplate)`, `assertMcpResourceTemplateNotListed($uriTemplate)` | the same for a template in `resources/templates/list`. |

`assertMcpPromptListedFor($identity, $name)`, `assertMcpPromptNotListedFor()`,
`assertMcpResourceListedFor($identity, $uri)` and
`assertMcpResourceNotListedFor()` check what another identity sees, like
`assertMcpToolListedFor()`, and leave the test's own identity as it was.
`assertMcpPromptDenied()` and `assertMcpResourceDenied()` check that the last
get or read was refused by your authorizer (see
[Authorization](authorization.md#resources-and-prompts)).

The `NotListed` assertions also check that the prompt, resource or template
exists, so a typo cannot pass as hidden. `mcpResponse()` and
`assertMcpJsonRpcError()` work on the last of all these calls. The tests that
`bin/cake bake mcp_resource` and `bin/cake bake mcp_prompt` write use these
helpers.

## Several servers

With `Mcp.servers`, the trait talks to the `default` server. Switch with
`mcpUseServer()`:

```php
$this->mcpUseServer('admin');
$this->assertMcpToolListed('refund_order');
```

## Replacing services

The trait uses Cake's `ContainerStubTrait`, so `mockService()` replaces a
service for the tools:

```php
$this->mockService(PaymentGateway::class, fn () => new FakePaymentGateway());
$this->mcpCallTool('refund_order', ['number' => 'A-1042']);
```

To build the server from another application class, call
`configApplication()` as you would for an integration test.
