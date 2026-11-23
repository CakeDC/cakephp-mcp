<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Middleware;

use Cake\Http\Response;
use Cake\Http\ResponseFactory;
use Cake\Http\ServerRequest;
use Cake\Http\StreamFactory;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Middleware\CatalogScopeMiddleware;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CatalogScopeMiddlewareTest extends TestCase
{
    /**
     * The SDK's authorization middleware hands on a token whose scopes it already expanded, but this check must not
     * depend on that: it compares with the same set as ToolCatalog::callable().
     */
    public function testATokenWhoseScopesImplyTheRequiredOnesIsLetThroughEvenUnexpanded(): void
    {
        $response = $this->middleware()->process($this->callTools(['orders:admin']), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testATokenWithoutTheScopeGetsTheChallenge(): void
    {
        $response = $this->middleware()->process($this->callTools(['other']), $this->handler());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('scope="orders:read"', $response->getHeaderLine('WWW-Authenticate'));
    }

    private function middleware(): CatalogScopeMiddleware
    {
        return new CatalogScopeMiddleware(
            new ScopePolicy([], [], ['order_status' => ['orders:read']], ['orders:admin' => ['orders:read']]),
            new ProtectedResourceMetadata('https://app.example.com/mcp', ['https://auth.example.com']),
            ['order_*'],
            new ResponseFactory(),
            new StreamFactory(),
            4096,
        );
    }

    /**
     * @param list<string> $scopes The token's scopes as granted.
     */
    private function callTools(array $scopes): ServerRequestInterface
    {
        $body = (string)json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'call_tools', 'arguments' => ['calls' => [['name' => 'order_status', 'arguments' => []]]]],
        ]);

        return (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST'], 'input' => $body]))
            ->withAttribute(AccessToken::class, new AccessToken($scopes));
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
    }
}
