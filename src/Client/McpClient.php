<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use CakeDC\Mcp\Client\Exception\ClientException;
use LogicException;
use Mcp\Exception\ConnectionException;
use Mcp\Exception\ExceptionInterface;

/**
 * A named client of `Mcp.clients`. In app mode it calls the server with the app's own credentials; in user mode,
 * for($user) gives the connection of one user.
 *
 * ```
 * McpClients::get('github')->callTool('search_issues', ['query' => 'bug']);
 * McpClients::get('linear')->for($this->Authentication->getIdentity())->listTools();
 * ```
 */
final class McpClient
{
    use CallsServerTrait;

    private ?ClientSession $session = null;

    /**
     * The token the session of a `client_credentials` client sends.
     */
    private ?string $sessionToken = null;

    /**
     * @var array<string, \CakeDC\Mcp\Client\UserConnection>
     */
    private array $users = [];

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client.
     * @param \CakeDC\Mcp\Client\ClientRegistry $registry Where its HTTP client, store and OAuth pieces come from.
     */
    public function __construct(private ClientConfig $config, private ClientRegistry $registry)
    {
    }

    /**
     * @return string The client's name in `Mcp.clients`.
     */
    public function name(): string
    {
        return $this->config->name;
    }

    /**
     * @inheritDoc
     */
    public function config(): ClientConfig
    {
        return $this->config;
    }

    /**
     * The connection of one user, for a user mode client.
     *
     * @param mixed $user A user id, an identity, or a user entity or array with an `id`.
     * @return \CakeDC\Mcp\Client\UserConnection The same instance for the same user.
     * @throws \LogicException When the client is in app mode.
     * @throws \InvalidArgumentException When no user id can be read from $user.
     */
    public function for(mixed $user): UserConnection
    {
        if (!$this->config->isUser()) {
            throw new LogicException(sprintf(
                '"Mcp.clients.%s" is an app mode client: call it directly with McpClients::get(\'%s\'), or set '
                . '"auth" => "user" to call it on behalf of each user.',
                $this->config->name,
                $this->config->name,
            ));
        }
        $userId = UserId::of($user);

        return $this->users[$userId] ??= new UserConnection($this->config, $this->registry, $userId);
    }

    /**
     * Ends the connection to the server, and every user's; the next call opens a new one.
     *
     * @return void
     */
    public function close(): void
    {
        $this->session?->close();
        $this->session = null;
        foreach ($this->users as $user) {
            $user->close();
        }
        $this->users = [];
    }

    /**
     * @inheritDoc
     */
    protected function listCache(): ?ListCache
    {
        if ($this->config->isUser() || $this->config->cache === null) {
            return null;
        }
        // Keyed by the credential the requests carry: a `token` callable may return another user's token.
        $credential = $this->config->isClientCredentials()
            ? $this->registry->clientCredentials($this->config)->token()
            : (string)json_encode([$this->config->headers, $this->config->callableToken()]);

        return ListCache::for($this->config, null, $credential);
    }

    /**
     * @inheritDoc
     */
    protected function lastResult(string $method): ?array
    {
        return $this->session?->lastResult($method);
    }

    /**
     * @inheritDoc
     */
    protected function run(callable $call): mixed
    {
        if ($this->config->isUser()) {
            throw new LogicException(sprintf(
                '"Mcp.clients.%s" is a user mode client: call it on behalf of a user with '
                . 'McpClients::get(\'%s\')->for($user).',
                $this->config->name,
                $this->config->name,
            ));
        }
        if ($this->config->isClientCredentials()) {
            return $this->runWithOwnToken($call);
        }
        $this->session ??= new ClientSession(
            $this->config,
            $this->registry->http(),
            [],
            ListCache::for($this->config, null),
        );

        try {
            return $this->session->call($call);
        } catch (ConnectionException $e) {
            // A `token` callable that failed: its own message, not the transport's.
            $previous = $e->getPrevious();
            throw $previous instanceof ClientException ? $previous : $e;
        }
    }

    /**
     * A call of a `client_credentials` client, with the application's own token; when the server refuses it
     * (401), once more with a new one.
     *
     * @template T
     * @param callable(\Mcp\Client): T $call The call.
     * @return T
     */
    private function runWithOwnToken(callable $call): mixed
    {
        $tokens = $this->registry->clientCredentials($this->config);
        $session = $this->sessionFor($tokens->token());
        try {
            return $session->call($call);
        } catch (ExceptionInterface $e) {
            if ($session->lastStatus() !== 401) {
                throw $e;
            }
        }

        return $this->sessionFor($tokens->token(true))->call($call);
    }

    /**
     * @param string $token The access token.
     * @return \CakeDC\Mcp\Client\ClientSession A session that sends it.
     */
    private function sessionFor(string $token): ClientSession
    {
        if ($this->session === null || $this->sessionToken !== $token) {
            $this->session?->close();
            $this->session = new ClientSession(
                $this->config,
                $this->registry->http(),
                ['Authorization' => 'Bearer ' . $token],
                ListCache::for($this->config, null),
            );
            $this->sessionToken = $token;
        }

        return $this->session;
    }
}
