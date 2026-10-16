<?php
declare(strict_types=1);

namespace CakeDC\Mcp\OAuth;

use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\OAuth\Server\Token\TokenValidator;
use CakeDC\OAuth\Server\Token\ValidationFailure;
use Closure;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

/**
 * oauth mode: validates the access tokens of cakedc/cakephp-oauth-server in this application, and
 * records the token owner's identity as the CurrentIdentity tools and the tool authorizer see.
 */
final class OAuthServerTokenValidator implements AuthorizationTokenValidatorInterface
{
    /**
     * @param \CakeDC\OAuth\Server\Token\TokenValidator $validator The authorization server's validator.
     * @param string $resource The MCP endpoint's resource identifier.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $current Receives the identity.
     * @param \Closure|null $identity `fn(AccessToken $token, mixed $identity): mixed` (`Mcp.auth.identity`);
     *   null, false or any other scalar rejects the token with 401 `invalid_token`.
     */
    public function __construct(
        private readonly TokenValidator $validator,
        private readonly string $resource,
        private readonly CurrentIdentity $current,
        private readonly ?Closure $identity = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function validate(string $accessToken): AuthorizationResult
    {
        $result = $this->validator->validate($accessToken, $this->resource);
        if ($result instanceof ValidationFailure) {
            return AuthorizationResult::unauthorized($result->error, $result->description);
        }
        // The claims of the core's MCP SDK bridge.
        $token = new AccessToken($result->scopes, array_filter([
            'sub' => $result->owner->subject,
            'name' => $result->owner->displayName,
            'client_id' => $result->client?->clientId,
            'aud' => $result->resource->identifier,
            'exp' => $result->token->expiresAt?->getTimestamp(),
            'iat' => $result->token->createdAt->getTimestamp(),
            'subject_type' => $result->token->subjectType->value,
        ], fn(mixed $value): bool => $value !== null));

        $identity = $result->owner->identity;
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

        return AuthorizationResult::allow($token);
    }
}
