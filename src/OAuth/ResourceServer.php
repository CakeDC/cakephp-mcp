<?php
declare(strict_types=1);

namespace CakeDC\Mcp\OAuth;

use Cake\Http\Client;
use Cake\Http\RequestFactory;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\OAuth\Server\AuthorizationServer;
use CakeDC\OAuth\Server\Resource\ProtectedResource;
use CakeDC\OAuthServer\OAuthServer;
use Firebase\JWT\JWT;
use LogicException;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * The MCP endpoint as an OAuth 2.1 protected resource: its RFC 9728 metadata, the token validator of
 * the configured mode, and the SDK middleware that enforces both. Builds nothing over the network.
 */
final class ResourceServer
{
    private ?AuthorizationServer $authorizationServer = null;

    private ?ProtectedResource $declared = null;

    /**
     * @param \CakeDC\Mcp\OAuth\AuthSettings $settings The `Mcp.auth` settings, not in host mode.
     */
    public function __construct(private readonly AuthSettings $settings)
    {
    }

    /**
     * The protected resource metadata (RFC 9728).
     *
     * @return \Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata
     */
    public function metadata(): ProtectedResourceMetadata
    {
        return new ProtectedResourceMetadata(
            $this->resource(),
            [$this->issuer()],
            $this->scopesSupported(),
            $this->settings->resourceName,
            $this->settings->resourceDocumentation,
        );
    }

    /**
     * The SDK middleware: Bearer token, scope policy and the challenges MCP clients follow.
     *
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity Receives the token owner.
     * @param \Psr\Http\Message\ResponseFactoryInterface $responseFactory For its responses.
     * @param \Psr\Http\Message\StreamFactoryInterface $streamFactory For its bodies.
     * @return \Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware
     */
    public function middleware(
        CurrentIdentity $identity,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
    ): AuthorizationMiddleware {
        return new AuthorizationMiddleware(
            $this->validator($identity),
            $this->metadata(),
            $this->settings->scopePolicy(),
            $responseFactory,
            $streamFactory,
        );
    }

    /**
     * The token validator of the configured mode; it also records the identity in $identity.
     *
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity Receives the token owner.
     * @return \Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface
     */
    public function validator(CurrentIdentity $identity): AuthorizationTokenValidatorInterface
    {
        return match ($this->settings->mode) {
            AuthSettings::OAUTH => new OAuthServerTokenValidator(
                $this->authorizationServer()->tokenValidator(),
                $this->resource(),
                $identity,
                $this->settings->identity,
            ),
            AuthSettings::EXTERNAL => new ExternalTokenValidator(
                $this->jwtValidator(),
                $identity,
                $this->settings->identity,
            ),
            default => throw new LogicException(
                sprintf('The "%s" mode has no token validator.', $this->settings->mode),
            ),
        };
    }

    /**
     * @return string
     */
    private function resource(): string
    {
        return $this->settings->mode === AuthSettings::OAUTH
            ? $this->declared()->identifier
            : $this->settings->resource;
    }

    /**
     * @return string
     */
    private function issuer(): string
    {
        if ($this->settings->mode === AuthSettings::OAUTH) {
            return $this->authorizationServer()->config()->issuer;
        }

        if ($this->settings->external !== null) {
            return $this->settings->external->issuer;
        }

        throw new LogicException(sprintf('The "%s" mode has no authorization server.', $this->settings->mode));
    }

    /**
     * @return list<string>
     */
    private function scopesSupported(): array
    {
        return $this->settings->mode === AuthSettings::OAUTH
            ? $this->declared()->scopeNames()
            : $this->settings->configuredScopes();
    }

    /**
     * The SDK's JWT validator for the external issuer. Discovery and keys are fetched on first use.
     *
     * @return \Mcp\Server\Transport\Http\OAuth\JwtTokenValidator
     */
    private function jwtValidator(): JwtTokenValidator
    {
        $external = $this->settings->external;
        if ($external === null) {
            throw new LogicException('External mode without external settings.');
        }
        if (!class_exists(JWT::class) || !interface_exists(CacheItemPoolInterface::class)) {
            throw new RuntimeException(
                '"Mcp.auth.mode" external needs firebase/php-jwt and psr/cache: run '
                . '`composer require firebase/php-jwt psr/cache`.',
            );
        }

        return JwtTokenValidator::fromIssuer(
            $external->issuer,
            $external->audiences,
            new CakeCachePool($external->cache),
            $external->httpClient ?? new Client(['timeout' => 5]),
            new RequestFactory(),
            $external->algorithms,
            $external->scopeClaim,
            $external->tokenType,
            $external->leeway,
        );
    }

    /**
     * The authorization server of this application, once its resource and scopes are checked.
     *
     * @return \CakeDC\OAuth\Server\AuthorizationServer
     */
    private function authorizationServer(): AuthorizationServer
    {
        if ($this->authorizationServer !== null) {
            return $this->authorizationServer;
        }
        if (!class_exists(OAuthServer::class)) {
            throw new RuntimeException(
                '"Mcp.auth.mode" oauth needs cakedc/cakephp-oauth-server: run '
                . '`composer require cakedc/cakephp-oauth-server` and load the CakeDC/OAuthServer plugin.',
            );
        }
        $server = OAuthServer::server();
        $resources = $server->config()->resources;
        $declared = $resources->get($this->settings->resource);
        if ($declared === null) {
            throw new RuntimeException(sprintf(
                'The MCP resource "%s" (Mcp.auth.resource) is not declared in OAuthServer.resources (declared: %s).',
                $this->settings->resource,
                implode(', ', array_map(
                    fn(ProtectedResource $resource): string => $resource->identifier,
                    $resources->all(),
                )),
            ));
        }
        foreach ($this->settings->configuredScopes() as $scope) {
            if (!$declared->hasScope($scope)) {
                throw new RuntimeException(sprintf(
                    'The scope "%s" in Mcp.auth.scopes is not declared by the OAuthServer resource "%s".',
                    $scope,
                    $declared->identifier,
                ));
            }
        }
        $this->declared = $declared;

        return $this->authorizationServer = $server;
    }

    /**
     * @return \CakeDC\OAuth\Server\Resource\ProtectedResource
     */
    private function declared(): ProtectedResource
    {
        $this->authorizationServer();
        assert($this->declared !== null);

        return $this->declared;
    }
}
