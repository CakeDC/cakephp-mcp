# Writing tools

A tool is a method of a plain PHP class, marked with an attribute from the
official SDK. The plugin scans the directories in `Mcp.discovery.scanDirs`
(`src/Mcp` by default) and registers what it finds.

## Attributes

```php
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
```

- `#[McpTool]` exposes a method clients can call.
- `#[McpResource]` exposes read-only content addressed by a URI.
- `#[McpPrompt]` exposes a reusable prompt template.

The description comes from the method's docblock. The input schema is built
from the parameter types, and `@param` lines become the parameter descriptions.

## Input schemas

Clients show the input schema to the model, and some hosts reject schemas they
find ambiguous. A few habits keep it clean:

- Describe every parameter in a `@param` line. A parameter without a
  description leaves the model guessing.
- Write arrays as `int[]` or `array<int>` in the docblock. The SDK does not
  resolve `list<int>` yet, so that form gives `"items": {}`, an array of
  anything.
- Use `#[Schema]` (`Mcp\Capability\Attribute\Schema`) on a parameter for what a
  PHP type cannot say: allowed values, a format, a range, or the type of the
  items of an array.
- Give an optional parameter a default the schema accepts, because the model may
  send it back. A free-text string can default to `''`. A string with a format
  or allowed values cannot: `''` is not a date, so a model that sends it gets an
  error. Make it nullable instead (`?string $from = null`) and say in its
  description what leaving it out does, or default to one of the allowed values.
- The nullable form gives `"type": ["null", "string"]`, a type array that some
  hosts (Gemini-based ones, for example) reject. Set `Mcp.schema.normalize` to
  `true` (see [Configuration](configuration.md#keys)) to advertise those
  parameters as plain `"string"` without a default: the model leaves them out,
  and a client that still sends `null` keeps working.

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Orders in a date range.
 *
 * @param string|null $from First day, YYYY-MM-DD. Leave it out for 30 days ago.
 * @param string $status Order status.
 * @param int[] $facilities Facility ids. Leave it out for all.
 */
#[McpTool(name: 'list_orders')]
public function list(
    #[Schema(format: 'date')] ?string $from = null,
    #[Schema(enum: ['open', 'closed'])] string $status = 'open',
    array $facilities = [],
): array {
    // ...
}
```

`Mcp.schema.normalize` only changes optional parameters whose default is
`null`: it removes `null` from their types. A required nullable parameter, a
nullable one with another default (`?string $status = 'open'`, where `null` is a
value of its own) and a wider union (`int|string`) keep their type arrays.
`bin/cake mcp check` lists what is left: type arrays, untyped arrays and
parameters without a description.

## Results

What a tool returns decides what the client receives:

| The tool returns | The client receives |
|---|---|
| a string | that text |
| an associative array | the array as pretty-printed JSON text, and the same array as `structuredContent` |
| a list (`['A-1', 'A-2']`) | the list as pretty-printed JSON text. Clients of the 2026-07-28 protocol revision also get it as `structuredContent` (a JSON array); clients of the 2025 revisions do not, since that field had to be an object there |
| a `Mcp\Schema\Result\CallToolResult` | exactly its content and `structuredContent` |

An associative array is the quickest way to return data:

```php
namespace App\Mcp;

use Cake\ORM\Locator\LocatorAwareTrait;
use Mcp\Capability\Attribute\McpTool;

class OrderTools
{
    use LocatorAwareTrait;

    /**
     * Finds an order by its number.
     *
     * @param string $number The order number, for example "A-1042".
     * @return array{number: string, status: string, total: float}
     */
    #[McpTool(name: 'find_order')]
    public function find(string $number): array
    {
        $order = $this->fetchTable('Orders')->find()->where(['number' => $number])->firstOrFail();

        return [
            'number' => $order->number,
            'status' => $order->status,
            'total' => (float)$order->total,
        ];
    }
}
```

The array travels twice, as text and as `structuredContent` (a list too, for
clients of the 2026-07-28 revision), and the model may read both. That is fine for a small result. For a large one, return a single
compact JSON text instead, so the model reads it once:

```php
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

return new CallToolResult([
    new TextContent(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
]);
```

### Output schemas

When a client parses the result (an agent framework, a script, a client that
shows it as a table), declare its shape with `outputSchema` and return a
`CallToolResult` with a short text for the model and the data as
`structuredContent`:

```php
#[McpTool(name: 'order_summary', outputSchema: [
    'type' => 'object',
    'properties' => ['total' => ['type' => 'number'], 'count' => ['type' => 'integer']],
    'required' => ['total'],
])]
public function summary(): CallToolResult
{
    return new CallToolResult(
        [new TextContent('2 open orders, 25.50 in total.')],
        false,
        ['total' => 25.5, 'count' => 2],
    );
}
```

`tools/list` shows the `outputSchema`, and the SDK checks the
`structuredContent` against it: a result that breaks it reaches the client as a
tool error ("Invalid structured output for tool ..."), not as data. Test the
tool's result with `assertMcpStructuredContent()` (see [Testing](testing.md)).

## Progress and logs

A long tool can tell the client how far it got. Take the SDK's
`Mcp\Server\ClientGateway` as a parameter (the SDK fills it in, it is not
part of the input schema) and call `progress()` as the work advances:

```php
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Server\ClientGateway;

#[McpTool(name: 'import_orders')]
public function import(ClientGateway $client, string $file): string
{
    $rows = $this->reader->rows($file);
    foreach (array_chunk($rows, 100) as $i => $chunk) {
        $this->importer->save($chunk);
        $client->progress(($i + 1) * 100, count($rows), 'Saved ' . (($i + 1) * 100) . ' orders');
    }
    $client->log(LoggingLevel::Info, sprintf('Imported %d orders from %s', count($rows), $file));

    return sprintf('Imported %d orders.', count($rows));
}
```

Progress is sent only when the client asked for it (its call carries a
`progressToken`); otherwise `progress()` does nothing. Log lines are sent at
the level the client chose with `logging/setLevel`. Over HTTP the endpoint then
answers with server-sent events: the notifications as they happen, then the
result. If they arrive all at once at the end, something buffers the response
(see [Troubleshooting](troubleshooting.md#server-sent-events-stall-behind-nginx)).
In tests, `assertMcpProgressSent()` and `assertMcpNotificationSent()` check
them (see [Testing](testing.md)).

`ClientGateway` can also ask the client's model (`sample()`) or the user
(`elicit()`) something. Those need a client that supports them, and they
cannot be answered in `McpTestTrait` tests.

## Tool catalog

A server with many tools sends every schema to the model on every turn. The tool
catalog keeps chosen tools out of `tools/list` and gives the model two tools to
reach them instead:

```php
'Mcp' => [
    'catalog' => [
        'enabled' => true,
        'tools' => ['report_*', 'export_orders'],   // names or fnmatch() patterns
        'maxCalls' => 5,                             // tools one call_tools may run
    ],
],
```

- `find_tools` takes a `query` (words to look for) and a `limit` (1 to 50,
  default 10), and returns the matching tools with their descriptions and input
  schemas, best matches first. It only returns tools the caller may call.
- `call_tools` takes `calls`, a list of `{"name": ..., "arguments": {...}}`, runs
  them in order and returns each result. It stops at the first error.

Each tool `call_tools` runs is checked like a direct `tools/call`: the
authorizer (a denied tool answers like an unknown one), the scopes of
`Mcp.auth.scopes.tools`, and the tool's input schema. Over HTTP, a `call_tools`
whose token lacks the scope of a tool it runs gets the same `403
insufficient_scope` as a direct call, naming the missing scopes, so the client
can ask for them. Each inner call writes its own audit record under its own
name, with `via=call_tools`. `find_tools` and `call_tools` themselves are never
refused by the authorizer: what they run is.

Hosts ask the user before running a tool they do not know to be safe, and an
"always allow" for `call_tools` covers every tool in the catalog. `find_tools`
returns each tool's annotations (`readOnlyHint`, `destructiveHint`) so the
model knows what it is about to run, but keep tools that change or delete data
out of the catalog unless that blanket approval is acceptable. A tool run by
`call_tools` does not get the caller's progress token, and the audit record of
the `call_tools` call itself names the failing tool, never the output of the
tools it ran.

A tool that asks the user for input (an elicitation through `ClientGateway`,
or a returned `InputRequiredResult`) cannot finish inside `call_tools` in the
2026-07-28 revision: the client would answer by sending the whole batch again,
and the tools before it would run a second time. So that call ends with the tool
error `<tool> needs input from the user: call it on its own, not through
call_tools.`, the results of the calls before it are kept, and its audit record
carries its name. Keep such tools out of the catalog, or let the model call
them directly.

Catalogued tools can still be called directly by name, so clients that already
know them keep working. `bin/cake mcp check` lists the catalogued tools on its
`Catalog` line, warns about patterns that match no tool, and checks their
schemas on its `Tool schemas` line.

## Registering only when

`#[McpWhen]` (`CakeDC\Mcp\Attribute\McpWhen`) registers a tool, resource,
resource template or prompt only when a condition holds. Put it next to the
SDK attribute, on the method or on an invokable class:

```php
use CakeDC\Mcp\Attribute\McpWhen;

#[McpWhen(config: 'Features.reports')]
#[McpTool(name: 'monthly_report')]
public function report(): array
{
    // ...
}

#[McpWhen(callback: [Flags::class, 'beta'])]
#[McpTool(name: 'beta_search')]
class BetaSearchTool
{
    // ...
}
```

With `config`, the element exists when that Configure value is truthy. With
`callback`, a static method decides; it receives the name of the server being
built (see [Several servers](configuration.md#several-servers)). The condition is
read every time the server is built, so a config change applies on the next
request. A skipped element does not exist for clients: it is not listed, and a
call to it answers like a call to an unknown tool. `bin/cake mcp check` lists
what was skipped on its `Conditions` line.

Use it for configuration and feature flags. What depends on who is calling
belongs in the authorizer (see [Authorization](authorization.md)).

## Generating classes with bake

With `cakephp/bake` installed, three bake commands create a class in
`src/Mcp/` and its test in `tests/TestCase/Mcp/`:

```bash
bin/cake bake mcp_tool ListOrders        # src/Mcp/ListOrdersTool.php, tool list_orders
bin/cake bake mcp_resource OrderGuide    # src/Mcp/OrderGuideResource.php, app://order-guide
bin/cake bake mcp_prompt WeeklyReport    # src/Mcp/WeeklyReportPrompt.php, prompt weekly_report
bin/cake bake mcp_app Inbox              # src/Mcp/InboxApp.php and templates/Mcp/inbox.php, see MCP Apps
```

Each class is invokable, with the attribute on the class and the work in
`__invoke()`, one class per tool. `mcp_app` creates an [MCP App](apps.md): a
class with the page resource and its tool, and the page template. The generated tool is read-only, has every
parameter described and passes the `Tool schemas` line of `bin/cake mcp check`.
Replace the placeholder description: the model reads it to decide when to call
the tool. `--plugin Shop` bakes into a plugin, `--no-test` skips the test, and
`--force` overwrites an existing file.

## Annotations

Annotations tell the client how a tool behaves. Clients such as Claude use them
to decide when to ask the user before running a tool, so mark the tools that
only read as read-only and the ones that delete or overwrite as destructive:

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

#[McpTool(name: 'find_order', annotations: new ToolAnnotations(readOnlyHint: true))]
public function find(string $number): array

#[McpTool(name: 'cancel_order', annotations: new ToolAnnotations(destructiveHint: true, idempotentHint: true))]
public function cancel(string $number): string
```

| Hint | Meaning |
| --- | --- |
| `title` | A human-readable name for the tool. |
| `readOnlyHint` | The tool does not change anything. |
| `destructiveHint` | The tool may delete or overwrite data. Only meaningful when it is not read-only. |
| `idempotentHint` | Calling it again with the same arguments changes nothing more. Only meaningful when it is not read-only. |
| `openWorldHint` | The tool reaches systems outside your application, such as a third-party API. |

A hint you leave out means the worst case: not read-only, destructive, not
idempotent and open world. Annotations are hints for the client, not a security
boundary. Who may call a tool is decided by [authorization](authorization.md).

## Constructor injection

Tool classes are built through Cake's container, so constructor dependencies
are injected. Services you registered in `Application::services()` come first;
anything else is autowired when its own dependencies can be resolved.

```php
class ReportTools
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    #[McpTool(name: 'monthly_report')]
    public function monthly(int $year, int $month): array
    {
        return $this->reports->forMonth($year, $month);
    }
}
```

For ORM tables use `LocatorAwareTrait` and `fetchTable()`, as in the examples
above, instead of injecting a table.

## Knowing who is calling

Inject `CakeDC\Mcp\Identity\CurrentIdentity`:

```php
use CakeDC\Mcp\Identity\CurrentIdentity;

class WhoAmITools
{
    public function __construct(private readonly CurrentIdentity $identity)
    {
    }

    #[McpTool(name: 'whoami')]
    public function whoami(): array
    {
        return ['id' => $this->identity->getIdentifier()];
    }
}
```

`get()` returns the host identity exactly as your authentication produced it,
`has()` tells whether there is one, and `getIdentifier()` returns its id.

The SDK's own access token is also available. Add a `RequestContext` parameter
to the tool method and call `$context->getAccessToken()`:

```php
use Mcp\Server\RequestContext;

#[McpTool(name: 'token_scopes')]
public function scopes(RequestContext $context): array
{
    return $context->getAccessToken()?->getScopes() ?? [];
}
```

## Errors

If a tool throws an unexpected exception, the client receives a JSON-RPC
`-32603` error with the message `Error while executing tool`. The original
message never reaches the client, so internals do not leak. With the stateless
2026-07-28 protocol revision the SDK answers that error with HTTP 400.

To return a message to the client on purpose, throw the exception that
`CakeDC\Mcp\Tool\ToolError::message()` builds (a
`Mcp\Exception\ToolCallException`). The model reads the message and can try
again:

```php
use CakeDC\Mcp\Tool\ToolError;

#[McpTool(name: 'cancel_order')]
public function cancel(string $number): string
{
    $order = $this->fetchTable('Orders')->find()->where(['number' => $number])->first();
    if ($order === null) {
        throw ToolError::message(sprintf('Order "%s" does not exist.', $number));
    }

    return 'Cancelled.';
}
```

Unhandled exceptions are logged through Cake's `Log` with the `mcp` scope.

### Validation errors

The SDK already rejects arguments that do not match the tool's input schema.
For the rules that live in your tables and validators, throw
`CakeDC\Mcp\Tool\ToolError::invalid()`. It turns Cake's validation errors into
a message with one line per field, so the model sees what to fix and can try
again:

```php
use Cake\ORM\Locator\LocatorAwareTrait;
use CakeDC\Mcp\Tool\ToolError;
use Mcp\Capability\Attribute\McpTool;

class ArticleTools
{
    use LocatorAwareTrait;

    #[McpTool(name: 'create_draft')]
    public function createDraft(string $title, string $body): array
    {
        $articles = $this->fetchTable('Articles');
        $article = $articles->newEntity(['title' => $title, 'body' => $body]);
        if (!$articles->save($article)) {
            throw ToolError::invalid($article, 'The draft was not saved.');
        }

        return ['id' => $article->id];
    }
}
```

The client receives a tool error with this text:

```
The draft was not saved.
- title: This field cannot be left empty
- tags.0.name: This field is required
```

`invalid()` takes an entity (errors of associated entities included), the
array returned by `Validator::validate()` or `getErrors()`, or a simple
`['field' => 'message']` array. With `saveOrFail()`,
catch `Cake\ORM\Exception\PersistenceFailedException` and pass
`$exception->getEntity()`. The messages are your validation messages as they
are, so write them for someone who has to fix the data.

To check the arguments themselves before doing any work, pass them and a
`Cake\Validation\Validator` to `CakeDC\Mcp\Tool\ToolValidation::check()`. It
throws the same kind of error when a rule fails. Keep the shape and the types in
the input schema (`#[Schema]`, PHP types) and put in the validator what a schema
cannot say: limits that depend on your data, rules across fields, or a validator
your application already has.

```php
use Cake\Validation\Validator;
use CakeDC\Mcp\Tool\ToolValidation;

#[McpTool(name: 'reserve_stock')]
public function reserve(string $sku, int $quantity): array
{
    $available = $this->stock->available($sku);
    $validator = (new Validator())
        ->range('quantity', [1, $available], sprintf('Between 1 and %d, the stock of %s.', $available, $sku));
    ToolValidation::check(['sku' => $sku, 'quantity' => $quantity], $validator, 'Nothing was reserved.');

    // ...
}
```

Pass `newRecord: false` to apply the validator's `'update'` rules.

Prompts take arguments too. `ToolValidation::checkPrompt()` runs the same
check in a prompt method, and the client gets the same lines as the error of
`prompts/get`.
