<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Cake\I18n\DateTime;
use Cake\Routing\Router;
use Cake\Utility\Text;
use CakeDC\Mcp\Client\Exception\ClientException;
use CakeDC\Mcp\Client\Exception\NotConnectedException;
use CakeDC\Mcp\Client\OAuth\Discovery;
use CakeDC\Mcp\Client\OAuth\OAuthException;
use CakeDC\Mcp\Client\Store\StoredConnection;
use Mcp\Exception\ExceptionInterface;

/**
 * One user's connection to a user mode client's server: `McpClients::get('linear')->for($user)`.
 *
 * Calls send the user's stored access token. It is refreshed when it expires within `Mcp.client.refreshLeeway`
 * seconds, and once more when the server answers 401; when there is no usable grant left, the call throws
 * NotConnectedException and the grant is deleted, so `isConnected()` is false and the user can connect again.
 */
final class UserConnection
{
    use CallsServerTrait;

    /**
     * How many times, and how long each, a request waits for another one's refresh: 10 seconds in all.
     */
    private const REFRESH_WAITS = 40;

    private const REFRESH_WAIT_MICROSECONDS = 250_000;

    private ?ClientSession $session = null;

    private ?string $sessionToken = null;

    /**
     * @param \CakeDC\Mcp\Client\ClientConfig $config The user mode client.
     * @param \CakeDC\Mcp\Client\ClientRegistry $registry Its HTTP client, store and token client.
     * @param string $userId The user.
     */
    public function __construct(
        private ClientConfig $config,
        private ClientRegistry $registry,
        private string $userId,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function config(): ClientConfig
    {
        return $this->config;
    }

    /**
     * @return string The user's id, as stored.
     */
    public function userId(): string
    {
        return $this->userId;
    }

    /**
     * @return bool Whether the user has a grant the client can use (a refresh token, or an access token that has not
     *   expired).
     */
    public function isConnected(): bool
    {
        $stored = $this->stored();

        return $stored !== null
            && ($stored->refreshToken !== null || !$stored->expiresWithin(0, DateTime::now()->getTimestamp()));
    }

    /**
     * The address of the plugin's connect route for this client; send the logged-in user there to sign in.
     *
     * @param string|null $redirect A local path to come back to afterwards.
     * @return string A full URL.
     */
    public function connectUrl(?string $redirect = null): string
    {
        $url = ['plugin' => 'CakeDC/Mcp', 'controller' => 'McpClient', 'action' => 'connect', $this->config->name];
        if ($redirect !== null) {
            $url['?'] = ['redirect' => $redirect];
        }

        return Router::url($url, true);
    }

    /**
     * Revokes the user's grant at the authorization server (best effort) and deletes it.
     *
     * @return void
     */
    public function disconnect(): void
    {
        $this->close();
        $store = $this->registry->store();
        $stored = $store->get($this->config->name, $this->userId);
        try {
            if ($stored !== null) {
                $trusted = $this->config->revocationUrl !== null
                    && $stored->revocationUrl === $this->config->revocationUrl;
                $this->registry->tokens($this->config)->revoke($stored, $trusted);
            }
        } finally {
            // Whatever revocation does, the local grant goes.
            $store->delete($this->config->name, $this->userId);
        }
    }

    /**
     * Ends the connection to the server; the next call opens a new one.
     *
     * @return void
     */
    public function close(): void
    {
        $this->session?->close();
        $this->session = null;
        $this->sessionToken = null;
    }

    /**
     * @inheritDoc
     */
    protected function listCache(): ?ListCache
    {
        // Only for a user who is still connected: a cached list is no way around a lost grant. Keyed by the access
        // token too, so a list is not served after a reconnection with another account.
        $stored = $this->config->cache !== null && $this->isConnected() ? $this->stored() : null;

        return $stored === null ? null : ListCache::for($this->config, $this->userId, $stored->accessToken);
    }

    /**
     * @inheritDoc
     */
    protected function lastResult(string $method): ?array
    {
        return $this->session?->lastResult($method);
    }

    /**
     * @inheritDoc
     */
    protected function run(callable $call): mixed
    {
        $session = $this->session($this->accessToken(false));
        $session->takeForbidden();
        try {
            return $session->call($call);
        } catch (ExceptionInterface $e) {
            $this->markStepUp($session->takeForbidden());
            // Only a call the server refused before running any of it: never one whose stream a 401 cut short.
            if (!$session->unauthorizedBeforeAccepted()) {
                throw $e;
            }
        }
        // The server no longer accepts the token: refresh once and try again.
        $session = $this->session($this->accessToken(true));
        try {
            return $session->call($call);
        } catch (ExceptionInterface $e) {
            if ($session->lastStatus() !== 401) {
                throw $e;
            }
            $this->forget();

            throw $this->notConnected();
        }
    }

    /**
     * Whether the server answered a call of this user with `403 insufficient_scope`: send the user to
     * `connectUrl()` to sign in again with the scopes of `stepUpScopes()`.
     *
     * @return bool
     */
    public function needsMoreScope(): bool
    {
        return $this->stepUpScopes() !== [];
    }

    /**
     * @return list<string> The scopes the next connect asks for: those granted plus those the server asked for;
     *   empty when it asked for none.
     */
    public function stepUpScopes(): array
    {
        return $this->stored()?->stepUpScopes() ?? [];
    }

    /**
     * Marks the grant with the scopes of an `insufficient_scope` challenge, kept until the user connects again.
     *
     * @param string|null $challenge The `WWW-Authenticate` header of the 403.
     * @return void
     */
    private function markStepUp(?string $challenge): void
    {
        $parameters = $challenge === null ? [] : Discovery::bearerParameters($challenge);
        if (($parameters['error'] ?? null) !== 'insufficient_scope') {
            return;
        }
        // offline_access is the authorization server's to offer, as on the first sign-in (Discovery): one that
        // does not know it would refuse every connect while the mark asked for it.
        $scopes = array_values(array_filter(
            explode(' ', $parameters['scope'] ?? ''),
            fn(string $scope): bool => $scope !== 'offline_access'
                && preg_match('/\A[\x21\x23-\x5B\x5D-\x7E]+\z/', $scope) === 1,
        ));
        $stored = $this->stored();
        // A retry limit (MCP authorization, step-up): scopes the last sign-in already asked for were not granted,
        // and connecting again would ask for the same. The call fails; the user is not sent round again.
        if ($stored !== null && array_diff($scopes, $stored->requestedScopes()) === []) {
            return;
        }
        if ($stored !== null && $scopes !== []) {
            // Only the mark: the tokens may have been refreshed by another request since they were read.
            $this->registry->store()->markStepUp(
                $this->config->name,
                $this->userId,
                (string)$stored->withStepUp($scopes)->stepUpScope,
            );
        }
    }

    /**
     * The user's grant, unless it was made for another server than the one configured now (the `url` changed):
     * its token is never sent elsewhere.
     *
     * @return \CakeDC\Mcp\Client\Store\StoredConnection|null
     */
    private function stored(): ?StoredConnection
    {
        $stored = $this->registry->store()->get($this->config->name, $this->userId);

        return $stored !== null && Discovery::covers($stored->resource, (string)$this->config->url) ? $stored : null;
    }

    /**
     * @param bool $rejected Whether the server just refused the stored access token.
     * @return string An access token to send.
     * @throws \CakeDC\Mcp\Client\Exception\NotConnectedException
     */
    private function accessToken(bool $rejected): string
    {
        $stored = $this->stored();
        if ($stored === null) {
            throw $this->notConnected();
        }
        $now = DateTime::now()->getTimestamp();
        if (!$rejected && !$stored->expiresWithin((int)ClientConfig::setting('refreshLeeway'), $now)) {
            return $stored->accessToken;
        }
        if ($stored->refreshToken === null) {
            if ($rejected || $stored->expiresWithin(0, $now)) {
                $this->forget();

                throw $this->notConnected();
            }

            // About to expire and nothing to refresh it with: it is still good for now.
            return $stored->accessToken;
        }

        return $this->refresh($stored, $now);
    }

    /**
     * @param \CakeDC\Mcp\Client\Store\StoredConnection $stored The grant.
     * @param int $now The time now.
     * @return string The new access token.
     * @throws \CakeDC\Mcp\Client\Exception\NotConnectedException When the grant is no longer accepted.
     */
    private function refresh(StoredConnection $stored, int $now): string
    {
        $store = $this->registry->store();
        $claimId = Text::uuid();
        // Longer than the token request may take, so a claim never ends while its request is still running.
        if (!$store->claimRefresh($this->config->name, $this->userId, $claimId, $this->config->timeout + 30)) {
            return $this->waitForRefresh($stored);
        }
        try {
            // Read again: another request may have refreshed, stored and released between our read and our claim,
            // and the refresh token we read is then already spent.
            $fresh = $this->stored();
            if ($fresh === null) {
                throw $this->notConnected();
            }
            if ($fresh->accessToken !== $stored->accessToken) {
                return $fresh->accessToken;
            }

            return $this->refreshClaimed($fresh, $now, $claimId);
        } finally {
            $store->releaseRefresh($this->config->name, $this->userId, $claimId);
        }
    }

    /**
     * Another request is refreshing the grant: waits a bounded time for the token it stores, and never refreshes
     * again with the refresh token that request just sent.
     *
     * @param \CakeDC\Mcp\Client\Store\StoredConnection $stored The grant as read before.
     * @return string The new access token.
     * @throws \CakeDC\Mcp\Client\Exception\NotConnectedException When the grant went meanwhile.
     * @throws \CakeDC\Mcp\Client\Exception\ClientException When the other request did not finish in time.
     */
    private function waitForRefresh(StoredConnection $stored): string
    {
        for ($attempt = 0; $attempt < self::REFRESH_WAITS; $attempt++) {
            $this->registry->sleep(self::REFRESH_WAIT_MICROSECONDS);
            $fresh = $this->stored();
            if ($fresh === null) {
                throw $this->notConnected();
            }
            if ($fresh->accessToken !== $stored->accessToken) {
                return $fresh->accessToken;
            }
        }

        throw new ClientException(sprintf(
            'The connection to "%s" is being renewed by another request; try again in a moment.',
            $this->config->name,
        ));
    }

    /**
     * @param \CakeDC\Mcp\Client\Store\StoredConnection $stored The grant.
     * @param int $now The time now.
     * @param string $claimId This request's refresh claim.
     * @return string The new access token.
     * @throws \CakeDC\Mcp\Client\Exception\NotConnectedException When the grant is no longer accepted.
     */
    private function refreshClaimed(StoredConnection $stored, int $now, string $claimId): string
    {
        $store = $this->registry->store();
        $trusted = $this->config->tokenUrl !== null && $stored->tokenUrl === $this->config->tokenUrl;
        try {
            $tokens = $this->registry->tokens($this->config)->refresh($stored, $trusted);
        } catch (OAuthException $e) {
            if (!in_array($e->error, ['invalid_grant', 'invalid_client', 'unauthorized_client'], true)) {
                throw $e;
            }
            // Another request may have refreshed (and rotated) the grant meanwhile.
            $again = $this->stored();
            if ($again !== null && $again->accessToken !== $stored->accessToken) {
                return $again->accessToken;
            }
            $this->forget();

            throw $this->notConnected();
        }
        $updated = $stored->withTokens(
            $tokens->accessToken,
            $tokens->refreshToken ?? $stored->refreshToken,
            $tokens->expiresIn === null ? null : $now + $tokens->expiresIn,
            $tokens->scope ?? $stored->scope,
        );
        if (!$store->saveRefreshed($updated, $claimId)) {
            $current = $this->stored();
            if ($current === null) {
                // The user disconnected while the token request ran: the new tokens are not kept, and not left live.
                $this->registry->tokens($this->config)->revoke(
                    $updated,
                    $this->config->revocationUrl !== null && $updated->revocationUrl === $this->config->revocationUrl,
                );

                throw $this->notConnected();
            }
            if ($current->accessToken !== $stored->accessToken) {
                // The user signed in again meanwhile, which ended this refresh: that grant is the one to use.
                return $current->accessToken;
            }
            // The claim outlived its lease and another request took it over, but nothing newer was stored: keep
            // these tokens, or a refresh token the server rotated is lost and the user is disconnected later. The
            // other request then finds its claim gone, or its refresh refused, and uses what is stored here.
            $store->save($updated);
        }

        return $updated->accessToken;
    }

    /**
     * @param string $token The access token to send.
     * @return \CakeDC\Mcp\Client\ClientSession A connection that sends it.
     */
    private function session(string $token): ClientSession
    {
        if ($this->session === null || $this->sessionToken !== $token) {
            $this->close();
            $this->session = new ClientSession(
                $this->config,
                $this->registry->http(),
                ['Authorization' => 'Bearer ' . $token],
                ListCache::for($this->config, $this->userId),
            );
            $this->sessionToken = $token;
        }

        return $this->session;
    }

    /**
     * Deletes the grant without revoking it (the server already refused it).
     *
     * @return void
     */
    private function forget(): void
    {
        $this->close();
        $this->registry->store()->delete($this->config->name, $this->userId);
    }

    /**
     * @return \CakeDC\Mcp\Client\Exception\NotConnectedException
     */
    private function notConnected(): NotConnectedException
    {
        return new NotConnectedException(sprintf(
            'The user is not connected to "%s": send them to McpClients::get(\'%s\')->for($user)->connectUrl().',
            $this->config->name,
            $this->config->name,
        ));
    }
}
