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
            'authorization' => ['rbac' => [['role' => 'admin']]],
            'auth' => ['scopes' => ['default' => ['mcp:admin']]],
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

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.servers.default"');

        McpConfig::servers();
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
}
