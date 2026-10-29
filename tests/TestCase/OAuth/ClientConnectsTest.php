<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\OAuth;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\Html;
use CakeDC\Mcp\Test\Support\McpHttpTrait;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuth\Server\Grant\Pkce;
use CakeDC\OAuthServer\OAuthServer;
use TestApp\OAuthTestConfig;

/**
 * The discovery and sign-in a remote MCP client goes through, from the first 401 to a tool call,
 * a step-up refusal and revocation.
 */
class ClientConnectsTest extends TestCase
{
    use IntegrationTestTrait;
    use McpHttpTrait;

    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const REDIRECT = 'http://127.0.0.1:53682/callback';

    protected array $fixtures = OAuthFixtures::ALL;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        OAuthServer::reset();
    }

    protected function tearDown(): void
    {
        OAuthServer::reset();
        parent::tearDown();
    }

    /**
     * @param string $path The path to get.
     * @return array<string, mixed>
     */
    private function getJson(string $path): array
    {
        $this->_request['headers'] = ['Accept' => 'application/json'];
        $this->get($path);
        $this->assertResponseOk();

        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    public function testAClientDiscoversSignsInCallsToolsAndIsRevoked(): void
    {
        // 1. The first call is challenged with the metadata URL.
        $this->listTools();
        $this->assertResponseCode(401);
        $metadataUrl = $this->challenge()['resource_metadata'] ?? '';
        $this->assertSame('http://localhost/.well-known/oauth-protected-resource/mcp', $metadataUrl);

        // 2. The metadata names the authorization server, whose own metadata names the endpoints.
        $resource = $this->getJson((string)parse_url($metadataUrl, PHP_URL_PATH));
        $this->assertSame(['http://localhost'], $resource['authorization_servers']);
        $server = $this->getJson('/.well-known/oauth-authorization-server');
        $this->assertSame('http://localhost', $server['issuer']);

        // 3. Dynamic registration, as Claude Code does.
        $this->_request['headers'] = ['Content-Type' => 'application/json'];
        $this->post((string)parse_url($server['registration_endpoint'], PHP_URL_PATH), (string)json_encode([
            'client_name' => 'Claude Code',
            'redirect_uris' => [self::REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]));
        $this->assertResponseCode(201);
        $clientId = (string)((array)json_decode((string)$this->_response->getBody(), true))['client_id'];

        // 4. The person signs in with the application's own login and approves.
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'state' => 's',
            'scope' => 'notes:read',
            'code_challenge' => Pkce::challengeFor(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'resource' => $resource['resource'],
        ], '', '&', PHP_QUERY_RFC3986);
        $this->_request['headers'] = [];
        $authorize = (string)parse_url($server['authorization_endpoint'], PHP_URL_PATH);
        $this->session(['Auth' => ['id' => 1]]);
        $this->get($authorize . '?' . $query);
        $this->assertResponseOk();
        $fields = Html::hiddenFields((string)$this->_response->getBody(), 'consent_token');
        unset($fields['_csrfToken']);
        $this->enableCsrfToken();
        $this->post($authorize, $fields + ['decision' => 'approve']);
        $location = $this->_response->getHeaderLine('Location');
        $this->assertStringStartsWith(self::REDIRECT . '?', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame('http://localhost', $params['iss'] ?? null);

        // 5. The code is exchanged for tokens bound to the MCP resource.
        $this->_request['headers'] = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $this->post((string)parse_url($server['token_endpoint'], PHP_URL_PATH), [
            'grant_type' => 'authorization_code',
            'code' => $params['code'] ?? '',
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
            'client_id' => $clientId,
            'resource' => $resource['resource'],
        ]);
        $this->assertResponseOk();
        $tokens = (array)json_decode((string)$this->_response->getBody(), true);
        $access = (string)$tokens['access_token'];

        // 6. Tools work as the person who signed in.
        $this->callTool('whoami', [], $access);
        $this->assertResponseOk();
        $whoami = json_decode((string)($this->responseJson()['result']['content'][0]['text'] ?? ''), true);
        $this->assertSame(1, $whoami['identifier'] ?? null);

        // 7. A tool beyond the granted scopes asks the client to step up.
        $this->callTool('create_note', ['title' => 'Groceries'], $access);
        $this->assertResponseCode(403);
        $this->assertEqualsCanonicalizing(
            ['notes:read', 'notes:write'],
            explode(' ', $this->challenge()['scope'] ?? ''),
        );

        // 8. Revoked, the token stops working at once.
        $this->_request['headers'] = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $this->post((string)parse_url($server['revocation_endpoint'], PHP_URL_PATH), [
            'token' => $access,
            'client_id' => $clientId,
        ]);
        $this->assertResponseOk();
        $this->callTool('whoami', [], $access);
        $this->assertResponseCode(401);
    }
}
