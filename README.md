# CakePHP MCP Server

Expose tools, resources and prompts from a CakePHP 5 application to Claude and
any other [Model Context Protocol](https://modelcontextprotocol.io) client.
Built on the official [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk).

- Remote MCP over Streamable HTTP, mounted as a regular Cake route (`/mcp`).
- `bin/cake mcp server` for local stdio clients.
- Tools are plain PHP classes with `#[McpTool]` attributes, with constructor injection.
- Authentication stays in your app (`cakephp/authentication`); the plugin reads the identity.
- Per-tool authorization, including `config/permissions.php` rules through `cakedc/auth`.

## Requirements

- PHP 8.2+
- CakePHP 5

## Install

```bash
composer require cakedc/cakephp-mcp
```

Load the plugin in `src/Application.php`:

```php
$this->addPlugin('CakeDC/Mcp');
```

Let MCP requests through CSRF protection (in `Application::middleware()`):

```php
$csrf = new CsrfProtectionMiddleware();
$csrf->skipCheckCallback(fn (ServerRequest $request): bool => str_starts_with($request->getPath(), '/mcp'));
```

## Your first tool

Create `src/Mcp/ArticleTools.php`:

```php
namespace App\Mcp;

use Cake\ORM\Locator\LocatorAwareTrait;
use Mcp\Capability\Attribute\McpTool;

class ArticleTools
{
    use LocatorAwareTrait;

    /**
     * Lists the titles of the latest articles.
     *
     * @return list<string>
     */
    #[McpTool(name: 'latest_articles')]
    public function latest(int $limit = 5): array
    {
        return $this->fetchTable('Articles')->find()
            ->select(['title'])
            ->orderByDesc('created')
            ->limit($limit)
            ->all()
            ->extract('title')
            ->toList();
    }
}
```

## Authenticate callers

By default `/mcp` requires an identity. Add an authenticator that reads a Bearer
token, for example with `cakephp/authentication`:

```php
$service->loadAuthenticator('Authentication.Token', [
    'header' => 'Authorization',
    'tokenPrefix' => 'Bearer',
    'identifier' => ['Authentication.Token' => ['tokenField' => 'api_token']],
]);
```

## Connect Claude

- Claude Code: `claude mcp add --transport http my-app https://example.com/mcp --header "Authorization: Bearer <token>"`
- claude.ai: Settings, Connectors, Add custom connector, with the URL and (where available) a request header.

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Writing tools](docs/tools.md)
- [Authentication](docs/authentication.md)
- [Authorization](docs/authorization.md)
- [stdio](docs/stdio.md)
- [Connecting clients](docs/clients.md)
- [Troubleshooting](docs/troubleshooting.md)

## License

MIT. Copyright (c) Cake Development Corporation.
