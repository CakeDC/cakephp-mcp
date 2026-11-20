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
- Prefer `string $from = ''` to `?string $from = null` for an optional string.
  The nullable form gives `"type": ["null", "string"]`, a type array that some
  hosts (Gemini-based ones, for example) reject.
- Use `#[Schema]` (`Mcp\Capability\Attribute\Schema`) on a parameter for what a
  PHP type cannot say: allowed values, a format, a range, or the type of the
  items of an array.

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Orders in a date range.
 *
 * @param string $from First day, YYYY-MM-DD. Empty: 30 days ago.
 * @param string $status Order status.
 * @param int[] $facilities Facility ids. Empty: all.
 */
#[McpTool(name: 'list_orders')]
public function list(
    #[Schema(format: 'date')] string $from = '',
    #[Schema(enum: ['open', 'closed'])] string $status = 'open',
    array $facilities = [],
): array {
    // ...
}
```

For existing tools written with nullable parameters, set
`Mcp.schema.normalize` to `true` (see [Configuration](configuration.md#keys)):
it removes the type arrays from `tools/list` without changing the code.
`bin/cake mcp check` lists the schemas that still have type arrays, untyped
arrays or parameters without a description.

## Example returning structured data

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

To return a message to the client on purpose, throw
`Mcp\Exception\ToolCallException`:

```php
use Mcp\Exception\ToolCallException;

#[McpTool(name: 'cancel_order')]
public function cancel(string $number): string
{
    $order = $this->fetchTable('Orders')->find()->where(['number' => $number])->first();
    if ($order === null) {
        throw new ToolCallException(sprintf('Order "%s" does not exist.', $number));
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
