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
    protected function run(callable $call): mixed
    {
        $session = $this->session($this->accessToken(false));
        try {
            return $call($session->client());
        } catch (ExceptionInterface $e) {
            if ($session->lastStatus() !== 401) {
                throw $e;
            }
        }
        // The server no longer accepts the token: refresh once and try again.
        $session = $this->session($this->accessToken(true));
        try {
            return $call($session->client());
        } catch (ExceptionInterface $e) {
            if ($session->lastStatus() !== 401) {
                throw $e;
            }
            $this->forget();

            throw $this->notConnected();
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
            if ($this->stored() === null) {
                // The user disconnected while the token request ran: the new tokens are not kept, and not left live.
                $this->registry->tokens($this->config)->revoke(
                    $updated,
                    $this->config->revocationUrl !== null && $updated->revocationUrl === $this->config->revocationUrl,
                );

                throw $this->notConnected();
            }
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
