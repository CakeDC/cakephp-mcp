<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Conformance;

use CakeDC\Mcp\Client\Store\ConnectionStoreInterface;
use CakeDC\Mcp\Client\Store\Registration;
use CakeDC\Mcp\Client\Store\StoredConnection;

/**
 * Grants and registrations in memory, for the one process of a conformance scenario (no database).
 */
final class MemoryConnectionStore implements ConnectionStoreInterface
{
    /**
     * @var array<string, \CakeDC\Mcp\Client\Store\StoredConnection>
     */
    private array $connections = [];

    /**
     * @var array<string, \CakeDC\Mcp\Client\Store\Registration>
     */
    private array $registrations = [];

    /**
     * @inheritDoc
     */
    public function get(string $client, string $userId): ?StoredConnection
    {
        return $this->connections[$client . "\0" . $userId] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function save(StoredConnection $connection): void
    {
        $this->connections[$connection->client . "\0" . $connection->userId] = $connection;
    }

    /**
     * @inheritDoc
     */
    public function saveRefreshed(StoredConnection $connection, string $claimId): bool
    {
        $this->save($connection);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function markStepUp(string $client, string $userId, string $scope): void
    {
        $stored = $this->get($client, $userId);
        if ($stored !== null) {
            $this->save($stored->withStepUp(explode(' ', $scope)));
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(string $client, string $userId): void
    {
        unset($this->connections[$client . "\0" . $userId]);
    }

    /**
     * @inheritDoc
     */
    public function claimRefresh(string $client, string $userId, string $claimId, int $leaseSeconds): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function releaseRefresh(string $client, string $userId, string $claimId): void
    {
    }

    /**
     * @inheritDoc
     */
    public function registration(string $client, string $issuer): ?Registration
    {
        return $this->registrations[$client . "\0" . $issuer] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function saveRegistration(Registration $registration): void
    {
        $this->registrations[$registration->client . "\0" . $registration->issuer] = $registration;
    }

    /**
     * @inheritDoc
     */
    public function deleteRegistration(string $client, string $issuer): void
    {
        unset($this->registrations[$client . "\0" . $issuer]);
    }
}
