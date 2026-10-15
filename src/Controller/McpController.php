<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;
use Cake\Http\ResponseFactory;
use Cake\Http\StreamFactory;
use Cake\Log\Log;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\Middleware\IdentityMiddleware;
use CakeDC\Mcp\Server\ServerFactory;
use Closure;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The MCP HTTP endpoint (`Mcp.route`, `/mcp` by default). Hands the request to
 * the SDK's Streamable HTTP transport and returns its response.
 */
class McpController extends Controller
{
    /**
     * @param \CakeDC\Mcp\Server\ServerFactory $factory Builds the server.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity Shared with the tools.
     * @return \Cake\Http\Response
     */
    public function handle(ServerFactory $factory, CurrentIdentity $identity): Response
    {
        $this->skipHostAuthorization();
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();

        try {
            $origins = array_values(array_filter((array)McpConfig::get('http.allowedOrigins'), 'is_string'));
            $scopes = McpConfig::get('identity.scopes');
            $transport = new StreamableHttpTransport(
                $this->request,
                $responseFactory,
                $streamFactory,
                new McpLogger(),
                [
                    new CorsMiddleware($origins),
                    new DnsRebindingProtectionMiddleware(
                        McpConfig::allowedHosts(),
                        $origins,
                        $responseFactory,
                        $streamFactory,
                    ),
                    new IdentityMiddleware(
                        $identity,
                        $responseFactory,
                        $streamFactory,
                        McpConfig::get('identity.required') !== false,
                        (string)McpConfig::get('identity.attribute'),
                        is_callable($scopes) ? Closure::fromCallable($scopes) : null,
                    ),
                ],
            );
            $response = $factory->create()->run($transport);
        } catch (Throwable $exception) {
            Log::error(sprintf("MCP request failed.\n%s", $exception), ['scope' => [McpLogger::SCOPE]]);

            return $this->internalError();
        }

        return $this->toCakeResponse($response);
    }

    /**
     * cakephp/authorization (when the host uses it) would otherwise demand an
     * authorization check for this action. Per-tool authorization is ours.
     *
     * @return void
     */
    private function skipHostAuthorization(): void
    {
        $authorization = $this->request->getAttribute('authorization');
        if (is_object($authorization) && method_exists($authorization, 'skipAuthorization')) {
            $authorization->skipAuthorization();
        }
    }

    /**
     * @param mixed $response What the transport returned.
     * @return \Cake\Http\Response
     */
    private function toCakeResponse(mixed $response): Response
    {
        if (!$response instanceof ResponseInterface) {
            return $this->internalError();
        }
        if (!$response instanceof Response) {
            $converted = (new Response())
                ->withStatus($response->getStatusCode(), $response->getReasonPhrase())
                ->withBody($response->getBody());
            foreach ($response->getHeaders() as $name => $values) {
                $converted = $converted->withHeader($name, $values);
            }
            $response = $converted;
        }
        if (str_starts_with($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
            $response = $response->withHeader('X-Accel-Buffering', 'no');
        }

        return $response;
    }

    /**
     * Never Cake's HTML error page, never a stack trace, even in debug mode.
     *
     * @return \Cake\Http\Response
     */
    private function internalError(): Response
    {
        return $this->response
            ->withStatus(500)
            ->withType('application/json')
            ->withStringBody((string)json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32603, 'message' => 'Internal error'],
            ]));
    }
}
