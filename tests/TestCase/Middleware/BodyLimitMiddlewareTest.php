<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Middleware;

use Cake\Http\Response;
use Cake\Http\ResponseFactory;
use Cake\Http\ServerRequest;
use Cake\Http\StreamFactory;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Middleware\BodyLimitMiddleware;
use Closure;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

class BodyLimitMiddlewareTest extends TestCase
{
    private ?string $handled = null;

    public function testABodyWithinTheLimitIsHandedOnWhole(): void
    {
        $response = $this->middleware(16)->process($this->post(str_repeat('a', 16)), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(str_repeat('a', 16), $this->handled);
    }

    public function testAnAnnouncedOversizedBodyIsRefusedWithoutReadingIt(): void
    {
        $unreadable = new class ('php://memory', 'r') extends Stream {
            public function read(int $length): string
            {
                throw new RuntimeException('The body must not be read.');
            }

            public function getSize(): ?int
            {
                return null;
            }
        };
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withHeader('Content-Length', '17')
            ->withBody($unreadable);

        $response = $this->middleware(16)->process($request, $this->handler());

        $this->assertTooLarge($response, 16);
        $this->assertNull($this->handled);
    }

    public function testABodyOfUnknownSizeIsReadOnlyUpToTheLimit(): void
    {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, str_repeat('a', 100));
        rewind($resource);
        $unsized = new class ($resource) extends Stream {
            public function getSize(): ?int
            {
                return null;
            }
        };
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))->withBody($unsized);

        $response = $this->middleware(16)->process($request, $this->handler());

        $this->assertTooLarge($response, 16);
        $this->assertNull($this->handled);
    }

    public function testAnUnsizedBodyIsNeverReadPastTheLimitPlusOneByte(): void
    {
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, str_repeat('a', 100000));
        rewind($resource);
        $counting = new class ($resource) extends Stream {
            public int $bytesRead = 0;

            public function getSize(): ?int
            {
                return null;
            }

            public function read(int $length): string
            {
                $chunk = parent::read($length);
                $this->bytesRead += strlen($chunk);

                return $chunk;
            }
        };
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))->withBody($counting);

        $response = $this->middleware(16)->process($request, $this->handler());

        $this->assertTooLarge($response, 16);
        $this->assertLessThanOrEqual(17, $counting->bytesRead);
    }

    public function testOtherMethodsPassUntouched(): void
    {
        $request = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'GET']]);

        $response = $this->middleware(1)->process($request, $this->handler());

        $this->assertSame(200, $response->getStatusCode());
    }

    private function middleware(int $max): BodyLimitMiddleware
    {
        return new BodyLimitMiddleware($max, new ResponseFactory(), new StreamFactory());
    }

    private function post(string $body): ServerRequest
    {
        return (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withBody((new StreamFactory())->createStream($body));
    }

    private function handler(): RequestHandlerInterface
    {
        $record = function (ServerRequestInterface $request): ResponseInterface {
            $this->handled = (string)$request->getBody();

            return new Response(['status' => 200]);
        };

        return new class ($record) implements RequestHandlerInterface {
            public function __construct(private readonly Closure $record)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->record)($request);
            }
        };
    }

    private function assertTooLarge(ResponseInterface $response, int $limit): void
    {
        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame('2.0', $body['jsonrpc'] ?? null);
        $this->assertSame(-32600, $body['error']['code'] ?? null);
        $this->assertSame($limit, $body['error']['data']['maxBodyBytes'] ?? null);
        $message = (string)($body['error']['message'] ?? '');
        $this->assertStringContainsString(sprintf('the limit is %d bytes', $limit), $message);
        $this->assertStringContainsString('Mcp.http.maxBodyBytes', $message);
        $this->assertStringContainsString('smaller payload', $message);
    }
}
