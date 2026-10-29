<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;
use Cake\Log\Log;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\OAuth\AuthSettings;
use CakeDC\Mcp\OAuth\ResourceServer;
use Throwable;

/**
 * The protected resource metadata of the MCP endpoint (RFC 9728), at
 * `/.well-known/oauth-protected-resource` followed by the MCP route. MCP clients read it to find the
 * authorization server. Public: no identity, any origin, no credentials.
 */
class ProtectedResourceController extends Controller
{
    use SkipsHostAuthorizationTrait;

    private const CORS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Accept, MCP-Protocol-Version',
        'Access-Control-Max-Age' => '86400',
    ];

    /**
     * @return \Cake\Http\Response
     */
    public function metadata(): Response
    {
        $this->skipHostAuthorization();
        $response = $this->response;
        foreach (self::CORS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if ($this->request->is('options')) {
            return $response->withStatus(204);
        }

        try {
            $settings = AuthSettings::fromConfig();
            if ($settings->mode === AuthSettings::HOST) {
                return $response->withStatus(404)
                    ->withType('application/json')
                    ->withStringBody((string)json_encode(['error' => 'not_found']));
            }
            $body = json_encode(
                (new ResourceServer($settings))->metadata(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (Throwable $exception) {
            Log::error(
                sprintf("MCP protected resource metadata failed.\n%s", $exception),
                ['scope' => [McpLogger::SCOPE]],
            );

            return $response->withStatus(500)
                ->withType('application/json')
                ->withStringBody((string)json_encode(['error' => 'server_error']));
        }

        return $response->withType('application/json')
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withStringBody($body);
    }
}
