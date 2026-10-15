<?php
declare(strict_types=1);

namespace TestApp;

use Mcp\Server\Transport\InMemoryTransport;

/**
 * Feeds queued JSON-RPC lines to the server and records every message it sends back.
 */
class RecordingTransport extends InMemoryTransport
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $sent = [];

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
     * @param string|int $id JSON-RPC id.
     * @return array<string, mixed>|null
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
