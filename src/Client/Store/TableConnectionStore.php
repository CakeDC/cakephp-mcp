<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Store;

use Cake\Database\Exception\QueryException;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use Cake\Utility\Security;
use CakeDC\Mcp\Client\ClientConfig;
use InvalidArgumentException;
use Throwable;

/**
 * The default store: the plugin's tables `mcp_client_connections` and `mcp_client_registrations` (run the plugin's
 * migration when you use user mode). Access tokens, refresh tokens and client secrets are stored as base64 of
 * `Security::encrypt()`, with `Mcp.client.encryptionKey` or else the application's salt. A row that does not decrypt
 * (the key changed) reads as no connection, so the user signs in again.
 */
class TableConnectionStore implements ConnectionStoreInterface
{
    use LocatorAwareTrait;

    private string $key;

    /**
     * @param string|null $key The encryption key (at least 32 bytes); `Mcp.client.encryptionKey` or the salt when null.
     */
    public function __construct(?string $key = null)
    {
        $key ??= ClientConfig::setting('encryptionKey');
        $this->key = is_string($key) && $key !== '' ? $key : Security::getSalt();
        if (strlen($this->key) < 32) {
            throw new InvalidArgumentException(
                'The MCP client token store needs a key of at least 32 bytes: set "Mcp.client.encryptionKey" or a '
                . 'longer Security.salt.',
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function get(string $client, string $userId): ?StoredConnection
    {
        $row = $this->connections()->find()
            ->where(['connection_key' => self::connectionKey($client, $userId)])
            ->first();
        if (!$row instanceof EntityInterface) {
            return null;
        }
        $access = $this->decrypt($row->get('access_token'));
        $refresh = $this->decrypt($row->get('refresh_token'));
        $secret = $this->decrypt($row->get('client_secret'));
        if (
            $access === null
            || ($row->get('refresh_token') !== null && $refresh === null)
            || ($row->get('client_secret') !== null && $secret === null)
        ) {
            return null;
        }
        $expires = $row->get('expires');

        return new StoredConnection(
            $client,
            $userId,
            $access,
            $refresh,
            $expires instanceof DateTime ? $expires->getTimestamp() : null,
            self::nullableString($row->get('scope')),
            (string)$row->get('issuer'),
            (string)$row->get('token_url'),
            self::nullableString($row->get('revocation_url')),
            (string)$row->get('oauth_client_id'),
            $secret,
            (string)$row->get('auth_method'),
            (string)$row->get('resource'),
            self::nullableString($row->get('step_up_scope')),
        );
    }

    /**
     * @inheritDoc
     */
    public function save(StoredConnection $connection): void
    {
        $data = [
            'connection_key' => self::connectionKey($connection->client, $connection->userId),
            'client' => $connection->client,
            'user_id' => $connection->userId,
            'access_token' => $this->encrypt($connection->accessToken),
            'refresh_token' => $this->encrypt($connection->refreshToken),
            'expires' => $connection->expiresAt === null ? null : DateTime::createFromTimestamp($connection->expiresAt),
            'scope' => $connection->scope,
            'issuer' => $connection->issuer,
            'token_url' => $connection->tokenUrl,
            'revocation_url' => $connection->revocationUrl,
            'oauth_client_id' => $connection->clientId,
            'client_secret' => $this->encrypt($connection->clientSecret),
            'auth_method' => $connection->authMethod,
            'resource' => $connection->resource,
            'step_up_scope' => $connection->stepUpScope,
            // A refresh of the grant replaced here must not write the old grant's tokens over this one.
            'refresh_claim_id' => null,
            'refresh_claimed_at' => null,
        ];
        $this->upsert($this->connections(), 'connection_key', $data);
    }

    /**
     * @inheritDoc
     */
    public function markStepUp(string $client, string $userId, string $scope): void
    {
        $this->connections()->updateAll(
            ['step_up_scope' => $scope],
            ['connection_key' => self::connectionKey($client, $userId)],
        );
    }

    /**
     * @inheritDoc
     */
    public function saveRefreshed(StoredConnection $connection, string $claimId): bool
    {
        // One conditional UPDATE: a deleted row, or a claim that is no longer this one, matches nothing.
        return $this->connections()->updateAll(
            [
                'access_token' => $this->encrypt($connection->accessToken),
                'refresh_token' => $this->encrypt($connection->refreshToken),
                'expires' => $connection->expiresAt === null
                    ? null
                    : DateTime::createFromTimestamp($connection->expiresAt),
                'scope' => $connection->scope,
            ],
            [
                'connection_key' => self::connectionKey($connection->client, $connection->userId),
                'refresh_claim_id' => $claimId,
            ],
        ) === 1;
    }

    /**
     * @inheritDoc
     */
    public function claimRefresh(string $client, string $userId, string $claimId, int $leaseSeconds): bool
    {
        $now = DateTime::now();

        // One conditional UPDATE: only one request can turn a free (or expired) claim into its own.
        return $this->connections()->updateAll(
            ['refresh_claim_id' => $claimId, 'refresh_claimed_at' => $now],
            [
                'connection_key' => self::connectionKey($client, $userId),
                'OR' => [
                    'refresh_claim_id IS' => null,
                    'refresh_claimed_at <' => $now->subSeconds($leaseSeconds),
                ],
            ],
        ) === 1;
    }

    /**
     * @inheritDoc
     */
    public function releaseRefresh(string $client, string $userId, string $claimId): void
    {
        $this->connections()->updateAll(
            ['refresh_claim_id' => null, 'refresh_claimed_at' => null],
            ['connection_key' => self::connectionKey($client, $userId), 'refresh_claim_id' => $claimId],
        );
    }

    /**
     * @inheritDoc
     */
    public function delete(string $client, string $userId): void
    {
        $this->connections()->deleteAll(['connection_key' => self::connectionKey($client, $userId)]);
    }

    /**
     * @inheritDoc
     */
    public function registration(string $client, string $issuer): ?Registration
    {
        $row = $this->registrations()->find()
            ->where(['registration_key' => self::registrationKey($client, $issuer)])
            ->first();
        if (!$row instanceof EntityInterface) {
            return null;
        }
        $secret = $this->decrypt($row->get('client_secret'));
        if ($row->get('client_secret') !== null && $secret === null) {
            return null;
        }

        return new Registration(
            $client,
            $issuer,
            (string)$row->get('redirect_uri'),
            (string)$row->get('oauth_client_id'),
            $secret,
            (string)$row->get('auth_method'),
        );
    }

    /**
     * @inheritDoc
     */
    public function saveRegistration(Registration $registration): void
    {
        $data = [
            'registration_key' => self::registrationKey($registration->client, $registration->issuer),
            'client' => $registration->client,
            'issuer' => $registration->issuer,
            'redirect_uri' => $registration->redirectUri,
            'oauth_client_id' => $registration->clientId,
            'client_secret' => $this->encrypt($registration->clientSecret),
            'auth_method' => $registration->authMethod,
        ];
        $this->upsert($this->registrations(), 'registration_key', $data);
    }

    /**
     * Updates the row with $data's key, or inserts it. When another request inserts the same key between the read
     * and the insert (two first requests of a user at once), the unique index refuses the insert: the row is read
     * again and updated, so both succeed and the last one wins.
     *
     * @param \Cake\ORM\Table $table The table.
     * @param string $key The unique key column.
     * @param array<string, mixed> $data The row.
     * @return void
     */
    private function upsert(Table $table, string $key, array $data): void
    {
        for ($attempt = 0;; $attempt++) {
            $row = $table->find()->where([$key => $data[$key]])->first();
            $entity = $row instanceof EntityInterface
                ? $table->patchEntity($row, $data, ['validate' => false])
                : $table->newEntity($data, ['validate' => false]);
            try {
                $table->saveOrFail($entity);

                return;
            } catch (Throwable $e) {
                // The ORM declares no exception here; the driver's QueryException is what the index raises.
                if (!$e instanceof QueryException || $row instanceof EntityInterface || $attempt > 0) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteRegistration(string $client, string $issuer): void
    {
        $this->registrations()->deleteAll(['registration_key' => self::registrationKey($client, $issuer)]);
    }

    /**
     * @return \Cake\ORM\Table
     */
    protected function connections(): Table
    {
        return $this->fetchTable('CakeDC/Mcp.McpClientConnections');
    }

    /**
     * @return \Cake\ORM\Table
     */
    protected function registrations(): Table
    {
        return $this->fetchTable('CakeDC/Mcp.McpClientRegistrations');
    }

    /**
     * The HMAC salt, derived from the key: by default Security mixes in the application's salt, and changing that
     * would make every stored grant unreadable even with `Mcp.client.encryptionKey` set.
     *
     * @return string
     */
    private function hmacSalt(): string
    {
        return hash('sha256', 'cakedc-mcp-client:' . $this->key);
    }

    /**
     * @param string|null $plain A secret.
     * @return string|null It encrypted and base64 encoded.
     */
    private function encrypt(?string $plain): ?string
    {
        return $plain === null ? null : base64_encode(Security::encrypt($plain, $this->key, $this->hmacSalt()));
    }

    /**
     * @param mixed $stored A stored value.
     * @return string|null The secret, or null when there is none or it does not decrypt.
     */
    private function decrypt(mixed $stored): ?string
    {
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        $binary = base64_decode($stored, true);

        return $binary === false ? null : Security::decrypt($binary, $this->key, $this->hmacSalt());
    }

    /**
     * @param mixed $value A column value.
     * @return string|null
     */
    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The key a grant is found by: a hash, so that no collation of the database can match another user's grant.
     *
     * @param string $client The client's name.
     * @param string $userId The user.
     * @return string 64 hex characters.
     */
    public static function connectionKey(string $client, string $userId): string
    {
        return hash('sha256', $client . "\0" . $userId);
    }

    /**
     * The key a registration is found by, as connectionKey().
     *
     * @param string $client The client's name.
     * @param string $issuer The authorization server.
     * @return string 64 hex characters.
     */
    public static function registrationKey(string $client, string $issuer): string
    {
        return hash('sha256', $client . "\0" . $issuer);
    }
}
