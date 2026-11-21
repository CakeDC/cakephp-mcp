<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\Http;

use Cake\Http\Client;
use Cake\Http\Client\AdapterInterface;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\Exception\ClientException;
use CakeDC\Mcp\Client\Http\BlockedUrlException;
use CakeDC\Mcp\Client\Http\Deadline;
use CakeDC\Mcp\Client\Http\EndpointGuard;
use CakeDC\Mcp\Client\Http\GuardedHttp;
use CakeDC\Mcp\Client\Http\HttpRequest;
use CakeDC\Mcp\Client\Http\HttpResponse;
use CakeDC\Mcp\Client\Http\OutboundHttp;
use CakeDC\Mcp\Test\Support\ScriptedGuardedTransport;
use CakeDC\Mcp\Test\Support\StaticHostResolver;
use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class OutboundHttpTest extends TestCase
{
    private ScriptedGuardedTransport $guarded;

    /**
     * @var \Psr\Http\Client\ClientInterface&object{sent: list<\Psr\Http\Message\RequestInterface>, answers: array<string, \Cake\Http\Client\Response>}
     */
    private ClientInterface $trusted;

    private OutboundHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guarded = new ScriptedGuardedTransport();
        $this->trusted = new class implements ClientInterface {
            /**
             * @var list<\Psr\Http\Message\RequestInterface>
             */
            public array $sent = [];

            /**
             * @var array<string, \Cake\Http\Client\Response>
             */
            public array $answers = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return $this->answers[(string)$request->getUri()] ?? new Response(['HTTP/1.1 404 Not Found'], '');
            }
        };
        $resolver = new StaticHostResolver([
            'auth.example.com' => ['93.184.216.34'],
            'internal.example.com' => ['10.0.0.1'],
        ]);
        $this->http = new OutboundHttp(new GuardedHttp(new EndpointGuard($resolver), $this->guarded, 4096), $this->trusted);
    }

    public function testWithADeadlineTheRequestsAndRedirectsShareIt(): void
    {
        $now = 0.0;
        $clock = function () use (&$now): float {
            return $now;
        };
        // Each answer takes 4 seconds; the server redirects three times.
        $slow = function (int $status, string $location) use (&$now): Closure {
            return function (HttpRequest $request) use (&$now, $status, $location): HttpResponse {
                $now += 4.0;

                return new HttpResponse($status, ['Location' => [$location]], '{}');
            };
        };
        $this->guarded->on('GET', 'https://auth.example.com/a', $slow(302, '/b'));
        $this->guarded->on('GET', 'https://auth.example.com/b', $slow(302, '/c'));
        $this->guarded->on('GET', 'https://auth.example.com/c', $slow(302, '/d'));
        $http = $this->http->withDeadline(new Deadline(10.0, $clock));

        try {
            $http->get('https://auth.example.com/a', false, 30.0);
            $this->fail('No exception once the time is up');
        } catch (ClientException $e) {
            $this->assertStringContainsString('took too long', $e->getMessage());
        }
        $this->assertSame([10.0, 6.0, 2.0], array_map(fn(array $sent): float => $sent['request']->timeout, $this->guarded->sent));

        // A trusted request once the time is up is not sent.
        try {
            $http->send(new HttpRequest('POST', 'http://localhost:8765/token', [], 'x'), true);
            $this->fail('No exception once the time is up');
        } catch (ClientException) {
        }
        $this->assertSame([], $this->trusted->sent);
        // Without one, nothing changes.
        $this->http->get('https://auth.example.com/c', false, 30.0);
        $this->assertSame(30.0, $this->guarded->sent[3]['request']->timeout);
    }

    public function testCakesClientGetsWhatIsLeftAndKeepsItsOwnSettings(): void
    {
        $adapter = new class implements AdapterInterface {
            /**
             * @var array<string, mixed>
             */
            public array $options = [];

            public function send(RequestInterface $request, array $options): array
            {
                $this->options = $options;

                return [new Response(['HTTP/1.1 200 OK'], '{}')];
            }
        };
        $client = new Client([
            'adapter' => $adapter,
            'timeout' => 30,
            'redirect' => 0,
            'proxy' => ['proxy' => 'http://proxy.corp.example:3128'],
        ]);
        $clock = new class {
            public float $now = 0.0;
        };
        $http = (new OutboundHttp(new GuardedHttp(new EndpointGuard(new StaticHostResolver([])), $this->guarded, 4096), $client))
            ->withDeadline(new Deadline(10.0, fn(): float => $clock->now));
        $clock->now = 7.5;

        $http->send(new HttpRequest('POST', 'https://auth.example.com/token', [], 'x', 30.0), true);

        $this->assertSame(3, $adapter->options['timeout']);
        $this->assertSame(['proxy' => 'http://proxy.corp.example:3128'], $adapter->options['proxy']);
    }

    public function testAFailureOfTheHttpLayerOnAConfiguredUrlIsAClientException(): void
    {
        // Cake's client throws a CakeException for an answer that says gzip and is not.
        $broken = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(['HTTP/1.1 200 OK', 'Content-Encoding: gzip'], '{"not":"gzip"}');
            }
        };
        $http = new OutboundHttp(new GuardedHttp(new EndpointGuard(new StaticHostResolver([])), $this->guarded, 4096), $broken);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('The server at auth.example.com could not be reached.');

        $http->send(new HttpRequest('POST', 'https://auth.example.com/revoke', [], 'token=x'), true);
    }

    public function testTrustedRequestsUseTheConfiguredClient(): void
    {
        $this->trusted->answers['http://localhost:8765/.well-known/oauth-protected-resource'] = new Response(
            ['HTTP/1.1 200 OK', 'Content-Type: application/json'],
            '{"resource":"x"}',
        );

        $response = $this->http->get('http://localhost:8765/.well-known/oauth-protected-resource', true, 5.0);

        $this->assertSame(['resource' => 'x'], $response->json());
        $this->assertSame([], $this->guarded->sent);
        $this->assertSame('application/json', $this->trusted->sent[0]->getHeaderLine('Accept'));
    }

    public function testTrustedSendCarriesHeadersAndBody(): void
    {
        $this->trusted->answers['https://sso.internal/token'] = new Response(['HTTP/1.1 200 OK'], '{"access_token":"a"}');

        $response = $this->http->send(new HttpRequest(
            'POST',
            'https://sso.internal/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'grant_type=x',
        ), true);

        $this->assertSame(200, $response->status);
        $this->assertSame('POST', $this->trusted->sent[0]->getMethod());
        $this->assertSame('grant_type=x', (string)$this->trusted->sent[0]->getBody());
        $this->assertSame('application/x-www-form-urlencoded', $this->trusted->sent[0]->getHeaderLine('Content-Type'));
    }

    public function testUntrustedRequestsGoThroughTheGuard(): void
    {
        $this->guarded->json('https://auth.example.com/meta', ['ok' => true]);

        $this->assertSame(['ok' => true], $this->http->get('https://auth.example.com/meta', false, 5.0)->json());
        $this->assertSame([], $this->trusted->sent);

        $this->expectException(BlockedUrlException::class);
        $this->http->get('https://internal.example.com/meta', false, 5.0);
    }

    public function testATrustedRedirectToAPrivateHostIsRefused(): void
    {
        $this->trusted->answers['http://localhost:8765/meta'] = new Response(
            ['HTTP/1.1 302 Found', 'Location: https://internal.example.com/meta'],
            '',
        );

        try {
            $this->http->get('http://localhost:8765/meta', true, 5.0);
            $this->fail('Not refused');
        } catch (BlockedUrlException) {
            $this->assertSame([], $this->guarded->sent);
        }
    }

    public function testATrustedRedirectToAPublicHostContinuesThroughTheGuard(): void
    {
        $this->trusted->answers['http://localhost:8765/meta'] = new Response(
            ['HTTP/1.1 302 Found', 'Location: https://auth.example.com/meta'],
            '',
        );
        $this->guarded->json('https://auth.example.com/meta', ['ok' => 2]);

        $this->assertSame(['ok' => 2], $this->http->get('http://localhost:8765/meta', true, 5.0)->json());
    }

    public function testATrustedSendNeverFollowsARedirect(): void
    {
        $this->trusted->answers['https://sso.internal/token'] = new Response(
            ['HTTP/1.1 307 Temporary Redirect', 'Location: https://auth.example.com/token'],
            '',
        );

        try {
            $this->http->send(new HttpRequest('POST', 'https://sso.internal/token', [], 'code=1'), true);
            $this->fail('Not refused');
        } catch (BlockedUrlException) {
            $this->assertSame([], $this->guarded->sent);
        }
    }
}
