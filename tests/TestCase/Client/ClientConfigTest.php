<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ClientConfig;
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
