<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\McpHttpTrait;
use CakeDC\Mcp\Test\Support\OAuthFixtures;
use CakeDC\OAuthServer\OAuthServer;
use TestApp\OAuthTestConfig;
use TestApp\TestTokens;

/**
 * `Mcp.http.maxBodyBytes` at the endpoint: an oversized body is answered with 413 and a JSON-RPC error
 * before any authentication, in every mode.
 */
class McpBodyLimitTest extends TestCase
{
    use IntegrationTestTrait;
    use McpHttpTrait;

    protected array $fixtures = OAuthFixtures::ALL;

    protected function tearDown(): void
    {
        OAuthServer::reset();
        parent::tearDown();
    }

    public function testAnOversizedBodyIsAnswered413BeforeAuthentication(): void
    {
        Configure::write('Mcp.http.maxBodyBytes', 2048);

        $this->callTool('echo', ['text' => str_repeat('a', 4096)]);

        $this->assertResponseCode(413);
        $this->assertSame('', $this->_response?->getHeaderLine('WWW-Authenticate'));
        $error = $this->responseJson()['error'] ?? [];
        $this->assertSame(-32600, $error['code'] ?? null);
        $this->assertSame(2048, $error['data']['maxBodyBytes'] ?? null);
        $this->assertStringContainsString('the limit is 2048 bytes', (string)($error['message'] ?? ''));
    }

    public function testInOAuthModeAnOversizedBodyIsNotABearerChallenge(): void
    {
        Configure::write('Mcp.auth', OAuthTestConfig::MCP_AUTH);
        OAuthServer::reset();
        Configure::write('Mcp.http.maxBodyBytes', 2048);

        $this->callTool('create_note', ['title' => str_repeat('a', 4096)]);

        $this->assertResponseCode(413);
        $this->assertSame('', $this->_response?->getHeaderLine('WWW-Authenticate'));
        $this->assertSame(-32600, $this->responseJson()['error']['code'] ?? null);
    }

    public function testABodyWithinTheLimitStillReachesTheTool(): void
    {
        Configure::write('Mcp.http.maxBodyBytes', 4096);
        $text = str_repeat('a', 1500);

        $this->callTool('echo', ['text' => $text], TestTokens::EDITOR);

        $this->assertResponseOk();
        $this->assertSame($text, $this->responseJson()['result']['content'][0]['text'] ?? null);
    }

    public function testAnInvalidLimitFailsClosed(): void
    {
        Configure::write('Mcp.http.maxBodyBytes', 0);

        $this->callTool('echo', ['text' => 'x'], TestTokens::EDITOR);

        $this->assertResponseCode(500);
        $this->assertSame(-32603, $this->responseJson()['error']['code'] ?? null);
    }
}
