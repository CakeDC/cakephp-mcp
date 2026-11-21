<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\McpHttpTrait;
use TestApp\OwnRouteApplication;
use TestApp\ScopedPluginApplication;
use TestApp\TestTokens;

/**
 * A route the application connects itself to the plugin's controller serves the default server, as in 2.1.
 */
class OwnRouteTest extends TestCase
{
    use IntegrationTestTrait;
    use McpHttpTrait;

    protected function tearDown(): void
    {
        Router::reload();
        parent::tearDown();
    }

    /**
     * tools/list at $path with a CSRF token: McpConfig::isEndpoint() only knows Mcp.route.
     *
     * @param string $path The path.
     * @return void
     */
    private function listToolsAt(string $path): void
    {
        $csrf = (new CsrfProtectionMiddleware())->createToken();
        $this->cookie('csrfToken', $csrf);
        $this->_request['headers'] = $this->mcpHeaders('tools/list', null, TestTokens::EDITOR) + [
            'Host' => 'localhost',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'X-CSRF-Token' => $csrf,
        ];
        $this->post($path, (string)json_encode($this->mcpMessage(1, 'tools/list')));
    }

    public function testARouteOfTheApplicationServesTheDefaultServer(): void
    {
        $this->configApplication(OwnRouteApplication::class, [CONFIG]);

        $this->listToolsAt('/internal/mcp');

        $this->assertResponseOk();
        $this->assertContains('echo', array_column($this->responseJson()['result']['tools'] ?? [], 'name'));
    }

    public function testARouteOfTheApplicationServesTheDefaultServerWhenThePluginConnectsNone(): void
    {
        $this->configApplication(OwnRouteApplication::class, [CONFIG]);
        Configure::write('Mcp.route', false);

        $this->listToolsAt('/internal/mcp');

        $this->assertResponseOk();
    }

    public function testThePluginRoutesLoadedInsideAScopeServeTheDefaultServer(): void
    {
        $this->configApplication(ScopedPluginApplication::class, [CONFIG]);

        $this->listToolsAt('/api/mcp');

        $this->assertResponseOk();
    }
}
