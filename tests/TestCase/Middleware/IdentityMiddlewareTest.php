<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Middleware;

use Cake\Http\Response;
use Cake\Http\ResponseFactory;
use Cake\Http\ServerRequest;
use Cake\Http\StreamFactory;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Middleware\IdentityMiddleware;
use Closure;
use Mcp\Server\Authorization\AccessToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class IdentityMiddlewareTest extends TestCase
{
    private CurrentIdentity $current;

    private ?ServerRequestInterface $handled = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->current = new CurrentIdentity();
        $this->handled = null;
    }

    public function testMissingIdentityIsChallengedWhenRequired(): void
    {
        $response = $this->middleware()->process($this->request(), $this->handler());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertStringContainsString('unauthorized', (string)$response->getBody());
        $this->assertNull($this->handled);
    }

    public function testMissingIdentityPassesWhenNotRequired(): void
    {
        $response = $this->middleware(required: false)->process($this->request(), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($this->handled);
        $this->assertNull($this->handled->getAttribute(AccessToken::class));
        $this->assertFalse($this->current->has());
    }

    public function testOptionsPassesWithoutIdentity(): void
    {
        $response = $this->middleware()->process($this->request('OPTIONS'), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($this->handled);
    }

    public function testIdentityBecomesAnAccessTokenAndTheCurrentIdentity(): void
    {
        $identity = ['id' => 7, 'role' => 'editor'];

        $this->middleware()->process($this->request()->withAttribute('identity', $identity), $this->handler());

        $token = $this->handled?->getAttribute(AccessToken::class);
        $this->assertInstanceOf(AccessToken::class, $token);
        $this->assertSame('7', $token->getSubject());
        $this->assertSame([], $token->getScopes());
        $this->assertSame($identity, $this->current->get());
    }

    public function testScopesComeFromTheConfiguredCallable(): void
    {
        $scopes = fn(mixed $identity): array => ['posts:' . $identity['role'], 42];

        $this->middleware(scopes: $scopes)->process(
            $this->request()->withAttribute('identity', ['id' => 1, 'role' => 'editor']),
            $this->handler(),
        );

        $token = $this->handled?->getAttribute(AccessToken::class);
        $this->assertInstanceOf(AccessToken::class, $token);
        $this->assertSame(['posts:editor'], $token->getScopes());
    }

    public function testCustomAttributeName(): void
    {
        $this->middleware(attribute: 'user')->process(
            $this->request()->withAttribute('user', ['id' => 5]),
            $this->handler(),
        );

        $this->assertSame(5, $this->current->getIdentifier());
    }

    public function testCurrentIdentityIsResetOnEveryRequest(): void
    {
        $this->current->set(['id' => 99]);

        $this->middleware(required: false)->process($this->request(), $this->handler());

        $this->assertFalse($this->current->has());
    }

    private function middleware(bool $required = true, string $attribute = 'identity', ?Closure $scopes = null): IdentityMiddleware
    {
        return new IdentityMiddleware(
            $this->current,
            new ResponseFactory(),
            new StreamFactory(),
            $required,
            $attribute,
            $scopes,
        );
    }

    private function request(string $method = 'POST'): ServerRequest
    {
        return new ServerRequest(['environment' => ['REQUEST_METHOD' => $method]]);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class ($this) implements RequestHandlerInterface {
            public function __construct(private IdentityMiddlewareTest $test)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->record($request);

                return new Response();
            }
        };
    }

    public function record(ServerRequestInterface $request): void
    {
        $this->handled = $request;
    }
}
