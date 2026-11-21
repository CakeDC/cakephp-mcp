<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Cake\Http\Client\Response;
use Cake\Http\StreamFactory;
use CakeDC\Mcp\Client\Http\GuardedHttp;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Remembers the status of the last answer, so a `401` (a token the server no longer accepts) can be told apart from
 * other failures: the SDK's transport reports both as the same exception. With a `cache`, answers the connection's
 * `server/discover` probe from it while a result with cache hints is fresh.
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
     * The `WWW-Authenticate` of the last 403 since `takeForbidden()`: a step-up challenge can come before the
     * answer that ended a call (the SDK tries the handshake after a refused `server/discover`).
     */
    private ?string $forbidden = null;

    /**
     * The raw `result` of the last answer to each list method, for the list cache.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $results = [];

    /**
     * Whether the server accepted (2xx) a `tools/call` of the current call: then that call may have run. The other
     * requests (`initialize`, `server/discover`, lists, reads, gets, completions) run nothing.
     */
    private bool $accepted = false;

    /**
     * Whether this connection sent its `server/discover` probe: only that one may be answered from the cache, a
     * later one is a `ping()` that must reach the server.
     */
    private bool $probed = false;

    /**
     * The status and absolute `Location` of the last answer when it was a redirect, which is never followed.
     *
     * @var array{0: int, 1: string}|null
     */
    private ?array $redirect = null;

    /**
     * @param \Psr\Http\Client\ClientInterface $client The client it wraps.
     * @param \CakeDC\Mcp\Client\ClientConfig|null $config The client, whose `token` callable is called for each request.
     * @param \CakeDC\Mcp\Client\ListCache|null $cache The client's cache, for the `server/discover` probe.
     */
    public function __construct(
        private ClientInterface $client,
        private ?ClientConfig $config = null,
        private ?ListCache $cache = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastStatus = null;
        $this->lastChallenge = null;
        $this->redirect = null;
        $token = $this->config?->callableToken();
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        $method = self::method($request);
        unset($this->results[$method]);
        $discover = null;
        if ($method === 'server/discover' && !$this->probed && $this->cache !== null) {
            // Keyed by the credential this very request carries, as a private list is.
            $discover = $this->cache->withCredential(
                $request->getHeaderLine('Authorization') . "\0" . json_encode($this->config->headers ?? []),
            );
            $kept = $discover->get('server/discover', null);
            if (is_array($kept)) {
                $this->probed = true;
                $this->lastStatus = 200;

                return self::answer($request, $kept);
            }
        }
        if ($method === 'server/discover') {
            $this->probed = true;
        }
        $response = $this->client->sendRequest($request);
        $this->lastStatus = $response->getStatusCode();
        $location = $response->getHeaderLine('Location');
        if (in_array($this->lastStatus, [301, 302, 303, 307, 308], true) && $location !== '') {
            $this->redirect = [$this->lastStatus, GuardedHttp::resolve((string)$request->getUri(), $location)];
        }
        if (
            $request->getMethod() === 'POST'
            && $response->getStatusCode() >= 200
            && $response->getStatusCode() < 300
            && $method === 'tools/call'
        ) {
            $this->accepted = true;
        }
        $challenge = $response->getHeaderLine('WWW-Authenticate');
        $this->lastChallenge = $challenge === '' ? null : $challenge;
        if ($response->getStatusCode() === 403 && $challenge !== '') {
            $this->forbidden = $challenge;
        }
        if (in_array($method, ListCache::METHODS, true) && $response->getStatusCode() === 200) {
            // Read here for its cache hints, which the SDK's list results do not keep, then handed on unread.
            $body = (string)$response->getBody();
            $response = $response->withBody((new StreamFactory())->createStream($body));
            $result = self::result($body);
            if ($result !== null) {
                $this->results[$method] = $result;
                $discover?->put('server/discover', null, $result, $result);
            }
        }

        return $response;
    }

    /**
     * @param \Psr\Http\Message\RequestInterface $request A `server/discover` request.
     * @param array<string, mixed> $result A fresh result kept for it.
     * @return \Psr\Http\Message\ResponseInterface The answer the server gave then, to this request's id.
     */
    private static function answer(RequestInterface $request, array $result): ResponseInterface
    {
        $body = $request->getBody();
        $decoded = json_decode((string)$body, true);
        $body->rewind();

        return new Response(['HTTP/1.1 200 OK', 'Content-Type: application/json'], (string)json_encode([
            'jsonrpc' => '2.0',
            'id' => is_array($decoded) ? ($decoded['id'] ?? null) : null,
            'result' => $result,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Starts a call: what `unauthorizedBeforeAccepted()` tells is about the requests from here on.
     *
     * @return void
     */
    public function startCall(): void
    {
        $this->accepted = false;
    }

    /**
     * @return bool Whether the last answer was a 401 and the server accepted no `tools/call` of the call before it,
     *   so the call did not run and may be sent again with a new token.
     */
    public function unauthorizedBeforeAccepted(): bool
    {
        return $this->lastStatus === 401 && !$this->accepted;
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
     * @return string|null The challenge of the last 403 since the previous call, which this forgets.
     */
    public function takeForbidden(): ?string
    {
        $challenge = $this->forbidden;
        $this->forbidden = null;

        return $challenge;
    }

    /**
     * @return string|null The `WWW-Authenticate` header of the last answer, null when it had none.
     */
    public function lastChallenge(): ?string
    {
        return $this->lastChallenge;
    }

    /**
     * @return array{0: int, 1: string}|null The status and target of the last answer, when it was a redirect.
     */
    public function lastRedirect(): ?array
    {
        return $this->redirect;
    }

    /**
     * @return int|null The status of the last answer, null when none came.
     */
    public function lastStatus(): ?int
    {
        return $this->lastStatus;
    }
}
