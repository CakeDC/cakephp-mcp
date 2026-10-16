<?php
declare(strict_types=1);

namespace CakeDC\Mcp\OAuth;

use ArrayObject;
use CakeDC\Mcp\Identity\CurrentIdentity;
use Closure;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

/**
 * external mode: the SDK's JWT validator, plus the identity tools and the tool authorizer see. Without
 * `Mcp.auth.identity` the identity is an ArrayObject with `id` set to `sub` and the token claims under
 * `claims`. Claims are never merged at the top level: a `role` claim from the identity provider must not
 * reach role based authorization.
 */
final class ExternalTokenValidator implements AuthorizationTokenValidatorInterface
{
    /**
     * @param \Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface $validator Validates the JWT.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $current Receives the identity.
     * @param \Closure|null $identity `fn(AccessToken $token, mixed $identity): mixed` (`Mcp.auth.identity`);
     *   null, false or any other scalar rejects the token with 401 `invalid_token`.
     */
    public function __construct(
        private readonly AuthorizationTokenValidatorInterface $validator,
        private readonly CurrentIdentity $current,
        private readonly ?Closure $identity = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function validate(string $accessToken): AuthorizationResult
    {
        $result = $this->validator->validate($accessToken);
        $token = $result->getAccessToken();
        if (!$result->isAllowed() || $token === null) {
            return $result;
        }
        $subject = $token->getSubject();
        $identity = $subject === null ? null : new ArrayObject(['id' => $subject, 'claims' => $token->getClaims()]);
        if ($this->identity !== null) {
            $identity = ($this->identity)($token, $identity);
        }
        // An identity is an array or an object: null, false or any other scalar never passes for one.
        if (!is_array($identity) && !is_object($identity)) {
            return AuthorizationResult::unauthorized(
                'invalid_token',
                'The owner of this access token is not known to this application.',
            );
        }
        $this->current->set($identity);

        return $result;
    }
}
