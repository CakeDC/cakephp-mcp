<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Store;

/**
 * Where user mode keeps each user's grant and the OAuth clients the application registered. The default is
 * TableConnectionStore; set another class in `Mcp.client.store`. An implementation keeps tokens and secrets
 * unreadable at rest.
 */
interface ConnectionStoreInterface
{
    /**
     * @param string $client The client's name in `Mcp.clients`.
     * @param string $userId The user.
     * @return \CakeDC\Mcp\Client\Store\StoredConnection|null The user's grant, or null when there is none it can read.
     */
    public function get(string $client, string $userId): ?StoredConnection;

    /**
     * Inserts the grant, or replaces the user's grant for that client.
     *
     * @param \CakeDC\Mcp\Client\Store\StoredConnection $connection The grant.
     * @return void
     */
    public function save(StoredConnection $connection): void;

    /**
     * Replaces the user's grant with what a refresh produced, only while $claimId is still its refresh claim:
     * a grant deleted meanwhile (the user disconnected) is never stored again, and neither is one whose claim
     * another request took over.
     *
     * @param \CakeDC\Mcp\Client\Store\StoredConnection $connection The refreshed grant.
     * @param string $claimId The id given to claimRefresh().
     * @return bool Whether it was stored.
     */
    public function saveRefreshed(StoredConnection $connection, string $claimId): bool;

    /**
     * @param string $client The client's name.
     * @param string $userId The user.
     * @return void
     */
    public function delete(string $client, string $userId): void;

    /**
     * Claims the refresh of the user's grant for one request, atomically: many authorization servers end a grant
     * when the same refresh token is used twice, so two requests must never refresh it at once.
     *
     * @param string $client The client's name.
     * @param string $userId The user.
     * @param string $claimId A new id for this attempt.
     * @param int $leaseSeconds How long the claim holds; after that another request may take it over.
     * @return bool Whether this request may refresh; false when another holds a live claim or there is no grant.
     */
    public function claimRefresh(string $client, string $userId, string $claimId, int $leaseSeconds): bool;

    /**
     * Ends the claim $claimId, if it is still the current one.
     *
     * @param string $client The client's name.
     * @param string $userId The user.
     * @param string $claimId The id given to claimRefresh().
     * @return void
     */
    public function releaseRefresh(string $client, string $userId, string $claimId): void;

    /**
     * @param string $client The client's name.
     * @param string $issuer The authorization server.
     * @return \CakeDC\Mcp\Client\Store\Registration|null The OAuth client registered there, or null.
     */
    public function registration(string $client, string $issuer): ?Registration;

    /**
     * Inserts the registration, or replaces the one for that client and issuer.
     *
     * @param \CakeDC\Mcp\Client\Store\Registration $registration The registration.
     * @return void
     */
    public function saveRegistration(Registration $registration): void;

    /**
     * @param string $client The client's name.
     * @param string $issuer The authorization server.
     * @return void
     */
    public function deleteRegistration(string $client, string $issuer): void;
}
