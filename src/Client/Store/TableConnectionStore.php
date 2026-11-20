<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Store;

use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\Table;
use Cake\Utility\Security;
use CakeDC\Mcp\Client\ClientConfig;
use InvalidArgumentException;

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
        $row = $this->connections()->find()->where(['client' => $client, 'user_id' => $userId])->first();
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
        );
    }

    /**
     * @inheritDoc
     */
    public function save(StoredConnection $connection): void
    {
        $table = $this->connections();
        $row = $table->find()->where(['client' => $connection->client, 'user_id' => $connection->userId])->first();
        $data = [
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
        ];
        $entity = $row instanceof EntityInterface
            ? $table->patchEntity($row, $data, ['validate' => false])
            : $table->newEntity($data, ['validate' => false]);
        $table->saveOrFail($entity);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $client, string $userId): void
    {
        $this->connections()->deleteAll(['client' => $client, 'user_id' => $userId]);
    }

    /**
     * @inheritDoc
     */
    public function registration(string $client, string $issuer): ?Registration
    {
        $row = $this->registrations()->find()->where(['client' => $client, 'issuer' => $issuer])->first();
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
        $table = $this->registrations();
        $row = $table->find()->where(['client' => $registration->client, 'issuer' => $registration->issuer])->first();
        $data = [
            'client' => $registration->client,
            'issuer' => $registration->issuer,
            'redirect_uri' => $registration->redirectUri,
            'oauth_client_id' => $registration->clientId,
            'client_secret' => $this->encrypt($registration->clientSecret),
            'auth_method' => $registration->authMethod,
        ];
        $entity = $row instanceof EntityInterface
            ? $table->patchEntity($row, $data, ['validate' => false])
            : $table->newEntity($data, ['validate' => false]);
        $table->saveOrFail($entity);
    }

    /**
     * @inheritDoc
     */
    public function deleteRegistration(string $client, string $issuer): void
    {
        $this->registrations()->deleteAll(['client' => $client, 'issuer' => $issuer]);
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
}
