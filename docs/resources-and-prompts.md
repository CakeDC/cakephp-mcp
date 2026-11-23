# Resources and prompts

Next to tools, an MCP server offers two other kinds of things:

- **Resources**: data the client reads by URI and shows to the model or the
  user, such as a policy, a report or an order. A **resource template** is a
  family of resources with one URI pattern, such as `shop://orders/{number}`.
- **Prompts**: ready-made messages the user picks from a list in the client,
  such as "summarize this order", with arguments the client asks for.

Both are declared like tools, with the SDK's attributes on plain classes in
`src/Mcp/` (or another directory of `Mcp.discovery.scanDirs`), and get
constructor injection from Cake's container. See [Writing tools](tools.md) for
discovery, the container and the caller's identity, which work the same way.

The examples on this page are those of `tests/test_app/src/McpDocs/ShopContent.php`
in the plugin, and the plugin's tests check that they answer as shown.

## Resources

```php
namespace App\Mcp;

use Mcp\Capability\Attribute\McpResource;

class ShopContent
{
    /**
     * How returns work.
     */
    #[McpResource(uri: 'shop://policies/returns', name: 'return_policy', mimeType: 'text/markdown')]
    public function returnPolicy(): string
    {
        return "# Returns\n\nWithin 30 days, with the receipt.";
    }

    /**
     * Today's numbers.
     */
    #[McpResource(uri: 'shop://stats', name: 'shop_stats', mimeType: 'application/json')]
    public function stats(): array
    {
        return ['orders' => 42, 'open' => 3];
    }
}
```

The docblock is the description clients show. What the method returns
becomes the contents of `resources/read`:

- A string is sent as text, with the `mimeType` of the attribute.
- An array is sent as JSON text when the `mimeType` is JSON
  (`application/json`).
- `['blob' => base64, 'mimeType' => ...]` is sent as binary content, for
  images, PDFs and other files:

  ```php
  #[McpResource(uri: 'shop://logo', name: 'logo', mimeType: 'image/png')]
  public function logo(): array
  {
      return ['blob' => base64_encode((string)file_get_contents(WWW_ROOT . 'img/logo.png')), 'mimeType' => 'image/png'];
  }
  ```

- An `SplFileInfo` is read and sent as binary content, and a
  `TextResourceContents` or `BlobResourceContents` of the SDK is sent as it
  is, when you need full control.

`bin/cake bake mcp_resource OrderGuide` writes a resource class and its test
(see [Generating classes with bake](tools.md#generating-classes-with-bake)).

## Resource templates

A template declares the URI pattern; its variables are the method's
arguments:

```php
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpResourceTemplate;

#[McpResourceTemplate(uriTemplate: 'shop://orders/{number}', name: 'order', mimeType: 'application/json')]
public function order(
    #[CompletionProvider(provider: OrderNumbers::class)]
    string $number,
): array {
    return ['number' => $number, 'status' => 'shipped'];
}
```

Templates are listed by `resources/templates/list`, and the client reads a
concrete URI (`shop://orders/A-1001`) with `resources/read`, like any
resource.

## Prompts

```php
use Mcp\Capability\Attribute\McpPrompt;

/**
 * Writes a summary of an order for the customer.
 *
 * @param string $number The order number.
 */
#[McpPrompt(name: 'summarize_order')]
public function summarizeOrder(
    #[CompletionProvider(provider: OrderNumbers::class)]
    string $number,
): array {
    return [
        ['role' => 'user', 'content' => 'Summarize order ' . $number . ' for the customer.'],
    ];
}
```

The arguments become the prompt's arguments, with the `@param` descriptions.
The method returns a list of messages, each with a `role` (`user` or
`assistant`) and a `content`. Check arguments with
`ToolValidation::checkPrompt()` (see [Errors](tools.md#errors)).
`bin/cake bake mcp_prompt WeeklyReport` writes a prompt class and its test.

## Content types

A message's `content` is a string (sent as text) or one of the SDK's content
classes:

```php
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\EmbeddedResource;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\ResourceLink;
use Mcp\Schema\Content\TextResourceContents;

return [
    ['role' => 'user', 'content' => 'Summarize order ' . $number . ' for the customer.'],
    // The order itself, inside the message.
    ['role' => 'user', 'content' => new EmbeddedResource(
        new TextResourceContents('shop://orders/' . $number, 'application/json', $order),
    )],
    // A pointer to a resource the client can read when it needs it.
    ['role' => 'user', 'content' => new ResourceLink('shop://policies/returns', 'return_policy', mimeType: 'text/markdown')],
];
```

- `ImageContent($base64, 'image/png')`: an image, as the `describe_photo`
  prompt of the example sends with "Describe this product photo in one
  sentence."
- `AudioContent($base64, 'audio/wav')`: a recording, as the
  `transcribe_voicemail` prompt sends with "Transcribe this voicemail."
- `EmbeddedResource`: the contents of a resource inside the message, text
  (`TextResourceContents`) or binary (`BlobResourceContents`).
- `ResourceLink`: the URI of a resource, with its name and MIME type, without
  its contents.

Tools return the same content classes, see [Results](tools.md#results).

## Completions

Clients ask for completions while the user types an argument of a prompt or a
variable of a template (`completion/complete`). Declare them with
`#[CompletionProvider]` on the argument, as above: `values: [...]` for a
fixed list, `enum: Status::class` for the cases of an enum, or
`provider: OrderNumbers::class` for a class that implements the SDK's
`ProviderInterface`, built from the container:

```php
use Mcp\Capability\Completion\ProviderInterface;

class OrderNumbers implements ProviderInterface
{
    public function __construct(private OrdersTable $orders)
    {
    }

    public function getCompletions(string $currentValue): array
    {
        return $this->orders->find()
            ->select(['number'])
            ->where(['number LIKE' => $currentValue . '%'])
            ->limit(20)
            ->all()
            ->extract('number')
            ->toList();
    }
}
```

The example class of the plugin's tests completes `A-` to `A-1001` and
`A-1002` from a fixed list instead of a table.

## Authorization

By default every identified caller can list and read every resource and get
every prompt. An authorizer that implements `ResourceAuthorizerInterface` or
`PromptAuthorizerInterface` decides who sees which one: a denied resource or
prompt is left out of the lists, answers like an unknown one, and completes
nothing. `RbacToolAuthorizer` checks them as controllers `Resources` and
`Prompts` once your rules name them. See
[Resources and prompts](authorization.md#resources-and-prompts) in
Authorization.

## Testing

`McpTestTrait` reads resources, gets prompts and asks for completions through
the server, as the current identity:

```php
$this->mcpReadResource('shop://orders/A-1001');
$this->assertMcpResourceContains('"status": "shipped"');

$this->mcpGetPrompt('summarize_order', ['number' => 'A-1001']);
$this->assertMcpPromptContains('Summarize order A-1001');

$this->mcpComplete(['type' => 'ref/resource', 'uri' => 'shop://orders/{number}'], 'number', 'A-');
$this->assertMcpCompletionValues(['A-1001', 'A-1002']);

$this->mcpActingAs($clerk);
$this->assertMcpResourceNotListed('shop://stats');
```

See [Prompts, resources and completions](testing.md#prompts-resources-and-completions)
for every helper and assertion.
