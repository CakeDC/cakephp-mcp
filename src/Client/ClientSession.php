<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Cake\Http\RequestFactory;
use Cake\Http\StreamFactory;
use CakeDC\Mcp\Log\McpLogger;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\ConnectionException;
use Mcp\Exception\RequestException;
use Mcp\Schema\JsonRpc\Error;

/**
 * One connection of the SDK client to a server, opened on first use and kept for the calls that follow in the same
 * PHP process, until close().
 *
 * @internal
 */
final class ClientSession
{
    /**
     * Variables a stdio server inherits from this process; everything else (database passwords, keys) stays here
     * unless the client's `env` passes it.
     *
     * @var list<string>
     */
    private const INHERITED_ENV = [
        'PATH', 'HOME', 'USER', 'LANG', 'LC_ALL', 'TMPDIR', 'TEMP', 'TMP', 'SYSTEMROOT', 'USERPROFILE', 'APPDATA',
    ];

    private ?Client $client = null;

    private ?WatchedHttpClient $http = null;

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client.
     * @param \CakeDC\Mcp\Client\HttpClientFactoryInterface $factory Makes the PSR-18 client for an HTTP server.
     * @param array<string, string> $headers Headers on top of the configured ones (a user's token).
     * @param \CakeDC\Mcp\Client\ListCache|null $lists The client's list cache, dropped on `list_changed`.
     */
    public function __construct(
        private ClientConfig $config,
        private HttpClientFactoryInterface $factory,
        private array $headers = [],
        private ?ListCache $lists = null,
    ) {
    }

    /**
     * @return \Mcp\Client The SDK client, connected.
     * @throws \Mcp\Exception\ConnectionException When the server cannot be reached or the handshake fails.
     */
    public function client(): Client
    {
        if ($this->client !== null && $this->client->isConnected()) {
            return $this->client;
        }
        $builder = Client::builder()
            ->setClientInfo((string)ClientConfig::setting('name'), (string)ClientConfig::setting('version'))
            ->setInitTimeout($this->config->timeout)
            ->setRequestTimeout($this->config->timeout)
            // The caller decides whether to try again; retries here would multiply the timeout.
            ->setMaxRetries(0)
            ->setLogger(new McpLogger())
            ->addRequestHandler(new PingHandler());
        if ($this->lists !== null) {
            $builder->addNotificationHandler(new ListChangedHandler($this->lists));
        }
        if ($this->config->protocolVersion !== null) {
            $builder->setProtocolVersion($this->config->protocolVersion);
        }
        $client = $builder->build();
        $client->connect($this->transport());
        $this->client = $client;

        return $client;
    }

    /**
     * Runs $call on the connected client. When the server refused the request before running it, it is sent once
     * more: after a header mismatch (-32020), with the tool list fetched again so the SDK knows the headers of
     * each tool, as the specification advises; after an expired session (HTTP 404 in the handshake revisions),
     * on a new session. Nothing else is retried, so a call that may have run never runs twice.
     *
     * @template T
     * @param callable(\Mcp\Client): T $call The call.
     * @return T
     */
    public function call(callable $call): mixed
    {
        $client = $this->client();
        try {
            return $call($client);
        } catch (RequestException $e) {
            if ($e->getCode() !== Error::HEADER_MISMATCH) {
                throw $e;
            }
            $client->listTools();

            return $call($client);
        } catch (ConnectionException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
            $this->close();

            return $call($this->client());
        }
    }

    /**
     * @param string $method A list method.
     * @return array<string, mixed>|null The raw `result` of the last answer to it, with its cache hints.
     */
    public function lastResult(string $method): ?array
    {
        return $this->http?->lastResult($method);
    }

    /**
     * @return string|null The challenge of the server's last 403 since the previous call, which this forgets.
     */
    public function takeForbidden(): ?string
    {
        return $this->http?->takeForbidden();
    }

    /**
     * @return string|null The `WWW-Authenticate` header of the server's last answer, null when it had none.
     */
    public function lastChallenge(): ?string
    {
        return $this->http?->lastChallenge();
    }

    /**
     * @return int|null The HTTP status of the last answer from the server, null for stdio or when none came.
     */
    public function lastStatus(): ?int
    {
        return $this->http?->lastStatus();
    }

    /**
     * Ends the connection: the HTTP session is deleted, the stdio server stopped.
     *
     * @return void
     */
    public function close(): void
    {
        $this->client?->disconnect();
        $this->client = null;
    }

    /**
     * @return \Mcp\Client\Transport\TransportInterface
     */
    private function transport(): TransportInterface
    {
        if ($this->config->command !== null) {
            $env = [];
            foreach (self::INHERITED_ENV as $name) {
                $value = getenv($name);
                if (is_string($value)) {
                    $env[$name] = $value;
                }
            }

            return new StdioTransport(
                $this->config->command,
                $this->config->args,
                $this->config->cwd,
                $this->config->env + $env,
                new McpLogger(),
            );
        }

        $this->http = new WatchedHttpClient($this->factory->create($this->config), $this->config);

        return new HttpTransport(
            (string)$this->config->url,
            $this->headers + $this->config->headers,
            $this->http,
            new RequestFactory(),
            new StreamFactory(),
            new McpLogger(),
        );
    }
}
