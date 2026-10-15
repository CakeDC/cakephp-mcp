# Authorization

Authentication says who is calling. Authorization says which tools that caller
may use. The plugin checks it on every `tools/list` and `tools/call`.

## The interface

```php
namespace CakeDC\Mcp\Authorization;

interface ToolAuthorizerInterface
{
    public function canCall(mixed $identity, string $tool): bool;
}
```

`$identity` is the host identity as your authentication produced it (or `null`
when identity is not required and the caller is anonymous). `$tool` is the MCP
tool name.

The default is `AllowAllToolAuthorizer`: every authenticated caller may use
every tool.

## A custom authorizer

```php
namespace App\Mcp;

use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;

class StaffOnlyAuthorizer implements ToolAuthorizerInterface
{
    public function canCall(mixed $identity, string $tool): bool
    {
        if (str_starts_with($tool, 'admin_')) {
            return ($identity['role'] ?? null) === 'admin';
        }

        return $identity !== null;
    }
}
```

Configure it by class name (built through the container, so it can have
injected dependencies) or by instance:

```php
Configure::write('Mcp.authorization.authorizer', \App\Mcp\StaffOnlyAuthorizer::class);
// or
Configure::write('Mcp.authorization.authorizer', new StaffOnlyAuthorizer());
```

## What a denied caller sees

- A denied tool is hidden from `tools/list`.
- Calling it anyway gets the same answer as calling a tool that does not
  exist: JSON-RPC error `-32602` with the message `Tool not found: "<name>".`

So callers cannot discover which protected tools exist.

## RbacToolAuthorizer (cakedc/auth)

If your app uses `cakedc/auth` permissions, install it and select
`RbacToolAuthorizer`:

```bash
composer require cakedc/auth
```

```php
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;

Configure::write('Mcp.authorization', [
    'authorizer' => RbacToolAuthorizer::class,
    'rbac' => [
        'permissions' => [
            // editors may use two tools
            [
                'role' => 'editor',
                'plugin' => 'CakeDC/Mcp',
                'controller' => 'Tools',
                'action' => ['find_order', 'whoami'],
            ],
            // admins may use all of them
            [
                'role' => 'admin',
                'plugin' => 'CakeDC/Mcp',
                'controller' => 'Tools',
                'action' => '*',
            ],
            // any role, but only for the user whose id is 1
            [
                'role' => '*',
                'plugin' => 'CakeDC/Mcp',
                'controller' => 'Tools',
                'action' => 'own_profile',
                'allowed' => fn ($user, $role, $request): bool => ($user['id'] ?? null) === 1,
            ],
        ],
    ],
]);
```

Tool `X` is checked as plugin `CakeDC/Mcp`, controller `Tools`, action `X`.
Rules are the same format as `config/permissions.php`, and `Mcp.authorization.rbac`
accepts the same options as the cakedc/auth `Rbac` class (`permissions`,
`permissions_provider_class`, `role_field`, `default_role`, `log`, and so on).
You can also pass a prepared `Rbac` instance to `new RbacToolAuthorizer($rbac)`
and configure the authorizer by instance.

Identities that are not arrays or `ArrayAccess` objects are denied.

### Warning: host rules without a plugin key

cakedc/auth rules only constrain the keys they list. A host rule that has no
`plugin` key, for example:

```php
['role' => 'admin', 'controller' => '*', 'action' => '*']
```

also grants every MCP tool to admins, because the missing `plugin` matches
anything. Write MCP rules with `'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools'`,
and review the host-wide wildcard rules whenever the MCP rules share a
permissions file with the rest of your application.

## stdio

Per-tool authorization is skipped for stdio by default (`Mcp.stdio.trusted`).
See [stdio](stdio.md).
