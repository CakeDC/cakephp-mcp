<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use CakeDC\Mcp\Client\ClientRegistry;
use CakeDC\Mcp\Client\Http\EndpointGuard;
use CakeDC\Mcp\Client\Http\GuardedHttp;
use CakeDC\Mcp\Client\Http\HttpRequest;
use CakeDC\Mcp\Client\Http\HttpResponse;
use CakeDC\Mcp\Client\Store\ConnectionStoreInterface;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use Closure;

/**
 * An MCP server that asks for OAuth, and its authorization server, without a network.
 *
 * - `https://mcp.example.com/mcp` (configured, so reached through the trusted PSR-18 double, an in-process SDK
 *   server accepting the tokens issued here) answers 401 with a challenge naming its resource metadata.
 * - Learned URLs (resource metadata, `https://auth.example.com` metadata, registration, token and revocation
 *   endpoints) are served by the guarded transport double, behind the real EndpointGuard with a DNS double:
 *   `mcp.example.com` and `auth.example.com` are public, `internal.example.com` is 10.0.0.1.
 */
final class FakeOAuthServer
{
    public const MCP = 'https://mcp.example.com/mcp';

    public const RESOURCE_METADATA = 'https://mcp.example.com/.well-known/oauth-protected-resource/mcp';

    public const ISSUER = 'https://auth.example.com';

    public const AS_METADATA = 'https://auth.example.com/.well-known/oauth-authorization-server';

    public SdkServerHttpClient $mcp;

    public ScriptedGuardedTransport $guarded;

    public StaticHostResolver $dns;

    /**
     * Form bodies sent to the token endpoint, parsed, with the Authorization header under `_authorization`.
     *
     * @var list<array<string, string>>
     */
    public array $tokenRequests = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $registrations = [];

    /**
     * @var list<array<string, string>>
     */
    public array $revocations = [];

    /**
     * The code the token endpoint accepts.
     */
    public string $code = 'good-code';

    /**
     * Whether a refresh is answered with `invalid_grant`.
     */
    public bool $rejectRefresh = false;

    /**
     * Whether the revocation endpoint fails.
     */
    public bool $revocationFails = false;

    private int $issued = 0;

    public function __construct()
    {
        $this->mcp = new SdkServerHttpClient();
        $this->mcp->tokens = [];
        $this->mcp->challenge = sprintf(
            'Bearer resource_metadata="%s", scope="read write"',
            self::RESOURCE_METADATA,
        );
        $this->dns = new StaticHostResolver([
            'mcp.example.com' => ['93.184.216.34'],
            'auth.example.com' => ['93.184.216.35'],
            'internal.example.com' => ['10.0.0.1'],
        ]);
        $this->guarded = new ScriptedGuardedTransport();
        $this->resourceMetadata([
            'resource' => self::MCP,
            'authorization_servers' => [self::ISSUER],
            'scopes_supported' => ['read', 'write', 'offline_access'],
        ]);
        $this->authorizationServer();
        $this->guarded->on('POST', self::ISSUER . '/register', function (HttpRequest $request): HttpResponse {
            $this->registrations[] = (array)json_decode((string)$request->body, true);

            return self::json(201, ['client_id' => 'dyn-client-' . count($this->registrations)]);
        });
        $this->guarded->on('POST', self::ISSUER . '/token', fn(HttpRequest $request): HttpResponse => $this->token($request));
        $this->guarded->on('POST', self::ISSUER . '/revoke', function (HttpRequest $request): HttpResponse {
            parse_str((string)$request->body, $form);
            $this->revocations[] = array_map('strval', $form);

            return $this->revocationFails ? new HttpResponse(503, [], '') : new HttpResponse(200, [], '');
        });
    }

    /**
     * Serves the MCP server's protected resource metadata at RESOURCE_METADATA, on the MCP server's own origin
     * (read like the configured url) and through the guard (as an address the server named).
     *
     * @param array<string, mixed> $document The document.
     * @return void
     */
    public function resourceMetadata(array $document): void
    {
        $this->mcp->documents[self::RESOURCE_METADATA] = $document;
        $this->guarded->json(self::RESOURCE_METADATA, $document);
    }

    /**
     * Serves the authorization server's metadata, with $overrides.
     *
     * @param array<string, mixed> $overrides Fields to replace or add.
     * @return void
     */
    public function authorizationServer(array $overrides = []): void
    {
        $this->guarded->json(self::AS_METADATA, $overrides + [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/authorize',
            'token_endpoint' => self::ISSUER . '/token',
            'registration_endpoint' => self::ISSUER . '/register',
            'revocation_endpoint' => self::ISSUER . '/revoke',
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic'],
        ]);
    }

    /**
     * @param \CakeDC\Mcp\Client\Store\ConnectionStoreInterface|null $store The store; the table store by default.
     * @param \Closure|null $sleep `fn(int $microseconds): void` while another request refreshes; no wait by default.
     * @return \CakeDC\Mcp\Client\ClientRegistry A registry on these doubles.
     */
    public function registry(?ConnectionStoreInterface $store = null, ?Closure $sleep = null): ClientRegistry
    {
        return new ClientRegistry(
            new FixedHttpClientFactory($this->mcp),
            $store ?? new TableConnectionStore(),
            new GuardedHttp(new EndpointGuard($this->dns), $this->guarded, 1024 * 1024),
            $sleep ?? function (): void {
            },
        );
    }

    /**
     * @param \CakeDC\Mcp\Client\Http\HttpRequest $request A token request.
     * @return \CakeDC\Mcp\Client\Http\HttpResponse
     */
    private function token(HttpRequest $request): HttpResponse
    {
        parse_str((string)$request->body, $form);
        $form = array_map('strval', $form);
        $form['_authorization'] = $request->headers['Authorization'] ?? '';
        $this->tokenRequests[] = $form;
        $grant = $form['grant_type'] ?? '';
        if ($grant === 'authorization_code' && ($form['code'] ?? '') !== $this->code) {
            return self::json(400, ['error' => 'invalid_grant', 'error_description' => 'bad code <script>']);
        }
        if ($grant === 'refresh_token' && $this->rejectRefresh) {
            return self::json(400, ['error' => 'invalid_grant']);
        }
        if ($grant !== 'authorization_code' && $grant !== 'refresh_token') {
            return self::json(400, ['error' => 'unsupported_grant_type']);
        }
        $this->issued++;
        $access = 'at-' . $this->issued;
        $this->mcp->tokens[] = $access;

        return self::json(200, [
            'access_token' => $access,
            'refresh_token' => 'rt-' . $this->issued,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'read write',
        ]);
    }

    /**
     * @param int $status The status.
     * @param array<string, mixed> $body The JSON body.
     * @return \CakeDC\Mcp\Client\Http\HttpResponse
     */
    private static function json(int $status, array $body): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => ['application/json']], (string)json_encode($body));
    }
}
