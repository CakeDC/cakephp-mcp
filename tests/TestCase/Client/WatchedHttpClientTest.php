<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\Http\Client\Request;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\WatchedHttpClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A 401 can be retried with a new token while the server has accepted no tools/call of the call: requests that
 * run nothing (discovery, lists, reads) do not count.
 */
class WatchedHttpClientTest extends TestCase
{
    /**
     * @param list<int> $statuses The status of each answer, in order.
     * @return \CakeDC\Mcp\Client\WatchedHttpClient
     */
    private function client(array $statuses): WatchedHttpClient
    {
        return new WatchedHttpClient(new class ($statuses) implements ClientInterface {
            /**
             * @param list<int> $statuses
             */
            public function __construct(private array $statuses)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(['HTTP/1.1 ' . array_shift($this->statuses) . ' X'], '{}');
            }
        });
    }

    private static function post(string $method): Request
    {
        return new Request('https://mcp.example.com/mcp', 'POST', ['Mcp-Method' => $method], '{}');
    }

    public function testA401AfterRequestsThatRunNothingCanBeRetried(): void
    {
        $http = $this->client([200, 200, 200, 401]);
        $http->startCall();
        $http->sendRequest(self::post('server/discover'));
        $http->sendRequest(self::post('initialize'));
        $http->sendRequest(self::post('resources/read'));
        $http->sendRequest(self::post('tools/list'));

        $this->assertTrue($http->unauthorizedBeforeAccepted());
    }

    public function testA401AfterAnAcceptedToolCallCannot(): void
    {
        $http = $this->client([200, 401]);
        $http->startCall();
        $http->sendRequest(self::post('tools/call'));
        $http->sendRequest(new Request('https://mcp.example.com/mcp', 'GET'));

        $this->assertFalse($http->unauthorizedBeforeAccepted());
    }

    public function testA401ToTheToolCallItselfCanBeRetried(): void
    {
        $http = $this->client([200, 401]);
        $http->startCall();
        $http->sendRequest(self::post('server/discover'));
        $http->sendRequest(self::post('tools/call'));

        $this->assertTrue($http->unauthorizedBeforeAccepted());
    }
}
