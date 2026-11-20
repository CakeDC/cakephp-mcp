<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\OAuth;

use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\Http\HttpRequest;
use CakeDC\Mcp\Client\Http\OutboundHttp;
use CakeDC\Mcp\Client\Store\ConnectionStoreInterface;
use CakeDC\Mcp\Client\Store\Registration;

/**
 * The OAuth client the application is at an authorization server: the configured `clientId`, else the one it
 * registered there before (same callback), else a new dynamic registration (RFC 7591) that is stored, so every user
 * signs in with the same client.
 *
 * Adapted from ZuluBrain's Registrar.
 */
final class Registrar
{
    /**
     * The longest client id or secret kept.
     */
    private const MAX_LENGTH = 4096;

    private const AUTH_METHODS = ['none', 'client_secret_basic', 'client_secret_post'];

    /**
     * @param \CakeDC\Mcp\Client\Http\OutboundHttp $http Sends the registration request (always through the guard).
     * @param \CakeDC\Mcp\Client\Store\ConnectionStoreInterface $store Keeps registrations.
     * @param \CakeDC\Mcp\Client\ClientConfig $config The user mode client.
     */
    public function __construct(
        private OutboundHttp $http,
        private ConnectionStoreInterface $store,
        private ClientConfig $config,
    ) {
    }

    /**
     * @param \CakeDC\Mcp\Client\OAuth\AuthServer $server The authorization server.
     * @param string $redirectUri The callback URL.
     * @return \CakeDC\Mcp\Client\Store\Registration
     * @throws \CakeDC\Mcp\Client\OAuth\OAuthException When there is no way to get a client.
     */
    public function clientFor(AuthServer $server, string $redirectUri): Registration
    {
        $config = $this->config;
        if ($config->clientId !== null) {
            return new Registration(
                $config->name,
                $server->issuer,
                $redirectUri,
                $config->clientId,
                $config->clientSecret,
                self::automaticMethod($config->clientSecret !== null, $server->authMethods),
            );
        }
        $existing = $this->store->registration($config->name, $server->issuer);
        if ($existing !== null && $existing->redirectUri === $redirectUri) {
            return $existing;
        }
        if ($server->registrationUrl === null) {
            throw new OAuthException(sprintf(
                'The authorization server of "%s" does not register clients automatically: ask its owner for a '
                . 'client id and set "Mcp.clients.%s.clientId" (and "clientSecret" if it has one).',
                $config->name,
                $config->name,
            ));
        }

        $response = $this->http->send(new HttpRequest(
            'POST',
            $server->registrationUrl,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            (string)json_encode([
                'client_name' => (string)ClientConfig::setting('name'),
                'redirect_uris' => [$redirectUri],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => 'none',
            ], JSON_UNESCAPED_SLASHES),
            (float)$config->timeout,
        ), false);
        $answer = $response->json();
        $clientId = $answer['client_id'] ?? null;
        $secret = $answer['client_secret'] ?? null;
        if (
            ($response->status !== 200 && $response->status !== 201)
            || !self::isCredential($clientId)
            || ($secret !== null && !self::isCredential($secret))
        ) {
            throw new OAuthException(sprintf(
                'The authorization server of "%s" refused to register the application.',
                $config->name,
            ));
        }
        $method = $answer['token_endpoint_auth_method'] ?? null;
        if (!in_array($method, self::AUTH_METHODS, true)) {
            $method = $secret === null ? 'none' : 'client_secret_basic';
        }
        /** @var string $clientId */
        /** @var string|null $secret */
        $registration = new Registration($config->name, $server->issuer, $redirectUri, $clientId, $secret, $method);
        $this->store->saveRegistration($registration);

        return $registration;
    }

    /**
     * How a configured client authenticates: `none` without a secret; with one, `client_secret_basic` (the RFC 8414
     * default) unless the server lists `client_secret_post` and not basic.
     *
     * @param bool $hasSecret Whether there is a client secret.
     * @param list<string> $supported The server's `token_endpoint_auth_methods_supported`.
     * @return string
     */
    public static function automaticMethod(bool $hasSecret, array $supported): string
    {
        if (!$hasSecret) {
            return 'none';
        }
        if (!in_array('client_secret_basic', $supported, true) && in_array('client_secret_post', $supported, true)) {
            return 'client_secret_post';
        }

        return 'client_secret_basic';
    }

    /**
     * @param mixed $value A client id or secret from the server.
     * @return bool Whether it can be kept and sent back.
     */
    private static function isCredential(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= self::MAX_LENGTH
            && preg_match('/\A[\x20-\x7E]+\z/', $value) === 1;
    }
}
