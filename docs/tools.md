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
