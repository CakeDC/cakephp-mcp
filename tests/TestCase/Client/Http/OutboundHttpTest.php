<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\Http;

use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\Exception\ClientException;
use CakeDC\Mcp\Client\Http\BlockedUrlException;
use CakeDC\Mcp\Client\Http\EndpointGuard;
use CakeDC\Mcp\Client\Http\GuardedHttp;
use CakeDC\Mcp\Client\Http\HttpRequest;
use CakeDC\Mcp\Client\Http\OutboundHttp;
use CakeDC\Mcp\Test\Support\ScriptedGuardedTransport;
use CakeDC\Mcp\Test\Support\StaticHostResolver;
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
