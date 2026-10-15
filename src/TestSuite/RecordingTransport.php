<?php
declare(strict_types=1);

namespace CakeDC\Mcp\TestSuite;

use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Feeds the server a fixed list of JSON-RPC messages and keeps what it answers.
 *
 * @internal Used by McpTestTrait and the plugin's own tests.
 */
class RecordingTransport extends InMemoryTransport
{
    /**
     * Every message the server sent, decoded.
     *
     * @var list<array<string, mixed>>
     */
    public array $sent = [];

    /**
     * @param list<string> $messages The JSON-RPC messages to feed, in order.
     * @param \Mcp\Server\Authorization\AccessToken|null $accessToken Sent with every message, as the HTTP endpoint does.
     */
    public function __construct(array $messages = [], private readonly ?AccessToken $accessToken = null)
    {
        parent::__construct($messages);
    }

    /**
     * @inheritDoc
     */
    protected function handleMessage(string $payload, ?Uuid $sessionId, ?AccessToken $accessToken = null): void
    {
        parent::handleMessage($payload, $sessionId, $accessToken ?? $this->accessToken);
    }

    /**
     * @inheritDoc
     */
    public function send(string $data, array $context): void
    {
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            $this->sent[] = $decoded;
        }
        parent::send($data, $context);
    }

    /**
     * @param string|int $id The id of the request.
     * @return array<string, mixed>|null The response to that request.
     */
    public function responseFor(string|int $id): ?array
    {
        foreach ($this->sent as $message) {
            if (($message['id'] ?? null) === $id) {
                return $message;
            }
        }

        return null;
    }
}
