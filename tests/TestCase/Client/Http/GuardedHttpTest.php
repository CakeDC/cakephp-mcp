<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\Http;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\Http\BlockedUrlException;
use CakeDC\Mcp\Client\Http\EndpointGuard;
use CakeDC\Mcp\Client\Http\GuardedHttp;
use CakeDC\Mcp\Client\Http\HttpRequest;
use CakeDC\Mcp\Client\Http\HttpResponse;
use CakeDC\Mcp\Test\Support\ScriptedGuardedTransport;
use CakeDC\Mcp\Test\Support\StaticHostResolver;

class GuardedHttpTest extends TestCase
{
    private ScriptedGuardedTransport $transport;

    private GuardedHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        $resolver = new StaticHostResolver([
            'auth.example.com' => ['93.184.216.34'],
            'other.example.com' => ['93.184.216.35'],
            'internal.example.com' => ['10.0.0.1'],
        ]);
        $this->transport = new ScriptedGuardedTransport();
        $this->http = new GuardedHttp(new EndpointGuard($resolver), $this->transport, 4096);
    }

    private static function redirect(string $location, int $status = 302): HttpResponse
    {
        return new HttpResponse($status, ['Location' => [$location]], '');
    }

    public function testGetSendsTheExactUrlToThePinnedAddress(): void
    {
        $this->transport->json('https://auth.example.com/.well-known/x?resource=a%20b', ['ok' => true]);

        $response = $this->http->get('https://auth.example.com/.well-known/x?resource=a%20b', 5.0);

        $this->assertSame(['ok' => true], $response->json());
        $this->assertSame('93.184.216.34', $this->transport->sent[0]['ip']);
        $this->assertSame(5.0, $this->transport->sent[0]['request']->timeout);
    }

    public function testGetFollowsRedirectsCheckingEachHop(): void
    {
        $this->transport->on('GET', 'https://auth.example.com/a', self::redirect('/b'));
        $this->transport->on('GET', 'https://auth.example.com/b', self::redirect('https://other.example.com/c', 301));
        $this->transport->json('https://other.example.com/c', ['ok' => 1]);

        $response = $this->http->get('https://auth.example.com/a', 5.0);

        $this->assertSame(['ok' => 1], $response->json());
        $this->assertSame('93.184.216.35', $this->transport->sent[2]['ip']);
    }

    public function testARedirectToAPrivateHostIsRefusedBeforeItIsSent(): void
    {
        $this->transport->on('GET', 'https://auth.example.com/a', self::redirect('https://internal.example.com/meta'));

        try {
            $this->http->get('https://auth.example.com/a', 5.0);
            $this->fail('Not refused');
        } catch (BlockedUrlException) {
            $this->assertSame(['GET https://auth.example.com/a'], $this->transport->urls());
        }
    }

    public function testARedirectToHttpIsRefused(): void
    {
        $this->transport->on('GET', 'https://auth.example.com/a', self::redirect('http://auth.example.com/a'));

        $this->expectException(BlockedUrlException::class);

        $this->http->get('https://auth.example.com/a', 5.0);
    }

    public function testTooManyRedirectsAreRefused(): void
    {
        foreach (['a' => 'b', 'b' => 'c', 'c' => 'd', 'd' => 'e'] as $from => $to) {
            $this->transport->on('GET', 'https://auth.example.com/' . $from, self::redirect('/' . $to));
        }

        $this->expectException(BlockedUrlException::class);
        $this->expectExceptionMessage('redirected too many times');

        $this->http->get('https://auth.example.com/a', 5.0);
    }

    public function testSendRefusesARedirect(): void
    {
        $this->transport->on('POST', 'https://auth.example.com/token', self::redirect('https://other.example.com/token'));

        try {
            $this->http->send(new HttpRequest('POST', 'https://auth.example.com/token', [], 'code=abc'));
            $this->fail('Not refused');
        } catch (BlockedUrlException) {
            $this->assertSame(['POST https://auth.example.com/token'], $this->transport->urls());
        }
    }

    public function testSendToAPrivateHostIsNeverSent(): void
    {
        try {
            $this->http->send(new HttpRequest('POST', 'https://internal.example.com/token', [], 'code=abc'));
            $this->fail('Not refused');
        } catch (BlockedUrlException) {
            $this->assertSame([], $this->transport->sent);
        }
    }
}
