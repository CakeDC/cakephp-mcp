# Authentication

The plugin does not authenticate anyone itself. Your application does, with the
tools it already uses (`cakephp/authentication`, `cakedc/users`, and so on),
and the plugin reads the result.

## The identity contract

`IdentityMiddleware` looks for the identity in the request attribute named by
`Mcp.identity.attribute` (default `identity`, which is what
`cakephp/authentication` sets). When it finds one it:

- stores it as the `CurrentIdentity` that tools can inject;
- gives the SDK an access token whose `sub` claim is the identity's id.

The id is taken from `getIdentifier()` when the identity has that method
(`Authentication\Identity`), otherwise from the `id` key of an array,
`ArrayObject` or entity.

`Mcp.identity.required` (default `true`) decides what happens without an
identity. With `false`, unauthenticated calls go through and tools see no
identity.

## Bearer tokens

Add a token authenticator to your authentication service. With a token column
on your users table:

```php
public function getAuthenticationService(ServerRequestInterface $request): AuthenticationServiceInterface
{
    $service = new AuthenticationService();
    $service->loadAuthenticator('Authentication.Token', [
        'header' => 'Authorization',
        'tokenPrefix' => 'Bearer',
        'identifier' => ['Authentication.Token' => ['tokenField' => 'api_token']],
    ]);

    return $service;
}
```

For tokens that are not a column on one table, use a callback identifier:

```php
$service->loadAuthenticator('Authentication.Token', [
    'header' => 'Authorization',
    'tokenPrefix' => 'Bearer',
    'identifier' => [
        'Authentication.Callback' => [
            'callback' => fn (array $data): ?ArrayObject => $this->tokens->identityFor($data['token'] ?? null),
        ],
    ],
]);
```

The authenticator is not tied to the MCP path, so the rest of your app keeps
working as before.

## Scopes

`Mcp.identity.scopes` can map an identity to the scope strings the SDK access
token carries:

```php
Configure::write('Mcp.identity.scopes', fn (mixed $identity): array => ['articles:read']);
```

Tools read them with `RequestContext::getAccessToken()?->getScopes()`; see
[Writing tools](tools.md).

## Missing identity

When an identity is required and the request has none, the response is:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer
Content-Type: application/json

{"error":"unauthorized","error_description":"Authentication required."}
```

CORS preflight requests (OPTIONS) carry no credentials and are never
challenged.

## cakedc/auth and cakedc/users

Applications built on `cakedc/auth` or `cakedc/users` need nothing special.
They already put the identity in the `identity` attribute, so the plugin works
unchanged. To restrict tools by role, see [Authorization](authorization.md).

## OAuth

OAuth 2.1 (what claude.ai connectors expect for remote servers with
sign-in) is planned for 2.1. The identity middleware sits in the same slot the
SDK's OAuth middleware will take, so your tools will not need to change.
