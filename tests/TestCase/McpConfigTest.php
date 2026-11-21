<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use CakeDC\Auth\Rbac\Rbac;
use CakeDC\Mcp\Authorization\AllowAllToolAuthorizer;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\McpPlugin;
use InvalidArgumentException;
use Mcp\Server\Transport\StreamableHttpTransport;

class McpConfigTest extends TestCase
{
    public function testDefaultsApplyWhenTheHostConfiguresNothing(): void
    {
        Configure::delete('Mcp');

        $this->assertSame('/mcp', McpConfig::get('route'));
        $this->assertSame(['src/Mcp'], McpConfig::get('discovery.scanDirs'));
        $this->assertTrue(McpConfig::get('identity.required'));
        $this->assertSame(AllowAllToolAuthorizer::class, McpConfig::get('authorization.authorizer'));
        $this->assertTrue(McpConfig::get('stdio.trusted'));
    }

    public function testHostValuesOverrideAndKeepSiblingDefaults(): void
    {
        Configure::write('Mcp', ['server' => ['name' => 'Host Server']]);

        $this->assertSame('Host Server', McpConfig::get('server.name'));
        $this->assertSame('1.0.0', McpConfig::get('server.version'));
    }

    public function testListsAreReplacedNotAppended(): void
    {
        Configure::write('Mcp.discovery.scanDirs', ['src/Tools']);

        $this->assertSame(['src/Tools'], McpConfig::get('discovery.scanDirs'));
    }

    public function testRouteCanBeDisabledWithFalse(): void
    {
        Configure::write('Mcp.route', false);

        $this->assertFalse(McpConfig::get('route'));
    }

    public function testDiscoveryCacheIsOffInDebugAndNamedOtherwise(): void
    {
        Configure::write('debug', true);
        $this->assertNull(McpConfig::discoveryCache());

        Configure::write('debug', false);
        $this->assertSame('_cake_mcp_', McpConfig::discoveryCache());

        Configure::write('Mcp.discovery.cache', false);
        $this->assertNull(McpConfig::discoveryCache());
    }

    public function testAllowedHostsDefaultToFullBaseUrlHostPlusLocalhost(): void
    {
        Configure::write('App.fullBaseUrl', 'https://MCP.Example.com:8443');

        $this->assertSame(['mcp.example.com', 'localhost', '127.0.0.1', '[::1]'], McpConfig::allowedHosts());
    }

    public function testExplicitAllowedHostsAreUsedAsGivenLowercased(): void
    {
        Configure::write('Mcp.http.allowedHosts', ['API.example.com']);

        $this->assertSame(['api.example.com'], McpConfig::allowedHosts());
    }

    public function testEmptyArrayOverAnAssociativeDefaultKeepsTheDefault(): void
    {
        Configure::write('Mcp.identity', []);

        $this->assertTrue(McpConfig::get('identity.required'));
        $this->assertSame('identity', McpConfig::get('identity.attribute'));
    }

    public function testEmptyListOverAListDefaultStillReplaces(): void
    {
        Configure::write('Mcp.discovery.scanDirs', []);

        $this->assertSame([], McpConfig::get('discovery.scanDirs'));
    }

    public function testIsEndpointMatchesTheRouteAndItsMetadata(): void
    {
        foreach (['/mcp', '/mcp/', '/.well-known/oauth-protected-resource/mcp'] as $path) {
            $this->assertTrue(McpConfig::isEndpoint(new ServerRequest(['url' => $path])), $path);
        }
        foreach (['/mcpx', '/api/mcp', '/', '/.well-known/oauth-protected-resource'] as $path) {
            $this->assertFalse(McpConfig::isEndpoint(new ServerRequest(['url' => $path])), $path);
        }
    }

    public function testIsEndpointIsFalseWhenTheRouteIsDisabled(): void
    {
        Configure::write('Mcp.route', false);

        $this->assertFalse(McpConfig::isEndpoint(new ServerRequest(['url' => '/mcp'])));
    }

    public function testRbacPermissionsBypassAuthForTheEndpointAndItsMetadata(): void
    {
        $this->assertSame([
            [
                'role' => '*',
                'plugin' => 'CakeDC/Mcp',
                'controller' => ['Mcp', 'ProtectedResource'],
                'action' => ['handle', 'metadata'],
                'bypassAuth' => true,
            ],
        ], McpConfig::rbacPermissions());
    }

    public function testRbacPermissionsLetAnonymousRequestsReachThePluginOnly(): void
    {
        Router::reload();
        (new McpPlugin())->routes(Router::createRouteBuilder('/'));
        $rbac = new Rbac(['permissions' => McpConfig::rbacPermissions(), 'log' => false]);
        $routed = fn(string $url, string $method): ServerRequest => (new ServerRequest([
            'url' => $url,
            'environment' => ['REQUEST_METHOD' => $method],
        ]))->withAttribute('params', Router::parseRequest(new ServerRequest([
            'url' => $url,
            'environment' => ['REQUEST_METHOD' => $method],
        ])));

        try {
            $this->assertTrue($rbac->checkPermissions([], $routed('/mcp', 'POST')));
            $this->assertTrue($rbac->checkPermissions([], $routed('/.well-known/oauth-protected-resource/mcp', 'GET')));
            $other = (new ServerRequest(['url' => '/users/delete']))->withAttribute('params', [
                'plugin' => null,
                'controller' => 'Users',
                'action' => 'delete',
            ]);
            $this->assertFalse($rbac->checkPermissions([], $other));
        } finally {
            Router::reload();
        }
    }

    public function testTheBodyLimitDefaultsToTheSdkLimit(): void
    {
        Configure::delete('Mcp');

        $this->assertSame(StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES, McpConfig::maxBodyBytes());
        $this->assertSame(4194304, McpConfig::get('http.maxBodyBytes'));
    }

    public function testWithoutServersOnlyDefaultExists(): void
    {
        $this->assertSame(['default'], McpConfig::servers());
        $this->assertSame(McpConfig::get('route'), McpConfig::get('route', 'default'));
        $this->assertArrayNotHasKey('servers', McpConfig::get());
    }

    public function testNamedServerMergesNonSecuritySectionsKeyByKey(): void
    {
        Configure::write('Mcp.server.version', '3.1.0');
        Configure::write('Mcp.http.maxBodyBytes', 2048);
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'server' => ['name' => 'Admin MCP']]);

        $this->assertSame(['default', 'admin'], McpConfig::servers());
        $this->assertSame('/mcp/admin', McpConfig::get('route', 'admin'));
        $this->assertSame('Admin MCP', McpConfig::get('server.name', 'admin'));
        $this->assertSame('3.1.0', McpConfig::get('server.version', 'admin'));
        $this->assertSame(2048, McpConfig::maxBodyBytes('admin'));
        $this->assertArrayNotHasKey('servers', McpConfig::get(null, 'admin'));
    }

    public function testSecuritySectionsAreReplacedWhole(): void
    {
        Configure::write('Mcp.authorization.authorizer', 'App\\Mcp\\CustomAuthorizer');
        Configure::write('Mcp.auth.scopes.tools', ['refund' => ['mcp:refund']]);
        Configure::write('Mcp.discovery.excludeDirs', ['src/Mcp/Internal']);
        Configure::write('Mcp.servers.admin', [
            'route' => '/mcp/admin',
            'authorization' => ['authorizer' => AllowAllToolAuthorizer::class, 'rbac' => [['role' => 'admin']]],
            'auth' => ['mode' => 'host', 'scopes' => ['default' => ['mcp:admin']]],
            'discovery' => ['scanDirs' => ['src/Mcp/Admin']],
        ]);

        $this->assertSame(AllowAllToolAuthorizer::class, McpConfig::get('authorization.authorizer', 'admin'));
        $this->assertSame([['role' => 'admin']], McpConfig::get('authorization.rbac', 'admin'));
        $this->assertSame([], McpConfig::get('auth.scopes.tools', 'admin'));
        $this->assertSame(['mcp:admin'], McpConfig::get('auth.scopes.default', 'admin'));
        $this->assertSame([], McpConfig::get('discovery.excludeDirs', 'admin'));
        $this->assertSame(['src/Mcp/Admin'], McpConfig::get('discovery.scanDirs', 'admin'));
    }

    public function testSecuritySectionsAreInheritedWhenNotSet(): void
    {
        Configure::write('Mcp.authorization.authorizer', 'App\\Mcp\\CustomAuthorizer');
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin']);

        $this->assertSame('App\\Mcp\\CustomAuthorizer', McpConfig::get('authorization.authorizer', 'admin'));
        $this->assertSame(['src/Mcp'], McpConfig::get('discovery.scanDirs', 'admin'));
    }

    public function testServerConfigureIsReplacedOnlyWhenSet(): void
    {
        $hook = fn(): null => null;
        $other = fn(): null => null;
        Configure::write('Mcp.server.configure', $hook);
        Configure::write('Mcp.servers.inherits', ['route' => '/mcp/a', 'server' => ['name' => 'A']]);
        Configure::write('Mcp.servers.own', ['route' => '/mcp/b', 'server' => ['configure' => $other]]);
        Configure::write('Mcp.servers.none', ['route' => '/mcp/c', 'server' => ['configure' => null]]);

        $this->assertSame($hook, McpConfig::get('server.configure', 'inherits'));
        $this->assertSame($other, McpConfig::get('server.configure', 'own'));
        $this->assertNull(McpConfig::get('server.configure', 'none'));
    }

    public function testANullOrEmptySecuritySectionFailsClosed(): void
    {
        foreach (['auth', 'authorization', 'discovery'] as $section) {
            foreach ([null, [], 'yes'] as $value) {
                Configure::write('Mcp.servers', ['admin' => ['route' => '/mcp/admin', $section => $value]]);
                try {
                    McpConfig::get('route', 'admin');
                    $this->fail(sprintf('Accepted %s => %s', $section, var_export($value, true)));
                } catch (InvalidArgumentException $e) {
                    $this->assertStringContainsString(sprintf('"Mcp.servers.admin.%s"', $section), $e->getMessage());
                }
            }
        }
    }

    public function testServerNamesAreSlugs(): void
    {
        Configure::write('Mcp.servers', ['back office' => ['route' => '/mcp/bo'], 'admin' => ['route' => '/mcp/admin']]);

        $this->assertSame(['default', 'admin'], McpConfig::servers());
        $this->assertStringContainsString('letters, digits, "-" and "_"', McpConfig::serverErrors()['back office']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('letters, digits, "-" and "_"');

        McpConfig::get('route', 'back office');
    }

    public function testForReturnsTheResolvedConfig(): void
    {
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin', 'servers' => ['nested' => ['route' => '/x']]]);

        $this->assertSame(McpConfig::get(null, 'admin'), McpConfig::for('admin'));
        $this->assertArrayNotHasKey('servers', McpConfig::for('admin'));
    }

    public function testUnknownServerFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.servers.nope" is not configured.');

        McpConfig::get('route', 'nope');
    }

    public function testNamedServerNeedsARoute(): void
    {
        Configure::write('Mcp.servers.admin', ['server' => ['name' => 'Admin']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.servers.admin.route"');

        McpConfig::get('route', 'admin');
    }

    public function testServersCannotBeNamedDefault(): void
    {
        Configure::write('Mcp.servers.default', ['route' => '/other']);

        $this->assertSame(['default'], McpConfig::servers());
        $this->assertStringContainsString('"Mcp.servers.default"', McpConfig::serverErrors()['default']);
        $this->assertSame('/mcp', McpConfig::get('route'));
    }

    public function testIsEndpointAndServerForMatchEveryServer(): void
    {
        Configure::write('Mcp.servers.admin', ['route' => '/mcp/admin']);

        $cases = [
            '/mcp' => 'default',
            '/.well-known/oauth-protected-resource/mcp' => 'default',
            '/mcp/admin/' => 'admin',
            '/.well-known/oauth-protected-resource/mcp/admin' => 'admin',
            '/api/mcp' => null,
        ];
        foreach ($cases as $path => $server) {
            $request = new ServerRequest(['url' => $path]);
            $this->assertSame($server, McpConfig::serverFor($request), $path);
            $this->assertSame($server !== null, McpConfig::isEndpoint($request), $path);
        }
    }

    public function testTheBodyLimitMustBeAPositiveInteger(): void
    {
        Configure::write('Mcp.http.maxBodyBytes', 1024);
        $this->assertSame(1024, McpConfig::maxBodyBytes());

        foreach ([0, -1, '1024', 1.5, null] as $invalid) {
            Configure::write('Mcp.http.maxBodyBytes', $invalid);
            try {
                McpConfig::maxBodyBytes();
                $this->fail('Accepted ' . var_export($invalid, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Mcp.http.maxBodyBytes', $e->getMessage());
            }
        }
    }

    public function testAnInheritedResourceFollowsTheNamedServersRoute(): void
    {
        $cases = [
            'https://example.com/mcp' => 'https://example.com/mcp/admin',
            // Behind a proxy that adds a path prefix: the prefix stays.
            'https://example.com/api/mcp' => 'https://example.com/api/mcp/admin',
            'https://example.com:8443/elsewhere' => 'https://example.com:8443/mcp/admin',
        ];
        foreach ($cases as $resource => $expected) {
            Configure::write('Mcp.auth', ['mode' => 'oauth', 'resource' => $resource]);
            Configure::write('Mcp.servers', ['admin' => ['route' => '/mcp/admin']]);

            $this->assertSame($resource, McpConfig::get('auth.resource'));
            $this->assertSame($expected, McpConfig::get('auth.resource', 'admin'), $resource);
        }

        Configure::write('Mcp.servers.admin.auth', ['mode' => 'oauth', 'resource' => 'https://example.com/own']);
        $this->assertSame('https://example.com/own', McpConfig::get('auth.resource', 'admin'));
    }

    public function testAnInheritedExternalAudienceFails(): void
    {
        Configure::write('Mcp.auth', [
            'mode' => 'external',
            'external' => ['issuer' => 'https://login.example.com', 'audience' => 'api://mcp'],
        ]);
        Configure::write('Mcp.servers', ['admin' => ['route' => '/mcp/admin']]);

        $this->assertArrayHasKey('admin', McpConfig::serverErrors());
        $this->assertStringContainsString('"Mcp.servers.admin.auth"', McpConfig::serverErrors()['admin']);
        $this->assertStringContainsString('audience', McpConfig::serverErrors()['admin']);

        Configure::write('Mcp.servers.admin.auth', [
            'mode' => 'external',
            'external' => ['issuer' => 'https://login.example.com', 'audience' => 'api://mcp-admin'],
        ]);
        $this->assertSame([], McpConfig::serverErrors());
    }

    public function testANamedServersAuthNeedsAMode(): void
    {
        Configure::write('Mcp.servers', ['admin' => ['route' => '/mcp/admin', 'auth' => ['scopes' => ['default' => ['admin']]]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.servers.admin.auth.mode"');

        McpConfig::get('auth', 'admin');
    }

    public function testANamedServersAuthorizationNeedsAnAuthorizer(): void
    {
        Configure::write('Mcp.servers', ['admin' => ['route' => '/mcp/admin', 'authorization' => ['rbac' => [['role' => 'admin']]]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.servers.admin.authorization.authorizer"');

        McpConfig::get('authorization', 'admin');
    }

    public function testUnknownKeysOfANamedServerFail(): void
    {
        $cases = [
            'authorisation' => ['authorisation' => ['authorizer' => 'X']],
            'authorization.authoriser' => ['authorization' => ['authorizer' => 'X', 'authoriser' => 'Y']],
        ];
        foreach ($cases as $key => $config) {
            Configure::write('Mcp.servers', ['admin' => ['route' => '/mcp/admin'] + $config]);
            try {
                McpConfig::get('route', 'admin');
                $this->fail('Accepted ' . $key);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('"Mcp.servers.admin.' . $key . '"', $e->getMessage());
            }
        }
    }

    public function testIdentityIsReplacedWhole(): void
    {
        Configure::write('Mcp.identity', ['required' => false, 'attribute' => 'user']);
        Configure::write('Mcp.servers', ['admin' => ['route' => '/mcp/admin']]);

        $this->assertTrue(McpConfig::get('identity.required', 'admin'));
        $this->assertSame('identity', McpConfig::get('identity.attribute', 'admin'));

        Configure::write('Mcp.servers.admin.identity', ['required' => false]);
        $this->assertFalse(McpConfig::get('identity.required', 'admin'));
    }
}
