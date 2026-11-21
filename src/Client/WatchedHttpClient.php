<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Cake\Http\StreamFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Remembers the status of the last answer, so a `401` (a token the server no longer accepts) can be told apart from
 * other failures: the SDK's transport reports both as the same exception.
 *
 * @internal
 */
final class WatchedHttpClient implements ClientInterface
{
    private ?int $lastStatus = null;

    /**
     * The `WWW-Authenticate` header of the last answer, or null.
     */
    private ?string $lastChallenge = null;

    /**
     * The raw `result` of the last answer to each list method, for the list cache.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $results = [];

    /**
     * @param \Psr\Http\Client\ClientInterface $client The client it wraps.
     * @param \CakeDC\Mcp\Client\ClientConfig|null $config The client, whose `token` callable is called for each request.
     */
    public function __construct(private ClientInterface $client, private ?ClientConfig $config = null)
    {
    }

    /**
     * @inheritDoc
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastStatus = null;
        $this->lastChallenge = null;
        $token = $this->config?->callableToken();
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        $method = self::method($request);
        unset($this->results[$method]);
        $response = $this->client->sendRequest($request);
        $this->lastStatus = $response->getStatusCode();
        $challenge = $response->getHeaderLine('WWW-Authenticate');
        $this->lastChallenge = $challenge === '' ? null : $challenge;
        if (in_array($method, ListCache::METHODS, true) && $response->getStatusCode() === 200) {
            // Read here for its cache hints, which the SDK's list results do not keep, then handed on unread.
            $body = (string)$response->getBody();
            $response = $response->withBody((new StreamFactory())->createStream($body));
            $result = self::result($body);
            if ($result !== null) {
                $this->results[$method] = $result;
            }
        }

        return $response;
    }

    /**
     * @param string $method A list method.
     * @return array<string, mixed>|null The raw `result` of the last answer to it, if it was one.
     */
    public function lastResult(string $method): ?array
    {
        return $this->results[$method] ?? null;
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request A request to the MCP server.
     * @return string Its JSON-RPC method, or an empty string.
     */
    private static function method(RequestInterface $request): string
    {
        $header = $request->getHeaderLine('Mcp-Method');
        if ($header !== '') {
            return $header;
        }
        $body = $request->getBody();
        $decoded = json_decode((string)$body, true);
        $body->rewind();

        return is_array($decoded) && is_string($decoded['method'] ?? null) ? $decoded['method'] : '';
    }

    /**
     * @param string $body A JSON or event-stream answer.
     * @return array<string, mixed>|null The `result` of the JSON-RPC response it holds.
     */
    private static function result(string $body): ?array
    {
        $messages = [json_decode($body, true)];
        if ($messages[0] === null) {
            // text/event-stream: the data lines of each event.
            $messages = [];
            foreach (preg_split('/\r?\n\r?\n/', $body) ?: [] as $event) {
                $data = [];
                foreach (preg_split('/\r?\n/', $event) ?: [] as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $data[] = ltrim(substr($line, 5), ' ');
                    }
                }
                $messages[] = json_decode(implode("\n", $data), true);
            }
        }
        foreach ($messages as $message) {
            if (is_array($message) && is_array($message['result'] ?? null)) {
                return $message['result'];
            }
        }

        return null;
    }

    /**
     * @return string|null The `WWW-Authenticate` header of the last answer, null when it had none.
     */
    public function lastChallenge(): ?string
    {
        return $this->lastChallenge;
    }

    /**
     * @return int|null The status of the last answer, null when none came.
     */
    public function lastStatus(): ?int
    {
        return $this->lastStatus;
    }
}
