# CakePHP MCP Server

Expose tools, resources and prompts from a CakePHP 5 application to Claude and
any other [Model Context Protocol](https://modelcontextprotocol.io) client.
Built on the official [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk).

- Remote MCP over Streamable HTTP, mounted as a regular Cake route (`/mcp`).
- `bin/cake mcp server` for local stdio clients, and `bin/cake mcp inspector` to try the server by hand.
- Tools are plain PHP classes with `#[McpTool]` attributes, with constructor injection.
- Authentication stays in your app (`cakephp/authentication`); the plugin reads the identity.
- OAuth 2.1 for remote clients: sign people in with your own login (`cakedc/cakephp-oauth-server`) or accept tokens of Auth0, Keycloak, Microsoft Entra ID or WorkOS, with scopes per tool.
- `bin/cake mcp check` finds what keeps remote clients from connecting, without network calls.
- Per-tool authorization, including `config/permissions.php` rules through `cakedc/auth`.
- `McpTestTrait` to test your tools, and who may call them, from a regular Cake test case.

## Requirements

- PHP 8.2+
- CakePHP 5

## Install

```bash
composer require cakedc/cakephp-mcp
```

> **Before the first 2.x tag.** 2.x is not tagged yet, so require the
> development branch:
>
> ```bash
> composer require cakedc/cakephp-mcp:dev-2.next-cake5
> ```
>
> It needs `mcp/sdk` ^0.9, a stable release, so no dev stability settings are
> needed for the SDK.

Load the plugin in `src/Application.php`:

```php
$this->addPlugin('CakeDC/Mcp');
```

Let MCP requests through CSRF protection (in `Application::middleware()`):

```php
$csrf = new CsrfProtectionMiddleware();
$csrf->skipCheckCallback(fn (ServerRequest $request): bool => $request->getPath() === '/mcp');
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
- claude.ai: Settings, Connectors, Add custom connector, with the URL and (where available) a request header. To have people sign in instead, see [OAuth](docs/oauth.md).

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Writing tools](docs/tools.md)
- [Authentication](docs/authentication.md)
- [OAuth](docs/oauth.md)
- [Authorization](docs/authorization.md)
- [Testing your tools](docs/testing.md)
- [stdio](docs/stdio.md)
- [Connecting clients](docs/clients.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Protocol conformance](docs/conformance.md)

## License

MIT. Copyright (c) Cake Development Corporation.
