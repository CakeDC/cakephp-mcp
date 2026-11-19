# CakePHP MCP Server

Expose tools, resources and prompts from a CakePHP 4 application to Claude and
any other [Model Context Protocol](https://modelcontextprotocol.io) client.
Built on the official [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk).

This is the 1.x line, for CakePHP 4.5+. The 2.x line (CakePHP 5) also has
OAuth 2.1 for remote clients; see [Not available in 1.x](#not-available-in-1x).

- Remote MCP over Streamable HTTP, mounted as a regular Cake route (`/mcp`).
- `bin/cake mcp server` for local stdio clients, and `bin/cake mcp inspector` to try the server by hand.
- Tools are plain PHP classes with `#[McpTool]` attributes, with constructor injection.
- Authentication stays in your app (`cakephp/authentication`); the plugin reads the identity.
- `bin/cake mcp check` finds what keeps remote clients from connecting, without network calls.
- Per-tool authorization, including `config/permissions.php` rules through `cakedc/auth`.
- `McpTestTrait` to test your tools, and who may call them, from a regular Cake test case.
- An audit log of MCP calls (`Mcp.audit`, off by default): who called which tool, with which arguments, and how it ended, to a log scope or your own table.

## Requirements

- PHP 8.2+
- CakePHP 4.5+
- `cakephp/authentication` 2.x (or any authentication that sets the `identity` request attribute)

## Install

```bash
composer require cakedc/cakephp-mcp:^1.1
```

It installs `mcp/sdk` ^0.9, the official PHP SDK. Require `^1.1`: version 1.0.0
on Packagist is the CakePHP 5 code of 2.0.0, tagged by mistake.

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
            ->order(['created' => 'DESC'])
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
$service->loadIdentifier('Authentication.Token', ['tokenField' => 'api_token']);
$service->loadAuthenticator('Authentication.Token', [
    'header' => 'Authorization',
    'tokenPrefix' => 'Bearer',
]);
```

## Connect Claude

- Claude Code: `claude mcp add --transport http my-app https://example.com/mcp --header "Authorization: Bearer <token>"`
- claude.ai: Customize > Connectors > Add custom connector, with the URL and (where available) a request header.

## Not available in 1.x

The 1.x line is a port of 2.1.1 to CakePHP 4. It leaves out what needs
CakePHP 5 or packages that only exist for it:

- OAuth 2.1 for remote clients: the `oauth` and `external` values of
  `Mcp.auth.mode`, the protected resource metadata
  (`/.well-known/oauth-protected-resource`) and its `401` challenge, and the
  scope policy (`Mcp.auth.scopes`) with `403 insufficient_scope`. Only `host`
  mode is available; any other value stops the application at bootstrap.
- `McpTestTrait::assertMcpInsufficientScope()`. `mcpActingAs()` still takes
  scopes and claims, which reach the access token but never refuse a call.
- The rate limiting example, since CakePHP 4 has no `RateLimitMiddleware`.

Everything else (the endpoint, stdio, tools, authorization, the audit log,
`mcp check`, `mcp inspector` and `McpTestTrait`) is the same as in 2.1.1.

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Writing tools](docs/tools.md)
- [Authentication](docs/authentication.md)
- [Authorization](docs/authorization.md)
- [Testing your tools](docs/testing.md)
- [stdio](docs/stdio.md)
- [Connecting clients](docs/clients.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Protocol conformance](docs/conformance.md)

## License

MIT. Copyright (c) Cake Development Corporation.
