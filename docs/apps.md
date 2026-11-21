# MCP Apps

An MCP App is a small web page a host (Claude, ChatGPT, an IDE) shows in a
frame next to the conversation. The model calls a tool; the host opens the
tool's app and passes it the result; the page can call tools of your server
itself. It is the MCP Apps extension of the protocol
(`io.modelcontextprotocol/ui`). The plugin renders the page from a Cake
template and ships the official MCP Apps client, so the page needs no build
step.

## Turning it on

```php
'Mcp' => ['apps' => ['enabled' => true]],
```

The server then tells clients it serves apps. Clients that do not render apps
ignore it and keep using your tools as before. If `Mcp.server.configure`
already enables the extension (`$builder->enableExtension(new McpApps())`, as
a 2.1 application could), that keeps working, with or without this setting.
Leaving app-only tools out of `tools/list` for clients that do not render apps
happens only with `Mcp.apps.enabled` on.

## An app

An app is a `ui://` resource with the MIME type `text/html;profile=mcp-app`,
plus a tool linked to it with `#[McpApp]`. `bin/cake bake mcp_app Inbox`
creates both, the page template and a test:

```php
namespace App\Mcp;

use CakeDC\Mcp\App\AppView;
use CakeDC\Mcp\Attribute\McpApp;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;

class InboxApp
{
    #[McpResource(uri: 'ui://app/inbox', name: 'inbox', mimeType: McpApps::MIME_TYPE)]
    public function page(): TextResourceContents
    {
        return AppView::render('ui://app/inbox', 'Mcp/inbox', [], new UiResourceContentMeta(
            csp: new UiResourceCsp(connectDomains: ['https://api.example.com']),
        ));
    }

    /**
     * The orders waiting to be read.
     */
    #[McpApp(resource: 'ui://app/inbox')]
    #[McpTool(name: 'show_inbox')]
    public function show(): array
    {
        return ['count' => 3];
    }

    /**
     * The rows of the inbox, for the app only.
     */
    #[McpApp(resource: 'ui://app/inbox', visibility: ['app'])]
    #[McpTool(name: 'inbox_rows')]
    public function rows(): array
    {
        // ...
    }
}
```

`AppView::render()` renders `templates/Mcp/inbox.php` in the plugin's
`CakeDC/Mcp.app` layout and returns the page with the right MIME type. The
layout is a complete HTML document whose first script defines `McpApps`, the
official client (`@modelcontextprotocol/ext-apps`, inlined, so the page needs
no network access to load it). The template uses it:

```php
<ul id="rows"></ul>
<script type="module">
    const app = new McpApps.App({name: 'Inbox', version: '1.0.0'});
    app.ontoolresult = (result) => { /* the result of show_inbox */ };
    await app.connect();
    const rows = await app.callServerTool({name: 'inbox_rows', arguments: {}});
</script>
```

The third argument of `render()` holds the view variables. The fourth,
`UiResourceContentMeta`, tells the host what the frame may do: the domains it
may connect to or load from (`UiResourceCsp`), permissions such as the
clipboard (`UiResourcePermissions`), and whether it wants a border. Hosts apply
a strict policy by default, so declare every outside domain the page uses.

## Visibility

`#[McpApp]` writes `_meta.ui` into the tool's entry of `tools/list`:
`resourceUri`, and `visibility`, by default `['model', 'app']`. A tool with
`visibility: ['app']` is for the app only: hosts keep it from the model, and
clients that do not render apps do not get it in `tools/list` at all. The tool
catalog leaves every tool with an app out, even when a pattern of
`Mcp.catalog.tools` matches it: `find_tools` does not offer it, `call_tools`
does not run it, and it stays in `tools/list`, where the host reads its
`_meta.ui` (an app-only tool for clients that render apps, a tool that links to
an app for every client). `bin/cake mcp check` names the tools with an app that
a pattern matched on its `Catalog` line.

Visibility is a hint for the host, not access control. An app-only tool can
still be called by anyone who knows its name and holds a valid token, so it is
gated by your authorizer, needs its scopes and is audited like any other tool
(its audit records carry `app=1`). Data an app shows also passes through the
host, so the host vendor's terms apply to it as much as to what the model reads.

## Checking and testing

`bin/cake mcp check` reports apps on its `Apps` line, and fails when a tool
links to a resource that is not a registered app page, or when tools link to
apps while the server does not announce apps (neither `Mcp.apps.enabled` nor
the hook). In tests, call the resource method directly to check the page (the
test `bake mcp_app` writes loads the plugin for the page's layout), and the
tools with `McpTestTrait` as usual. Empty `_meta.ui`, `csp` and `permissions`
of a page are left out, never sent as empty lists.
