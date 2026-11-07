<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase;

use Cake\Cache\Cache;
use Cake\Cache\Engine\ArrayEngine;
use Cake\Http\Exception\TooManyRequestsException;
use Cake\Http\Middleware\RateLimitMiddleware;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\McpConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The rate limiting example of docs/configuration.md, as written there.
 */
class RateLimitExampleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::setConfig('rate_limit', ['className' => ArrayEngine::class]);
    }

    protected function tearDown(): void
    {
        Cache::drop('rate_limit');
        parent::tearDown();
    }

    /**
     * The documented middleware for oauth and external modes, with a limit of 2 for the test.
     */
    private function middleware(): RateLimitMiddleware
    {
        return new RateLimitMiddleware([
            'identifier' => RateLimitMiddleware::IDENTIFIER_TOKEN,
            'limit' => 2,
            'window' => 60,
            'cache' => 'rate_limit',
            'skipCheck' => fn(ServerRequestInterface $request): bool => !McpConfig::isEndpoint($request),
        ]);
    }

    private function send(RateLimitMiddleware $middleware, string $path, string $token): int
    {
        $request = (new ServerRequest([
            'url' => $path,
            'environment' => ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '192.0.2.10'],
        ]))->withHeader('Authorization', 'Bearer ' . $token);

        try {
            return $middleware->process($request, new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(['charset' => 'UTF-8']);
                }
            })->getStatusCode();
        } catch (TooManyRequestsException $exception) {
            // ErrorHandlerMiddleware, earlier in the queue, turns it into the response.
            return $exception->getCode();
        }
    }

    public function testTheMcpRouteIsLimitedPerToken(): void
    {
        $middleware = $this->middleware();

        $this->assertSame(200, $this->send($middleware, '/mcp', 'token-a'));
        $this->assertSame(200, $this->send($middleware, '/mcp', 'token-a'));
        $this->assertSame(429, $this->send($middleware, '/mcp', 'token-a'));
        $this->assertSame(200, $this->send($middleware, '/mcp', 'token-b'));
    }

    public function testTheDocsShowThisExample(): void
    {
        $docs = (string)file_get_contents(dirname(__DIR__, 2) . '/docs/configuration.md');

        $this->assertStringContainsString('## Rate limiting', $docs);
        $this->assertStringContainsString("'identifier' => RateLimitMiddleware::IDENTIFIER_TOKEN,", $docs);
        $this->assertStringContainsString("'skipCheck' => fn(ServerRequestInterface \$request): bool => !McpConfig::isEndpoint(\$request),", $docs);
    }

    public function testOtherRoutesAreNotLimited(): void
    {
        $middleware = $this->middleware();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->send($middleware, '/articles', 'token-a'));
        }
    }
}
