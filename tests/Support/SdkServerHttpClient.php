<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use Cake\Http\Client\Response as ClientResponse;
use Cake\Http\ResponseFactory;
use Cake\Http\ServerRequestFactory;
use Cake\Http\StreamFactory;
use Mcp\Server;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that answers with an MCP server of the SDK, run in process for each request, so the client under
 * test talks to a real server without a network. Records what it was sent.
 *
 * Tools: `echo` (returns `echo: <text>`), `whoami` (the bearer token it was called with), `delete_all`, `get_issue`,
 * `get_secret`; resource `docs://readme`; prompt `greet`.
 */
final class SdkServerHttpClient implements ClientInterface
{
    /**
     * @var list<array{method: string, url: string, headers: array<string, string>, rpc: string|null, body: string}>
     */
    public array $requests = [];

    /**
     * Bearer tokens the server accepts; null accepts any request.
     *
     * @var list<string>|null
     */
    public ?array $tokens = null;

    private InMemorySessionStore $sessions;

    private string $currentToken = '';

    /**
     * @param int $pageSize Items per page of a list.
     */
    public function __construct(private int $pageSize = 50)
    {
        $this->sessions = new InMemorySessionStore();
    }

    /**
     * @return list<string> The JSON-RPC methods received, in order (notifications included).
     */
    public function methods(): array
    {
        return array_values(array_filter(array_map(fn(array $r): ?string => $r['rpc'], $this->requests)));
    }

    /**
     * @inheritDoc
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = (string)$request->getBody();
        $request->getBody()->rewind();
        $decoded = json_decode($body, true);
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower((string)$name)] = implode(', ', $values);
        }
        $this->requests[] = [
            'method' => $request->getMethod(),
            'url' => (string)$request->getUri(),
            'headers' => $headers,
            'rpc' => is_array($decoded) && is_string($decoded['method'] ?? null) ? $decoded['method'] : null,
            'body' => $body,
        ];

        $authorization = $headers['authorization'] ?? '';
        $this->currentToken = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
        if ($this->tokens !== null && !in_array($this->currentToken, $this->tokens, true)) {
            return new ClientResponse(
                ['HTTP/1.1 401 Unauthorized', 'WWW-Authenticate: Bearer error="invalid_token"'],
                '',
            );
        }

        $token = $this->currentToken;
        $server = Server::builder()
            ->setServerInfo('fake', '1.0.0')
            ->setSession($this->sessions)
            ->setPaginationLimit($this->pageSize)
            ->addTool(static fn(string $text): string => 'echo: ' . $text, 'echo', null, 'Echoes the text.')
            ->addTool(static fn(): string => $token, 'whoami', null, 'The token of the call.')
            ->addTool(static fn(): string => 'deleted', 'delete_all', null, 'Deletes everything.')
            ->addTool(static fn(): string => 'issue', 'get_issue', null, 'Reads an issue.')
            ->addTool(static fn(): string => 'secret', 'get_secret', null, 'Reads a secret.')
            ->addResource(static fn(): string => 'Read me.', 'docs://readme', 'readme', null, null, 'text/plain')
            ->addPrompt(
                static fn(string $name): array => [['role' => 'user', 'content' => 'Hello ' . $name]],
                'greet',
                null,
                'Greets someone.',
            )
            ->build();

        $serverRequest = (new ServerRequestFactory())->createServerRequest($request->getMethod(), $request->getUri());
        foreach ($request->getHeaders() as $name => $values) {
            $serverRequest = $serverRequest->withHeader((string)$name, $values);
        }
        $serverRequest = $serverRequest->withHeader('Host', $request->getUri()->getHost())
            ->withBody((new StreamFactory())->createStream($body));

        return $server->run(new StreamableHttpTransport($serverRequest, new ResponseFactory(), new StreamFactory(), null, []));
    }
}
