# Installation

## Composer

```bash
composer require cakedc/cakephp-mcp
```

> **Beta note (temporary).** `mcp/sdk` 0.9.0 is not released yet, and this
> plugin pins the SDK to a fixed commit. Composer only applies inline aliases and
> `minimum-stability` from the root package, so until the SDK tags 0.9.0 your
> application must declare the same requirement and allow dev stability:
>
> ```bash
> composer config minimum-stability dev
> composer config prefer-stable true
> composer require "mcp/sdk:dev-main#82e19d114e95671e05e7df7909195f7ace03d90e as 0.9.0" cakedc/cakephp-mcp:dev-2.next-cake5
> ```
>
> This note will go away once the SDK tags 0.9.0.

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
$csrf->skipCheckCallback(fn (ServerRequest $request): bool => $request->getPath() === '/mcp');

$middlewareQueue->add($csrf);
```

If you change `Mcp.route`, change the path here too.

## OAuth

Remote clients that sign people in (claude.ai connectors, Claude Code, the MCP
Inspector) need `Mcp.auth` set to `oauth` or `external`. That also needs the
skip callback to cover the metadata path, so prefer this form, which follows
`Mcp.route`:

```php
use CakeDC\Mcp\McpConfig;

$csrf->skipCheckCallback(fn (ServerRequest $request): bool => McpConfig::isEndpoint($request));
```

The setup, the optional packages and `bin/cake mcp check` are described in
[OAuth](oauth.md).

## The route

The plugin connects `/mcp` (methods GET, POST, DELETE and OPTIONS) to its own
controller. GET is routed, but this SDK version answers it with 405 (there is
no standalone server-to-client stream). To use another path:

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
