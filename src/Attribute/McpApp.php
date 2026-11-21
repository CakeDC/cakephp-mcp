<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Links a tool to the MCP App that shows its result: `_meta.ui.resourceUri` and `_meta.ui.visibility` in
 * `tools/list`. Visibility `['app']` makes an app-only tool: hosts keep it from the model, and clients that do not
 * render apps do not get it in `tools/list`. It is a hint, not access control: the tool is gated like any other.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class McpApp
{
    /**
     * @param string $resource The `ui://` resource of the app.
     * @param list<string> $visibility Who may call the tool: `model`, `app` or both.
     * @throws \InvalidArgumentException When the resource is not a `ui://` URI or the visibility is unknown.
     */
    public function __construct(
        public readonly string $resource,
        public readonly array $visibility = ['model', 'app'],
    ) {
        if (!str_starts_with($resource, 'ui://')) {
            throw new InvalidArgumentException(sprintf('#[McpApp] resource "%s" must be a ui:// URI.', $resource));
        }
        if ($visibility === [] || array_diff($visibility, ['model', 'app']) !== []) {
            throw new InvalidArgumentException('#[McpApp] visibility must list "model", "app" or both.');
        }
    }
}
