<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\OAuth;

/**
 * A sign-in in progress, kept in the user's session between the connect and the callback requests. It holds no
 * client secret (read again from the config or the store at the callback).
 */
final class PendingAuthorization
{
    /**
     * Seconds a sign-in may take.
     */
    public const LIFETIME = 600;

    /**
     * @param string $client The client's name.
     * @param string $userId The user who started it.
     * @param string $state The `state` parameter.
     * @param string $verifier The PKCE code verifier.
     * @param string $redirectUri The callback URL sent.
     * @param string $returnTo The local path to send the user back to.
     * @param int $createdAt When it started (Unix time).
     * @param string $issuer The authorization server.
     * @param string $tokenUrl Its token endpoint.
     * @param string|null $revocationUrl Its revocation endpoint.
     * @param bool $trusted Whether the endpoints come from the config.
     * @param bool $issParameter Whether the response must carry `iss`.
     * @param string $resource The `resource` parameter.
     * @param string $clientId The client id.
     * @param string $authMethod How the client authenticates.
     */
    public function __construct(
        public readonly string $client,
        public readonly string $userId,
        public readonly string $state,
        public readonly string $verifier,
        public readonly string $redirectUri,
        public readonly string $returnTo,
        public readonly int $createdAt,
        public readonly string $issuer,
        public readonly string $tokenUrl,
        public readonly ?string $revocationUrl,
        public readonly bool $trusted,
        public readonly bool $issParameter,
        public readonly string $resource,
        public readonly string $clientId,
        public readonly string $authMethod,
    ) {
    }

    /**
     * @return array<string, scalar|null> For the session.
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param mixed $data What toArray() gave, read back from the session.
     * @return self|null Null when it is not a whole pending sign-in.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }
        $strings = [
            'client', 'userId', 'state', 'verifier', 'redirectUri', 'returnTo', 'issuer', 'tokenUrl', 'resource',
            'clientId', 'authMethod',
        ];
        foreach ($strings as $key) {
            if (!is_string($data[$key] ?? null)) {
                return null;
            }
        }
        if (
            !is_int($data['createdAt'] ?? null)
            || !is_bool($data['trusted'] ?? null)
            || !is_bool($data['issParameter'] ?? null)
            || !(is_string($data['revocationUrl'] ?? null) || ($data['revocationUrl'] ?? null) === null)
        ) {
            return null;
        }

        return new self(
            $data['client'],
            $data['userId'],
            $data['state'],
            $data['verifier'],
            $data['redirectUri'],
            $data['returnTo'],
            $data['createdAt'],
            $data['issuer'],
            $data['tokenUrl'],
            $data['revocationUrl'],
            $data['trusted'],
            $data['issParameter'],
            $data['resource'],
            $data['clientId'],
            $data['authMethod'],
        );
    }
}
