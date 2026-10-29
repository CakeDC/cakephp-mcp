<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\OAuth;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\OAuth\AuthSettings;
use InvalidArgumentException;
use stdClass;

class AuthSettingsTest extends TestCase
{
    public function testHostModeByDefault(): void
    {
        $settings = AuthSettings::fromConfig();

        $this->assertSame(AuthSettings::HOST, $settings->mode);
        $this->assertSame('', $settings->resource);
        $this->assertNull($settings->scopePolicy());
        $this->assertNull($settings->external);
    }

    public function testHostModeDoesNotNeedAnHttpsBaseUrl(): void
    {
        Configure::write('App.fullBaseUrl', 'http://intranet.example');

        $this->assertSame(AuthSettings::HOST, AuthSettings::fromConfig()->mode);
    }

    public function testUnknownModeIsRejected(): void
    {
        Configure::write('Mcp.auth.mode', 'session');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.auth.mode"');
        AuthSettings::fromConfig();
    }

    public function testUnknownKeysAreRejected(): void
    {
        foreach (
            [
                ['Mcp.auth.scope', ['default' => ['x']], 'Mcp.auth.scope'],
                ['Mcp.auth.scopes.tool', ['a' => ['b']], 'Mcp.auth.scopes.tool'],
                ['Mcp.auth.external.issuerUrl', 'https://x.example', 'Mcp.auth.external.issuerUrl'],
            ] as [$path, $value, $named]
        ) {
            Configure::write('Mcp', ['auth' => ['mode' => 'oauth']]);
            Configure::write($path, $value);
            try {
                AuthSettings::fromConfig();
                $this->fail('No exception for ' . $path);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('"' . $named . '"', $e->getMessage());
            }
        }
    }

    public function testResourceDerivesFromTheBaseUrlAndTheRoute(): void
    {
        Configure::write('App.fullBaseUrl', 'HTTPS://Example.COM:443');
        Configure::write('Mcp.route', '/mcp');
        Configure::write('Mcp.auth.mode', 'oauth');

        $this->assertSame('https://example.com/mcp', AuthSettings::fromConfig()->resource);
    }

    public function testResourceKeepsTheBasePathOfASubdirectoryInstall(): void
    {
        Configure::write('App.fullBaseUrl', 'https://example.com');
        Configure::write('App.base', '/app');
        Configure::write('Mcp.auth.mode', 'oauth');

        $this->assertSame('https://example.com/app/mcp', AuthSettings::fromConfig()->resource);
    }

    public function testExplicitResourceIsCanonicalised(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'oauth', 'resource' => 'https://MCP.example.com:8443/mcp/']);

        $this->assertSame('https://mcp.example.com:8443/mcp', AuthSettings::fromConfig()->resource);
    }

    public function testPlainHttpIsOnlyAcceptedForLoopbackHosts(): void
    {
        Configure::write('Mcp.auth.mode', 'oauth');
        Configure::write('App.fullBaseUrl', 'http://localhost:8765');
        $this->assertSame('http://localhost:8765/mcp', AuthSettings::fromConfig()->resource);

        Configure::write('App.fullBaseUrl', 'http://example.com');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('App.fullBaseUrl');
        AuthSettings::fromConfig();
    }

    public function testResourceWithAFragmentOrCredentialsIsRejected(): void
    {
        foreach (['https://example.com/mcp#x', 'https://user:pass@example.com/mcp'] as $resource) {
            Configure::write('Mcp.auth', ['mode' => 'oauth', 'resource' => $resource]);
            try {
                AuthSettings::fromConfig();
                $this->fail('Accepted ' . $resource);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('"Mcp.auth.resource"', $e->getMessage());
            }
        }
    }

    public function testScopesMustBeListsOfScopeNames(): void
    {
        foreach (
            [
                ['default' => 'notes:read'],
                ['default' => ['notes read']],
                ['methods' => ['tools/call' => 'notes:read']],
                ['tools' => [['notes:read']]],
                ['implies' => ['notes:write' => [42]]],
            ] as $scopes
        ) {
            Configure::write('Mcp.auth', ['mode' => 'oauth', 'scopes' => $scopes]);
            try {
                AuthSettings::fromConfig();
                $this->fail('Accepted ' . json_encode($scopes));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('"Mcp.auth.scopes.', $e->getMessage());
            }
        }
    }

    public function testScopePolicyCombinesDefaultMethodAndToolScopes(): void
    {
        Configure::write('Mcp.auth', [
            'mode' => 'oauth',
            'scopes' => [
                'default' => ['mcp'],
                'methods' => ['tools/call' => ['notes:read']],
                'tools' => ['create_note' => ['notes:write']],
                'implies' => ['notes:write' => ['notes:read']],
            ],
        ]);
        $settings = AuthSettings::fromConfig();
        $policy = $settings->scopePolicy();

        $this->assertNotNull($policy);
        $this->assertEqualsCanonicalizing(
            ['mcp', 'notes:read', 'notes:write'],
            $policy->requiredFor([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'create_note'],
            ]),
        );
        $this->assertEqualsCanonicalizing(['mcp', 'notes:read', 'notes:write'], $settings->configuredScopes());
    }

    public function testIdentityMustBeCallable(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'oauth', 'identity' => 'not a function']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.auth.identity"');
        AuthSettings::fromConfig();
    }

    public function testIdentityCallableBecomesAClosure(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'oauth', 'identity' => 'strtoupper']);

        $this->assertNotNull(AuthSettings::fromConfig()->identity);
    }

    public function testExternalModeNeedsAnHttpsIssuer(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'external']);
        try {
            AuthSettings::fromConfig();
            $this->fail('No issuer accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('"Mcp.auth.external.issuer"', $e->getMessage());
        }

        Configure::write('Mcp.auth.external.issuer', 'http://issuer.example');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.auth.external.issuer"');
        AuthSettings::fromConfig();
    }

    public function testExternalSettingsDefaults(): void
    {
        Configure::write('App.fullBaseUrl', 'https://example.com');
        Configure::write('Mcp.auth', ['mode' => 'external', 'external' => ['issuer' => 'https://tenant.auth0.com/']]);
        $external = AuthSettings::fromConfig()->external;

        $this->assertNotNull($external);
        // The issuer is compared verbatim with the `iss` claim, so it is kept as configured.
        $this->assertSame('https://tenant.auth0.com/', $external->issuer);
        $this->assertSame(['https://example.com/mcp'], $external->audiences);
        $this->assertSame(['RS256'], $external->algorithms);
        $this->assertSame('scope', $external->scopeClaim);
        $this->assertSame('mcp_oauth', $external->cache);
        $this->assertNull($external->httpClient);
    }

    public function testExternalAlgorithmsAreAsymmetricOnly(): void
    {
        foreach (['none', 'HS256'] as $algorithm) {
            Configure::write('Mcp.auth', ['mode' => 'external', 'external' => [
                'issuer' => 'https://issuer.example',
                'algorithms' => [$algorithm],
            ]]);
            try {
                AuthSettings::fromConfig();
                $this->fail('Accepted ' . $algorithm);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('"Mcp.auth.external.algorithms"', $e->getMessage());
            }
        }
    }

    public function testExternalHttpClientMustBePsr18(): void
    {
        Configure::write('Mcp.auth', ['mode' => 'external', 'external' => [
            'issuer' => 'https://issuer.example',
            'httpClient' => new stdClass(),
        ]]);
        try {
            AuthSettings::fromConfig();
            $this->fail('Accepted a non PSR-18 client');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('"Mcp.auth.external.httpClient"', $e->getMessage());
        }

        Configure::write('Mcp.auth.external.httpClient', new Client());
        $this->assertInstanceOf(Client::class, AuthSettings::fromConfig()->external?->httpClient);
    }

    public function testEmptyAuthArrayKeepsHostMode(): void
    {
        Configure::write('Mcp.auth', []);

        $this->assertSame(AuthSettings::HOST, AuthSettings::fromConfig()->mode);
    }

    public function testNonArrayAuthFailsClosed(): void
    {
        foreach (['oauth', null] as $auth) {
            Configure::write('Mcp.auth', $auth);
            try {
                AuthSettings::fromConfig();
                $this->fail('Accepted ' . var_export($auth, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('"Mcp.auth"', $e->getMessage());
            }
        }
    }

    public function testNonArrayScopesOrExternalFailClosed(): void
    {
        $cases = [
            ['scopes' => 'notes:read', 'named' => '"Mcp.auth.scopes"'],
            ['scopes' => null, 'named' => '"Mcp.auth.scopes"'],
            ['external' => 'x', 'named' => '"Mcp.auth.external"'],
            ['external' => null, 'named' => '"Mcp.auth.external"'],
        ];
        foreach ($cases as $case) {
            $named = $case['named'];
            unset($case['named']);
            Configure::write('Mcp.auth', ['mode' => 'oauth'] + $case);
            try {
                AuthSettings::fromConfig();
                $this->fail('Accepted ' . json_encode($case));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($named, $e->getMessage());
            }
        }
    }

    public function testNullScopeMapFailsClosed(): void
    {
        foreach (['default', 'methods', 'tools', 'implies'] as $key) {
            Configure::write('Mcp.auth', ['mode' => 'oauth', 'scopes' => [$key => null]]);
            try {
                AuthSettings::fromConfig();
                $this->fail('Accepted a null ' . $key);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('"Mcp.auth.scopes.' . $key . '"', $e->getMessage());
            }
        }
    }

    public function testNullModeIsRejected(): void
    {
        Configure::write('Mcp.auth', ['mode' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.auth.mode"');
        AuthSettings::fromConfig();
    }
}
