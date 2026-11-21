<?php
declare(strict_types=1);

namespace CakeDC\Mcp\TestSuite;

use LogicException;
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
     * The most times a handler may suspend itself (to send a notification) in one request.
     */
    private const MAX_STEPS = 1000000;

    /**
     * Every message the server sent, decoded.
     *
     * @var list<array<string, mixed>>
     */
    public array $sent = [];

    /**
     * Set when the last handler stopped on a request to the client.
     */
    private bool $waitingOnClient = false;

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
        $this->driveFiber();
    }

    /**
     * Whether the last tool stopped on a request to the client (sampling, elicitation), which nothing here answers.
     *
     * @return bool
     */
    public function waitsOnClient(): bool
    {
        return $this->waitingOnClient;
    }

    /**
     * Runs a handler that suspended itself to notify the client, as the SDK's stdio transport does: sends what it
     * queued and resumes it until it ends, then sends its result. A handler that waits on an answer from the
     * client is left suspended.
     *
     * @return void
     */
    private function driveFiber(): void
    {
        $this->waitingOnClient = false;
        // A bound, in case a handler suspends forever without asking anything; reaching it fails loudly.
        for ($step = 0; $this->sessionFiber !== null; $step++) {
            if ($step >= self::MAX_STEPS) {
                throw new LogicException(sprintf(
                    'The handler suspended itself %d times without ending; RecordingTransport stopped it.',
                    self::MAX_STEPS,
                ));
            }
            $fiber = $this->sessionFiber;
            $this->flushOutgoing();
            if ($fiber->isTerminated()) {
                $this->sessionFiber = null;
                $this->send((string)json_encode($fiber->getReturn()), []);

                return;
            }
            if (!$fiber->isSuspended()) {
                return;
            }
            if ($this->getPendingRequests($this->sessionId, $fiber) !== []) {
                $this->waitingOnClient = true;

                return;
            }
            $this->handleFiberYield($fiber->resume(), $this->sessionId, $fiber);
        }
    }

    /**
     * @return void
     */
    private function flushOutgoing(): void
    {
        foreach ($this->getOutgoingMessages($this->sessionId) as $message) {
            $this->send($message['message'], $message['context']);
        }
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
