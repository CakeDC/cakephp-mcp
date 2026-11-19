<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;
use Cake\Log\Log;
use CakeDC\Mcp\Audit\AuditSettings;
use CakeDC\Mcp\Audit\AuditWriter;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Log\McpLogger;
use CakeDC\Mcp\McpConfig;
use CakeDC\Mcp\Middleware\AuditRefusalMiddleware;
use CakeDC\Mcp\Middleware\BodyLimitMiddleware;
use CakeDC\Mcp\Middleware\IdentityMiddleware;
use CakeDC\Mcp\Server\ServerFactory;
use Closure;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Throwable;

/**
 * The MCP HTTP endpoint (`Mcp.route`, `/mcp` by default). Hands the request to
 * the SDK's Streamable HTTP transport and returns its response. In host mode the identity comes from the
 * host's authentication; in `oauth` and `external` modes from the access token (the host's identity
 * attribute is ignored).
 */
class McpController extends Controller
{
    use SkipsHostAuthorizationTrait;

    /**
     * @param \CakeDC\Mcp\Server\ServerFactory $factory Builds the server.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity Shared with the tools.
     * @return \Cake\Http\Response
     */
    public function handle(ServerFactory $factory, CurrentIdentity $identity): Response
    {
        $this->skipHostAuthorization();
        // A long-running worker reuses the container: no request starts with the previous one's identity.
        $identity->set(null);
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();

        try {
            $maxBodyBytes = McpConfig::maxBodyBytes();
            $origins = array_values(array_filter((array)McpConfig::get('http.allowedOrigins'), 'is_string'));
            $middleware = [
                new CorsMiddleware($origins),
                new DnsRebindingProtectionMiddleware(
                    McpConfig::allowedHosts(),
                    $origins,
                    $responseFactory,
                    $streamFactory,
                ),
                // Before authentication: an oversized body gets a 413 that names the limit, never a
                // Bearer challenge.
                new BodyLimitMiddleware($maxBodyBytes, $responseFactory, $streamFactory),
            ];
            $audit = AuditSettings::fromConfig();
            if ($audit->enabled) {
                // Right before authentication, to record the calls it refuses.
                $middleware[] = new AuditRefusalMiddleware($audit, new AuditWriter($audit), $identity);
            }
            $middleware[] = $this->authentication($identity, $responseFactory, $streamFactory);
            $transport = new StreamableHttpTransport(
                $this->request,
                $responseFactory,
                $streamFactory,
                new McpLogger(),
                $middleware,
                $maxBodyBytes,
            );
            $response = $factory->create()->run($transport);
        } catch (Throwable $exception) {
            Log::error(sprintf("MCP request failed.\n%s", $exception), ['scope' => [McpLogger::SCOPE]]);

            return $this->internalError();
        }

        return $this->toCakeResponse($response);
    }

    /**
     * The middleware that identifies the caller from the host's identity. An `auth.mode` other than
     * `host` throws, so the endpoint fails closed.
     *
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity Shared with the tools.
     * @param \Psr\Http\Message\ResponseFactoryInterface $responseFactory For its responses.
     * @param \Psr\Http\Message\StreamFactoryInterface $streamFactory For their bodies.
     * @return \Psr\Http\Server\MiddlewareInterface
     */
    private function authentication(
        CurrentIdentity $identity,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
    ): MiddlewareInterface {
        McpConfig::assertHostAuthMode();
        $scopes = McpConfig::get('identity.scopes');

        return new IdentityMiddleware(
            $identity,
            $responseFactory,
            $streamFactory,
            McpConfig::get('identity.required') !== false,
            (string)McpConfig::get('identity.attribute'),
            is_callable($scopes) ? Closure::fromCallable($scopes) : null,
        );
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
