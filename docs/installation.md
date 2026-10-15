# Installation

## Composer

```bash
composer require cakedc/cakephp-mcp
```

The plugin needs PHP 8.2+ and CakePHP 5. It builds on the official `mcp/sdk`.
The `php-http/discovery` Composer plugin is not needed: the plugin passes
Cake's own PSR-17 factories to the SDK, so nothing has to be discovered.

## Load the plugin

In `src/Application.php`:

```php
public function bootstrap(): void
{
    parent::bootstrap();
    $this->addPlugin('CakeDC/Mcp');
}
```

## Skip CSRF for the endpoint

MCP clients are not browsers and do not send CSRF tokens. In
`Application::middleware()` let requests to the MCP path through:

```php
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Http\ServerRequest;

$csrf = new CsrfProtectionMiddleware();
$csrf->skipCheckCallback(fn (ServerRequest $request): bool => str_starts_with($request->getPath(), '/mcp'));

$middlewareQueue->add($csrf);
```

If you change `Mcp.route`, change the path here too.

## The route

The plugin connects `/mcp` (methods GET, POST, DELETE and OPTIONS) to its own
controller. To use another path:

```php
// config/app_local.php or config/bootstrap.php
Configure::write('Mcp.route', '/api/mcp');
```

To not connect any route (for example when you only use stdio), set it to
`false`:

```php
Configure::write('Mcp.route', false);
```

## Where tools live

By default the plugin scans `src/Mcp/` in your application for classes with
`#[McpTool]`, `#[McpResource]` and `#[McpPrompt]` attributes. Change the
directories with `Mcp.discovery.scanDirs` (paths relative to `ROOT`). See
[Writing tools](tools.md).

If the directory does not exist yet, the endpoint still works and returns an
empty tool list.

## After deploying new tools

Outside debug mode the discovery result is cached in the `_cake_mcp_` cache
config. After you deploy new or changed tools, clear it:

```bash
bin/cake cache clear _cake_mcp_
```

In debug mode the discovery cache is off, so new tools show up right away.
