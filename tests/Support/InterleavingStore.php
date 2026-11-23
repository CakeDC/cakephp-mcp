<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use CakeDC\Mcp\Client\Store\ConnectionStoreInterface;
use CakeDC\Mcp\Client\Store\Registration;
use CakeDC\Mcp\Client\Store\StoredConnection;
use Closure;

/**
 * A token store that runs what another request does at a chosen moment of this one, to test races between
 * requests of the same user (each in its own PHP process in production, sharing the table).
 */
final class InterleavingStore implements ConnectionStoreInterface
{
    /**
     * Runs once, just before this request claims the refresh.
     */
    public ?Closure $beforeClaim = null;

    /**
     * Runs once, just before this request writes a grant or its step-up mark.
     */
    public ?Closure $beforeWrite = null;

    public function __construct(private readonly ConnectionStoreInterface $inner)
    {
    }

    public function get(string $client, string $userId): ?StoredConnection
    {
        return $this->inner->get($client, $userId);
    }

    public function save(StoredConnection $connection): void
    {
        $this->runBeforeWrite();
        $this->inner->save($connection);
    }

    public function markStepUp(string $client, string $userId, string $scope): void
    {
        $this->runBeforeWrite();
        $this->inner->markStepUp($client, $userId, $scope);
    }

    private function runBeforeWrite(): void
    {
        $before = $this->beforeWrite;
        $this->beforeWrite = null;
        if ($before !== null) {
            $before();
        }
    }

    public function saveRefreshed(StoredConnection $connection, string $claimId): bool
    {
        return $this->inner->saveRefreshed($connection, $claimId);
    }

    public function delete(string $client, string $userId): void
    {
        $this->inner->delete($client, $userId);
    }

    public function claimRefresh(string $client, string $userId, string $claimId, int $leaseSeconds): bool
    {
        $before = $this->beforeClaim;
        $this->beforeClaim = null;
        if ($before !== null) {
            $before();
        }

        return $this->inner->claimRefresh($client, $userId, $claimId, $leaseSeconds);
    }

    public function releaseRefresh(string $client, string $userId, string $claimId): void
    {
        $this->inner->releaseRefresh($client, $userId, $claimId);
    }

    public function registration(string $client, string $issuer): ?Registration
    {
        return $this->inner->registration($client, $issuer);
    }

    public function saveRegistration(Registration $registration): void
    {
        $this->inner->saveRegistration($registration);
    }

    public function deleteRegistration(string $client, string $issuer): void
    {
        $this->inner->deleteRegistration($client, $issuer);
    }
}
