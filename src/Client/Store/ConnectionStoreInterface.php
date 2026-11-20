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
     * @param string $client The client's name.
     * @param string $userId The user.
     * @return void
     */
    public function delete(string $client, string $userId): void;

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
