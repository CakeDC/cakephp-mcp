<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;
use Cake\Log\Log;
use Cake\Routing\Router;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\Exception\ClientException;
use CakeDC\Mcp\Client\McpClients;
use CakeDC\Mcp\Client\OAuth\OAuthException;
use CakeDC\Mcp\Client\OAuth\PendingAuthorization;
use CakeDC\Mcp\Client\UserId;
use CakeDC\Mcp\Log\McpLogger;
use InvalidArgumentException;

/**
 * The browser side of a user mode client's sign-in, at `Mcp.client.route`:
 *
 * - `GET {route}/{client}/connect?redirect=/local/path`: the logged-in user starts connecting; the sign-in is kept
 *   in the session and the browser goes to the authorization server.
 * - `GET {route}/{client}/callback`: the authorization server sends the browser back; the same user must be logged
 *   in, the `state` must match the sign-in in the session (used once), and the grant is stored.
 *
 * Both answer with a redirect to the local path and a flash message; nothing the server said is shown.
 */
class McpClientController extends Controller
{
    use SkipsHostAuthorizationTrait;

    private const SESSION_KEY = 'McpClient.pending';

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Flash');
    }

    /**
     * @param string $client The client's name.
     * @return \Cake\Http\Response|null
     */
    public function connect(string $client): ?Response
    {
        $this->skipHostAuthorization();
        $config = $this->userModeClient($client);
        if ($config === null) {
            return $this->plain(404, 'Not Found');
        }
        $userId = $this->currentUserId();
        if ($userId === null) {
            return $this->plain(401, 'Log in to connect.');
        }
        $returnTo = self::localPath($this->request->getQuery('redirect'));
        $redirectUri = Router::url(
            ['plugin' => 'CakeDC/Mcp', 'controller' => 'McpClient', 'action' => 'callback', $config->name],
            true,
        );

        try {
            [$pending, $url] = McpClients::registry()->flow($config->name)->start($userId, $redirectUri, $returnTo);
        } catch (ClientException $e) {
            Log::warning(
                sprintf('MCP client "%s": the sign-in could not start: %s', $config->name, $e->getMessage()),
                ['scope' => [McpLogger::SCOPE]],
            );
            $this->Flash->error(sprintf('The connection to %s could not be started. Try again later.', $config->name));

            return $this->redirect($returnTo);
        }
        $this->request->getSession()->write(self::SESSION_KEY . '.' . $config->name, $pending->toArray());

        return $this->redirect($url);
    }

    /**
     * @param string $client The client's name.
     * @return \Cake\Http\Response|null
     */
    public function callback(string $client): ?Response
    {
        $this->skipHostAuthorization();
        $config = $this->userModeClient($client);
        if ($config === null) {
            return $this->plain(404, 'Not Found');
        }
        $session = $this->request->getSession();
        $key = self::SESSION_KEY . '.' . $config->name;
        // Used once, whatever happens next.
        $pending = PendingAuthorization::fromArray($session->consume($key));
        $failed = sprintf('The connection to %s could not be completed. Try again.', $config->name);
        if ($pending === null) {
            $this->Flash->error($failed);

            return $this->redirect('/');
        }
        $userId = $this->currentUserId();
        if ($userId === null || !hash_equals($pending->userId, $userId)) {
            $this->Flash->error($failed);

            return $this->redirect($pending->returnTo);
        }

        try {
            McpClients::registry()->flow($config->name)->complete($pending, $this->request->getQueryParams());
        } catch (OAuthException $e) {
            Log::warning(
                sprintf('MCP client "%s": the sign-in failed: %s', $config->name, $e->getMessage()),
                ['scope' => [McpLogger::SCOPE]],
            );
            $this->Flash->error($e->error === null
                ? $failed
                : sprintf('The connection to %s could not be completed: %s.', $config->name, $e->error));

            return $this->redirect($pending->returnTo);
        } catch (ClientException $e) {
            Log::warning(
                sprintf('MCP client "%s": the sign-in failed: %s', $config->name, $e->getMessage()),
                ['scope' => [McpLogger::SCOPE]],
            );
            $this->Flash->error($failed);

            return $this->redirect($pending->returnTo);
        }
        $this->Flash->success(sprintf('Connected to %s.', $config->name));

        return $this->redirect($pending->returnTo);
    }

    /**
     * @param string $client The client's name.
     * @return \CakeDC\Mcp\Client\ClientConfig|null The user mode client, or null when there is none of that name.
     */
    private function userModeClient(string $client): ?ClientConfig
    {
        try {
            $config = ClientConfig::fromConfig($client);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $config->isUser() ? $config : null;
    }

    /**
     * @return string|null The logged-in user's id, null when no one is logged in.
     */
    private function currentUserId(): ?string
    {
        $identity = $this->request->getAttribute((string)ClientConfig::setting('identityAttribute'));
        if ($identity === null) {
            return null;
        }
        try {
            return UserId::of($identity);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A plain answer, independent of the application's error pages.
     *
     * @param int $status The status.
     * @param string $text The text.
     * @return \Cake\Http\Response
     */
    private function plain(int $status, string $text): Response
    {
        return $this->response->withStatus($status)->withType('text/plain')->withStringBody($text);
    }

    /**
     * @param mixed $path The `redirect` query parameter.
     * @return string It when it is a path on this site, else `/`.
     */
    private static function localPath(mixed $path): string
    {
        if (
            !is_string($path)
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || strlen($path) > 2000
        ) {
            return '/';
        }

        return $path;
    }
}
