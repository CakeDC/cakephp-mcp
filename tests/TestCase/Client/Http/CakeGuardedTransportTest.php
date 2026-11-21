<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\Http;

use Cake\Http\Client\AdapterInterface;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\Exception\ClientException;
use CakeDC\Mcp\Client\Http\BlockedUrlException;
use CakeDC\Mcp\Client\Http\CakeGuardedTransport;
use CakeDC\Mcp\Client\Http\CheckedEndpoint;
use CakeDC\Mcp\Client\Http\HttpRequest;
use Psr\Http\Message\RequestInterface;

class CakeGuardedTransportTest extends TestCase
{
    /**
     * @param string $body The body the adapter answers with.
     * @return \Cake\Http\Client\AdapterInterface&object{options: array<string, mixed>|null, request: \Psr\Http\Message\RequestInterface|null}
     */
    private function adapter(string $body = '{"ok":true}', int $status = 200): AdapterInterface
    {
        return new class ($body, $status) implements AdapterInterface {
            /**
             * @var array<string, mixed>|null
             */
            public ?array $options = null;

            public ?RequestInterface $request = null;

            public function __construct(private string $body, private int $status)
            {
            }

            public function send(RequestInterface $request, array $options): array
            {
                $this->request = $request;
                $this->options = $options;

                return [new Response(
                    [sprintf('HTTP/1.1 %d X', $this->status), 'Content-Type: application/json', 'Location: /next'],
                    $this->body,
                )];
            }
        };
    }

    public function testWithAProxyTheRequestGoesThroughItAndIsNotPinned(): void
    {
        $adapter = $this->adapter();
        $transport = new CakeGuardedTransport($adapter, 'http://user:secret@proxy.corp.example:3128');
        $pin = new CheckedEndpoint('https://auth.example.com', 'auth.example.com', 443, '');

        $transport->send(new HttpRequest('GET', 'https://auth.example.com/.well-known/x', [], null, 5.0), $pin, 2048);

        $curl = ($adapter->options ?? [])['curl'];
        $this->assertSame('http://user:secret@proxy.corp.example:3128', $curl[CURLOPT_PROXY]);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $curl);
        // Every request goes through it: NO_PROXY in the environment would send one direct, unpinned.
        $this->assertSame('', $curl[CURLOPT_NOPROXY]);
        $this->assertFalse($curl[CURLOPT_FOLLOWLOCATION]);
        // Proxy credentials go to the proxy only, never as a header the server would see.
        $this->assertSame('', $adapter->request?->getHeaderLine('Proxy-Authorization'));
    }

    public function testTheRequestIsPinnedWithoutProxyOrRedirects(): void
    {
        $adapter = $this->adapter();
        $transport = new CakeGuardedTransport($adapter);
        $pin = new CheckedEndpoint('https://auth.example.com', 'auth.example.com', 443, '93.184.216.34');

        $response = $transport->send(
            new HttpRequest('POST', 'https://auth.example.com/token?x=1', ['Accept' => 'application/json'], 'a=b', 7.0),
            $pin,
            2048,
        );

        $this->assertSame(200, $response->status);
        $this->assertSame('{"ok":true}', $response->body);
        $this->assertSame('application/json', $response->header('content-type'));
        $this->assertNotNull($adapter->request);
        $this->assertSame('https://auth.example.com/token?x=1', (string)$adapter->request->getUri());
        $this->assertSame('POST', $adapter->request->getMethod());
        $this->assertSame('a=b', (string)$adapter->request->getBody());
        $options = $adapter->options ?? [];
        $this->assertSame(7.0, $options['timeout']);
        $this->assertFalse(isset($options['proxy']));
        $curl = $options['curl'];
        $this->assertSame(['auth.example.com:443:93.184.216.34'], $curl[CURLOPT_RESOLVE]);
        $this->assertSame('', $curl[CURLOPT_PROXY]);
        $this->assertSame('*', $curl[CURLOPT_NOPROXY]);
        $this->assertFalse($curl[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(2048, $curl[CURLOPT_MAXFILESIZE]);
        $this->assertFalse($curl[CURLOPT_NOPROGRESS]);
        $this->assertIsCallable($curl[CURLOPT_XFERINFOFUNCTION]);
        $this->assertSame(1, $curl[CURLOPT_XFERINFOFUNCTION](null, 4096, 2049, 0, 0), 'past the cap aborts');
        $this->assertSame(0, $curl[CURLOPT_XFERINFOFUNCTION](null, 0, 100, 0, 0));
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $this->assertSame('https', $curl[CURLOPT_PROTOCOLS_STR]);
        } else {
            $this->assertSame(CURLPROTO_HTTPS, $curl[CURLOPT_PROTOCOLS]);
        }
    }

    public function testOnlyAnUncompressedAnswerIsAskedForAndAcceptedAtItsHeaders(): void
    {
        $adapter = $this->adapter();
        (new CakeGuardedTransport($adapter))->send(
            new HttpRequest('GET', 'https://auth.example.com/meta'),
            new CheckedEndpoint('https://auth.example.com', 'auth.example.com', 443, '93.184.216.34'),
            2048,
        );

        $this->assertSame('identity', $adapter->request?->getHeaderLine('Accept-Encoding'));
        $headers = $adapter->options['curl'][CURLOPT_HEADERFUNCTION] ?? null;
        $this->assertIsCallable($headers);
        $this->assertSame(strlen("Content-Type: text/html\r\n"), $headers(null, "Content-Type: text/html\r\n"));
        $this->assertSame(strlen("Content-Encoding: identity\r\n"), $headers(null, "Content-Encoding: identity\r\n"));
        // A compressed body would be inflated whole, past the cap: the transfer stops at its headers.
        $this->assertSame(0, $headers(null, "Content-Encoding: gzip\r\n"));
        // Cake trims the name before the colon, so these spellings are Content-Encoding to it too.
        foreach (["Content-Encoding : gzip\r\n", "Content-Encoding\t: gzip\r\n", "Content-Encoding\x0B: gzip\r\n", "content-ENCODING:br\r\n"] as $line) {
            $this->assertSame(0, $headers(null, $line), json_encode($line) ?: '');
        }
        $this->assertSame(strlen("X-Content-Encoding: gzip\r\n"), $headers(null, "X-Content-Encoding: gzip\r\n"));
    }

    public function testACompressedAnswerIsRefused(): void
    {
        $adapter = new class implements AdapterInterface {
            public function send(RequestInterface $request, array $options): array
            {
                return [new Response(['HTTP/1.1 200 OK', 'Content-Encoding: gzip'], (string)gzencode('{"a":1}'))];
            }
        };

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('compressed');

        (new CakeGuardedTransport($adapter))->send(
            new HttpRequest('GET', 'https://auth.example.com/meta'),
            new CheckedEndpoint('https://auth.example.com', 'auth.example.com', 443, '93.184.216.34'),
            2048,
        );
    }

    public function testAnyFailureOfTheHttpLayerIsAClientException(): void
    {
        $adapter = new class implements AdapterInterface {
            public function send(RequestInterface $request, array $options): array
            {
                // What Cake's Response does with a body that claims gzip and is not.
                return [new Response(['HTTP/1.1 200 OK', 'Content-Encoding: gzip'], '{"not":"gzip"}')];
            }
        };

        $this->expectException(ClientException::class);

        (new CakeGuardedTransport($adapter))->send(
            new HttpRequest('GET', 'https://auth.example.com/meta'),
            new CheckedEndpoint('https://auth.example.com', 'auth.example.com', 443, '93.184.216.34'),
            2048,
        );
    }

    public function testAnIpv6PinIsBracketed(): void
    {
        $adapter = $this->adapter();
        $pin = new CheckedEndpoint('https://auth.example.com:8443', 'auth.example.com', 8443, '2606:2800:220:1::1');

        (new CakeGuardedTransport($adapter))->send(new HttpRequest('GET', 'https://auth.example.com:8443/x'), $pin, 100);

        $this->assertSame(['auth.example.com:8443:[2606:2800:220:1::1]'], $adapter->options['curl'][CURLOPT_RESOLVE] ?? null);
    }

    public function testAUrlOffThePinnedOriginIsRefused(): void
    {
        $adapter = $this->adapter();
        $pin = new CheckedEndpoint('https://auth.example.com', 'auth.example.com', 443, '93.184.216.34');

        try {
            (new CakeGuardedTransport($adapter))->send(new HttpRequest('GET', 'https://evil.example.com/x'), $pin, 100);
            $this->fail('Not refused');
        } catch (BlockedUrlException) {
            $this->assertNull($adapter->request);
        }
    }

    public function testATrailingDotHostIsRefused(): void
    {
        $adapter = $this->adapter();
        $pin = new CheckedEndpoint('https://auth.example.com', 'auth.example.com', 443, '93.184.216.34');

        try {
            (new CakeGuardedTransport($adapter))->send(new HttpRequest('GET', 'https://auth.example.com./x'), $pin, 100);
            $this->fail('Not refused');
        } catch (BlockedUrlException) {
            $this->assertNull($adapter->request);
        }
    }

    public function testABodyOverTheCapIsRefused(): void
    {
        $adapter = $this->adapter(str_repeat('x', 101));
        $pin = new CheckedEndpoint('https://auth.example.com', 'auth.example.com', 443, '93.184.216.34');

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('larger answer');

        (new CakeGuardedTransport($adapter))->send(new HttpRequest('GET', 'https://auth.example.com/x'), $pin, 100);
    }

    public function testRedirectsAreReturnedNotFollowed(): void
    {
        $adapter = $this->adapter('', 302);
        $pin = new CheckedEndpoint('https://auth.example.com', 'auth.example.com', 443, '93.184.216.34');

        $response = (new CakeGuardedTransport($adapter))->send(new HttpRequest('GET', 'https://auth.example.com/x'), $pin, 100);

        $this->assertSame(302, $response->status);
        $this->assertSame('/next', $response->header('Location'));
    }
}
