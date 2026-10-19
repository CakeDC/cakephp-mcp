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

Applications built on `cakedc/auth` or `cakedc/users` already put the identity
in the `identity` attribute, so the plugin reads it unchanged. Two defaults of
`cakedc/users` get in the way of the endpoint, though, and need configuration:

1. `cakedc/users` loads `RequestAuthorizationMiddleware` with `RbacPolicy`, which
   authorizes the route before the controller runs. The plugin's
   `skipAuthorization()` call never gets a chance, and an anonymous request is
   redirected to the login page instead of receiving the plugin's 401. Add a
   permission rule for the endpoint and its protected resource metadata (used
   by [OAuth](oauth.md)) in your `config/permissions.php`, with `bypassAuth` so
   the request reaches the plugin, which then does its own 401 and per-tool
   authorization. `McpConfig::rbacPermissions()` returns that rule:

   ```php
   // config/permissions.php
   use CakeDC\Mcp\McpConfig;

   return [
       'CakeDC/Auth.permissions' => array_merge(McpConfig::rbacPermissions(), [
           // ... your rules
       ]),
   ];
   ```

   It is this rule, which you can also write by hand:

   ```php
   [
       'role' => '*',
       'plugin' => 'CakeDC/Mcp',
       'controller' => ['Mcp', 'ProtectedResource'],
       'action' => ['handle', 'metadata'],
       'bypassAuth' => true,
   ],
   ```

   With the oauth plugin, merge `OAuthServer::rbacPermissions()` as well (see
   [OAuth](oauth.md#cakedcusers)).

2. The default `Token` authenticator of `cakedc/users` reads the `api_key` query
   parameter (`'header' => null`, `'queryParam' => 'api_key'`), not the
   `Authorization` header. Configure a header based Bearer authenticator, as
   shown in [Bearer tokens](#bearer-tokens), for example through
   `Auth.Authenticators.Token` (`'header' => 'Authorization'`,
   `'queryParam' => null`, `'tokenPrefix' => 'Bearer'`).

The key names above come from `cakedc/users` and `cakedc/auth` as installed in
a local project; check them against the versions you use. To restrict tools by
role, see [Authorization](authorization.md).

## OAuth

Remote clients such as claude.ai connectors sign people in with OAuth 2.1.
`Mcp.auth.mode` chooses how the endpoint authenticates:

- `host` (default): everything above. Your authentication puts the identity in
  the request.
- `oauth`: access tokens issued by `cakedc/cakephp-oauth-server` in the same
  application, so people sign in with your own login.
- `external`: JWT access tokens issued by Auth0, Keycloak, Microsoft Entra ID,
  WorkOS or another OpenID Connect issuer.

In `oauth` and `external` modes the identity comes from the access token and
the identity attribute of your own authentication (`Mcp.identity.attribute`) is
ignored on the MCP route, as are `Mcp.identity.required` and
`Mcp.identity.scopes`. Tools still read the identity through `CurrentIdentity`.
The full guide is in [OAuth](oauth.md).
