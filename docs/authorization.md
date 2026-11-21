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
injected dependencies) or by instance. The authorizer is chosen only through
`Mcp.authorization.authorizer`; binding `ToolAuthorizerInterface` in the
container is not consulted:

```php
Configure::write('Mcp.authorization.authorizer', \App\Mcp\StaffOnlyAuthorizer::class);
// or
Configure::write('Mcp.authorization.authorizer', new StaffOnlyAuthorizer());
```

## What a denied caller sees

- A denied tool is hidden from `tools/list`.
- Calling it anyway gets the same answer as calling a tool that does not
  exist: JSON-RPC error `-32602` with the message `Tool not found: "<name>".`

So callers generally cannot discover which protected tools exist. One
exception: tools whose input schema uses `x-mcp-header` annotations can be told
apart on the stateless (2026-07-28) era, because header validation runs before
the guard.

With the [audit log](configuration.md#audit-log) on, the record of a denied
call says `denied`, so you can tell it from an unknown tool even though the
caller cannot.

## Resources and prompts

An authorizer that only implements `ToolAuthorizerInterface` leaves resources
and prompts open: any identified caller can list, read and get them, as in 2.1.
To decide who reads which resource and gets which prompt, implement one or both
of these interfaces in the same class:

```php
use CakeDC\Mcp\Authorization\PromptAuthorizerInterface;
use CakeDC\Mcp\Authorization\ResourceAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;

final class AppAuthorizer implements
    ToolAuthorizerInterface,
    ResourceAuthorizerInterface,
    PromptAuthorizerInterface
{
    public function canCall(mixed $identity, string $tool): bool
    {
        return $identity !== null;
    }

    public function canReadResource(mixed $identity, string $uri): bool
    {
        // A concrete URI read through a template, or the template's own URI
        // (`orders://{number}`) when templates are listed or completed.
        return !str_starts_with($uri, 'payroll://') || ($identity['role'] ?? null) === 'hr';
    }

    public function canGetPrompt(mixed $identity, string $name): bool
    {
        return $name !== 'payroll_summary' || ($identity['role'] ?? null) === 'hr';
    }
}
```

The URI is the one the client sent, not a canonical form. A template variable
takes any text but `/`, so `orders://2001`, `orders://02001`,
`orders://2001?x=1` and `orders://2001 ` (with a trailing space) all reach the
handler of `orders://{number}`, and a handler that reads the number as an
integer loads the same order for each. Do not deny one record by its exact
URI: deny by default and allow what the caller may read, decide by prefix as
above, or read the variable the way the handler does.

What a denied resource or prompt does:

- It is left out of `resources/list`, `resources/templates/list` and
  `prompts/list`.
- Reading it (`resources/read`, also a URI a template matches), subscribing
  to it or unsubscribing from it answers exactly like an unknown URI, getting
  a denied prompt answers exactly like an unknown prompt, and a completion
  for a denied prompt or template answers exactly like one for an unknown
  reference, so a caller cannot tell it exists.
- A URI that a template serves is checked twice: the concrete URI
  (`orders://1001`) and the template's URI (`orders://{number}`). Denying
  either one denies the read, the subscription and the completion, so
  denying a template denies every URI it serves.
- A `subscriptions/listen` stream (2026-07-28 revision) never carries a
  `notifications/resources/updated` for a resource the caller cannot read,
  even when the client listed its URI in `resourceSubscriptions`.
- [MCP App](apps.md) pages are resources and follow the same rule.
- With [cache hints](configuration.md#keys), the lists and `resources/read`
  are always sent private, as `tools/list` is.

`bin/cake mcp check` says on its `Authorizer` line whether resources and
prompts are covered. Test the rules with the `assertMcpResourceNotListed()`,
`assertMcpResourceDenied()`, `assertMcpPromptNotListed()` and
`assertMcpPromptDenied()` assertions of [McpTestTrait](testing.md).

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

### Resources and prompts with Rbac

`RbacToolAuthorizer` also checks resource `X` as plugin `CakeDC/Mcp`,
controller `Resources`, action `X` (the URI read, or the template's URI when
templates are listed or completed), and prompt `X` as controller `Prompts`,
action `X`. It does so only when at least one rule names plugin `CakeDC/Mcp`
and controller `Resources` (or `Prompts`), exactly or in a list. With no such
rule, every resource (or prompt) stays open, so a 2.1 rule set changes nothing,
and neither does a rule for a controller of your own that is also called
`Resources`.

```php
[
    'role' => 'editor',
    'plugin' => 'CakeDC/Mcp',
    'controller' => 'Resources',
    'action' => ['app://rules', 'orders://{number}'],
],
[
    // the orders a user may read, through the template
    'role' => 'editor',
    'plugin' => 'CakeDC/Mcp',
    'controller' => 'Resources',
    'action' => '*',
    'allowed' => fn ($user, $role, $request): bool
        => str_starts_with((string)$request->getParam('action'), 'orders://'),
],
['role' => 'editor', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Prompts', 'action' => '*'],
```

Actions match exactly, so the concrete URIs of a template need a `'*'` rule
with an `allowed` callback, as above. Reading `orders://1001` needs both
rules: one for the template's URI and one for the concrete URI. Once the `Resources` rules are on, the
host-wide wildcard rules of the next section grant resources too.

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

`bin/cake mcp check` warns when the rules it loads are empty, or when none of
them can allow plugin `CakeDC/Mcp` and controller `Tools`: then no one can
call a tool.

## Token scopes

In `oauth` and `external` modes (see [OAuth](oauth.md)) a second check runs
before the tool authorizer: the scope policy in `Mcp.auth.scopes`. A call whose
token lacks the scopes of its method or tool is refused with `403
insufficient_scope` and the list of scopes it needs, so clients can ask the
person for more. The tool authorizer still runs for calls that pass, so a call
needs both the scope and the permission. A tool the token has no scope for is
still listed by `tools/list`; the authorizer decides what is hidden.

In `oauth` mode the identity the authorizer sees is the application identity of
the token owner, so `RbacToolAuthorizer` rules keep working. In `external` mode
the default identity is an `ArrayObject` with `id` and `claims` and no role, so
`cakedc/auth` applies its `default_role`; map the token to one of your users with
`Mcp.auth.identity` to use roles. See
[Identity in external mode](oauth.md#identity-in-external-mode).

## stdio

Per-tool authorization is skipped for stdio by default (`Mcp.stdio.trusted`).
See [stdio](stdio.md).
