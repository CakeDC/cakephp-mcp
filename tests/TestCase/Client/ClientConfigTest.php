<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\CakeHttpClientFactory;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\Exception\ClientException;
use CakeDC\Mcp\Client\Store\TableConnectionStore;
use InvalidArgumentException;
use Mcp\Schema\Enum\ProtocolVersion;

class ClientConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     * @return void
     */
    private function expectError(array $config, string $message): void
    {
        Configure::write('Mcp.clients.broken', $config);
        try {
            ClientConfig::fromConfig('broken');
            $this->fail('No exception for ' . json_encode($config));
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function testAppModeWithTokenSetsTheBearerHeader(): void
    {
        Configure::write('Mcp.clients.github', [
            'url' => 'https://api.example.com/mcp',
            'token' => 'secret-token',
            'headers' => ['X-Team' => 'core'],
            'timeout' => 12,
            'tools' => ['only' => ['get_*'], 'except' => ['get_secret']],
        ]);

        $config = ClientConfig::fromConfig('github');

        $this->assertSame('github', $config->name);
        $this->assertSame('https://api.example.com/mcp', $config->url);
        $this->assertSame(['X-Team' => 'core', 'Authorization' => 'Bearer secret-token'], $config->headers);
        $this->assertSame(12, $config->timeout);
        $this->assertSame(['get_*'], $config->toolsOnly);
        $this->assertSame(['get_secret'], $config->toolsExcept);
        $this->assertFalse($config->isUser());
        $this->assertFalse($config->isStdio());
        $this->assertNull($config->protocolVersion);
    }

    public function testSharedSettingsOfTheWrongTypeAreReported(): void
    {
        $this->assertSame([], ClientConfig::errors());
        $bad = [
            'name' => '',
            'version' => 2,
            'refreshLeeway' => -1,
            'maxResponseBytes' => '1MB',
            'identityAttribute' => ['identity'],
            'encryptionKey' => 12345,
            'proxy' => 'socks5://proxy.corp.example',
            'proxi' => 'http://proxy.corp.example',
        ];
        try {
            foreach ($bad as $key => $value) {
                Configure::write('Mcp.client.' . $key, $value);
                $errors = ClientConfig::errors();
                $this->assertArrayHasKey('Mcp.client.' . $key, $errors, $key);
                $this->assertStringContainsString('"Mcp.client.' . $key . '"', $errors['Mcp.client.' . $key]);
                Configure::delete('Mcp.client.' . $key);
            }
            foreach (['refreshLeeway' => 0, 'maxResponseBytes' => 2048, 'encryptionKey' => null, 'version' => '2.0'] as $key => $value) {
                Configure::write('Mcp.client.' . $key, $value);
                $this->assertSame([], ClientConfig::errors(), $key);
            }
        } finally {
            Configure::delete('Mcp.client');
        }
    }

    public function testTheProxyIsOffByDefault(): void
    {
        $this->assertNull(ClientConfig::proxy());
    }

    public function testTheProxyIsReadAndChecked(): void
    {
        Configure::write('Mcp.client.proxy', 'http://proxy.corp.example:3128');
        $this->assertSame('http://proxy.corp.example:3128', ClientConfig::proxy());

        Configure::write('Mcp.client.proxy', 'socks5h://proxy.corp.example:1080');
        $this->assertSame('socks5h://proxy.corp.example:1080', ClientConfig::proxy());

        // socks5:// resolves the name in the application, not in the proxy: socks5h:// is the one that works here.
        $bads = ['proxy.corp.example:3128', 'ftp://proxy.corp.example', 'http://', 'http://proxy.corp.example/path', 42, ''];
        foreach ([...$bads, 'socks5://proxy.corp.example:1080'] as $bad) {
            Configure::write('Mcp.client.proxy', $bad);
            try {
                ClientConfig::proxy();
                $this->fail('Accepted ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('"Mcp.client.proxy"', $e->getMessage());
            }
        }
        Configure::delete('Mcp.client.proxy');
    }

    public function testTheHttpClientsGoThroughTheProxy(): void
    {
        Configure::write('Mcp.client.proxy', 'http://proxy.corp.example:3128');
        Configure::write('Mcp.clients.remote', ['url' => 'https://mcp.example.com/mcp']);
        try {
            $client = (new CakeHttpClientFactory())->create(ClientConfig::fromConfig('remote'));
            $this->assertInstanceOf(Client::class, $client);
            $this->assertSame(['proxy' => 'http://proxy.corp.example:3128'], $client->getConfig('proxy'));
            // This machine is reached directly (the proxy's localhost is another one), and NO_PROXY in the
            // environment decides nothing else.
            $this->assertSame([CURLOPT_NOPROXY => 'localhost,127.0.0.1,::1'], $client->getConfig('curl'));
        } finally {
            Configure::delete('Mcp.client.proxy');
        }
    }

    public function testEveryConfiguredAddressOnThisMachineBypassesTheProxy(): void
    {
        // The URL policy takes all of 127.0.0.0/8 and *.localhost as this machine (plain http allowed): none of
        // them crosses the proxy, whatever curl knows of address ranges.
        Configure::write('Mcp.client.proxy', 'http://proxy.corp.example:3128');
        Configure::write('Mcp.clients.local', [
            'url' => 'http://127.0.0.2:8080/mcp',
            'auth' => 'user',
            'clientId' => 'app',
            'authorizationUrl' => 'http://127.0.0.3/authorize',
            'tokenUrl' => 'http://127.0.0.3/token',
            'revocationUrl' => 'https://auth.example.com/revoke',
        ]);
        try {
            $client = (new CakeHttpClientFactory())->create(ClientConfig::fromConfig('local'));
            $this->assertInstanceOf(Client::class, $client);
            $this->assertSame([CURLOPT_NOPROXY => 'localhost,127.0.0.1,::1,127.0.0.2,127.0.0.3'], $client->getConfig('curl'));
        } finally {
            Configure::delete('Mcp.client.proxy');
        }
    }

    public function testStdioClient(): void
    {
        Configure::write('Mcp.clients.local', [
            'command' => 'php',
            'args' => ['server.php', '--quiet'],
            'env' => ['API_KEY' => 'k'],
            'cwd' => '/tmp',
        ]);

        $config = ClientConfig::fromConfig('local');

        $this->assertTrue($config->isStdio());
        $this->assertSame('php', $config->command);
        $this->assertSame(['server.php', '--quiet'], $config->args);
        $this->assertSame(['API_KEY' => 'k'], $config->env);
        $this->assertSame('/tmp', $config->cwd);
        $this->assertSame(30, $config->timeout);
    }

    public function testUrlOrCommandIsRequiredButNotBoth(): void
    {
        $this->expectError([], 'needs a "url" (an HTTP server) or a "command"');
        $this->expectError(['url' => 'https://a.example.com', 'command' => 'php'], 'not both');
    }

    public function testUnknownClientFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.clients.nope" is not configured.');

        ClientConfig::fromConfig('nope');
    }

    public function testUnsetTokenFails(): void
    {
        $this->expectError(['url' => 'https://a.example.com', 'token' => null], '"Mcp.clients.broken.token" is empty');
        $this->expectError(['url' => 'https://a.example.com', 'token' => ''], '"Mcp.clients.broken.token" is empty');
    }

    public function testATokenReadFromASecretFileLosesItsLineBreak(): void
    {
        // What file_get_contents() of a Docker or Kubernetes secret returns.
        Configure::write('Mcp.clients.github', ['url' => 'https://api.example.com/mcp', 'token' => "robot-access-token\n"]);
        $this->assertSame('Bearer robot-access-token', ClientConfig::fromConfig('github')->headers['Authorization']);

        Configure::write('Mcp.clients.github', ['url' => 'https://api.example.com/mcp', 'token' => fn(): string => " robot-access-token\r\n"]);
        $this->assertSame('robot-access-token', ClientConfig::fromConfig('github')->callableToken());
    }

    public function testATokenAHeaderCannotCarryFailsWithoutQuotingIt(): void
    {
        Configure::write('Mcp.clients.broken', ['url' => 'https://a.example.com', 'token' => "robot-access\ntoken"]);
        try {
            ClientConfig::fromConfig('broken');
            $this->fail('Accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('"Mcp.clients.broken.token" has characters', $e->getMessage());
            $this->assertStringNotContainsString('robot-access', $e->getMessage());
        }

        Configure::write('Mcp.clients.broken', ['url' => 'https://a.example.com', 'token' => fn(): string => "robot-access\ntoken"]);
        try {
            ClientConfig::fromConfig('broken')->callableToken();
            $this->fail('Accepted');
        } catch (ClientException $e) {
            $this->assertStringContainsString('has characters', $e->getMessage());
            $this->assertStringNotContainsString('robot-access', $e->getMessage());
        }
    }

    public function testACacheOnAStdioClientFails(): void
    {
        $this->expectError(
            ['command' => 'php', 'args' => ['server.php'], 'cache' => 'default'],
            '"Mcp.clients.broken.cache" is only for a client over HTTP',
        );
    }

    public function testUserModeNeedsAUrlAndNoToken(): void
    {
        $this->expectError(['command' => 'php', 'auth' => 'user'], 'user mode needs a "url"');
        $this->expectError(
            ['url' => 'https://a.example.com', 'auth' => 'user', 'token' => 't'],
            'user mode signs each user in',
        );
        $this->expectError(
            ['url' => 'https://a.example.com', 'auth' => 'user', 'headers' => ['authorization' => 'Bearer x']],
            'user mode signs each user in',
        );
        $this->expectError(['url' => 'https://a.example.com', 'auth' => 'admin'], '"app" or "user"');

        Configure::write('Mcp.clients.linear', ['url' => 'https://mcp.example.com/mcp', 'auth' => 'user']);
        $this->assertTrue(ClientConfig::fromConfig('linear')->isUser());
    }

    public function testConfiguredEndpointsNeedAClientId(): void
    {
        $this->expectError(
            ['url' => 'https://a.example.com', 'auth' => 'user', 'tokenUrl' => 'https://a.example.com/token'],
            'needs "authorizationUrl", "tokenUrl" and "clientId" together',
        );
        $this->expectError(
            [
                'url' => 'https://a.example.com', 'auth' => 'user',
                'authorizationUrl' => 'https://a.example.com/authorize', 'tokenUrl' => 'http://a.example.com/token',
                'clientId' => 'abc',
            ],
            '"Mcp.clients.broken.tokenUrl"',
        );

        Configure::write('Mcp.clients.internal', [
            'url' => 'https://mcp.internal/mcp',
            'auth' => 'user',
            'authorizationUrl' => 'https://sso.internal/authorize',
            'tokenUrl' => 'https://sso.internal/token',
            'revocationUrl' => 'https://sso.internal/revoke',
            'clientId' => 'cake-app',
            'clientSecret' => 's3cret',
            'scopes' => ['read', 'write'],
        ]);
        $config = ClientConfig::fromConfig('internal');
        $this->assertSame('https://sso.internal/token', $config->tokenUrl);
        $this->assertSame('cake-app', $config->clientId);
        $this->assertSame(['read', 'write'], $config->scopes);
    }

    public function testOAuthSettingsOnlyInUserMode(): void
    {
        $this->expectError(['url' => 'https://a.example.com', 'clientId' => 'x'], 'only for user mode');
        $this->expectError(['url' => 'https://a.example.com', 'scopes' => ['read']], 'only for user mode');
        $this->expectError(
            ['url' => 'https://a.example.com', 'auth' => 'user', 'clientSecret' => 's'],
            '"Mcp.clients.broken.clientSecret" needs "clientId"',
        );
    }

    public function testASecretWithDiscoveryNeedsTheIssuer(): void
    {
        $this->expectError(
            ['url' => 'https://a.example.com', 'auth' => 'user', 'clientId' => 'id', 'clientSecret' => 's'],
            'the secret would go to whatever authorization server the MCP server names',
        );

        Configure::write('Mcp.clients.notion', [
            'url' => 'https://mcp.notion.example/mcp',
            'auth' => 'user',
            'clientId' => 'id',
            'clientSecret' => 's',
            'issuer' => 'https://login.notion.example',
        ]);
        $this->assertSame('https://login.notion.example', ClientConfig::fromConfig('notion')->issuer);

        // Without a secret the issuer stays optional.
        Configure::write('Mcp.clients.public', ['url' => 'https://a.example.com', 'auth' => 'user', 'clientId' => 'id']);
        $this->assertNull(ClientConfig::fromConfig('public')->issuer);
    }

    public function testProtocolVersionMustBeKnown(): void
    {
        $this->expectError(['url' => 'https://a.example.com', 'protocolVersion' => '2020-01-01'], 'protocolVersion');

        Configure::write('Mcp.clients.old', ['url' => 'https://a.example.com', 'protocolVersion' => '2025-06-18']);
        $this->assertSame(ProtocolVersion::V2025_06_18, ClientConfig::fromConfig('old')->protocolVersion);
    }

    public function testToolPatternsMustBeStrings(): void
    {
        $this->expectError(['url' => 'https://a.example.com', 'tools' => ['only' => 'get_*']], 'tools.only');
        $this->expectError(['url' => 'https://a.example.com', 'tools' => ['except' => [1]]], 'tools.except');
        $this->expectError(['url' => 'https://a.example.com', 'timeout' => 0], 'timeout');
        $this->expectError(['url' => 'https://a.example.com', 'headers' => ['X' => 1]], 'headers');
    }

    public function testUnknownToolKeysFail(): void
    {
        // A misspelled deny list would otherwise allow every tool.
        $this->expectError(['url' => 'https://a.example.com', 'tools' => ['exclude' => ['delete_*']]], 'tools.exclude');
    }

    public function testNamesAndUserMode(): void
    {
        Configure::write('Mcp.clients', [
            'a' => ['url' => 'https://a.example.com'],
            'b' => ['url' => 'https://b.example.com'],
        ]);
        $this->assertSame(['a', 'b'], ClientConfig::names());
        $this->assertFalse(ClientConfig::anyUserMode());

        Configure::write('Mcp.clients.c', ['url' => 'https://c.example.com', 'auth' => 'user']);
        $this->assertTrue(ClientConfig::anyUserMode());

        Configure::write('Mcp.clients', [
            'bad name' => ['url' => 'https://a.example.com'],
            'a' => ['url' => 'https://a.example.com'],
        ]);
        $this->assertSame(['a'], ClientConfig::names());
        $this->assertStringContainsString('letters, digits', ClientConfig::errors()['bad name']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('letters, digits');
        ClientConfig::fromConfig('bad name');
    }

    public function testSettingsHaveDefaults(): void
    {
        Configure::delete('Mcp.client');
        $this->assertSame('/mcp-client', ClientConfig::setting('route'));
        $this->assertSame(TableConnectionStore::class, ClientConfig::setting('store'));
        $this->assertNull(ClientConfig::setting('encryptionKey'));
        $this->assertSame(60, ClientConfig::setting('refreshLeeway'));
        $this->assertSame(1024 * 1024, ClientConfig::setting('maxResponseBytes'));

        Configure::write('Mcp.client.route', '/connections');
        $this->assertSame('/connections', ClientConfig::setting('route'));
        $this->assertSame('identity', ClientConfig::setting('identityAttribute'));
    }
}
