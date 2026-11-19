<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Middleware;

use CakeDC\Mcp\Audit\AuditRecord;
use CakeDC\Mcp\Audit\AuditSettings;
use CakeDC\Mcp\Audit\AuditWriter;
use CakeDC\Mcp\Identity\CurrentIdentity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records the audited calls that authentication refuses before the MCP server
 * runs: a `401`, or a `403` for a missing scope. Goes right before the
 * authentication middleware and never changes the answer. The token and the
 * session are not part of the record (the session header is not trusted
 * before authentication).
 */
final class AuditRefusalMiddleware implements MiddlewareInterface
{
    /**
     * @param \CakeDC\Mcp\Audit\AuditSettings $settings The audit settings.
     * @param \CakeDC\Mcp\Audit\AuditWriter $writer Writes the records.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity The token owner, set by authentication.
     */
    public function __construct(
        private readonly AuditSettings $settings,
        private readonly AuditWriter $writer,
        private readonly CurrentIdentity $identity,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $status = $response->getStatusCode();
        $outcome = match (true) {
            $status === 401 => AuditRecord::UNAUTHORIZED,
            $status === 403 && str_contains($response->getHeaderLine('WWW-Authenticate'), 'error="insufficient_scope"')
                => AuditRecord::INSUFFICIENT_SCOPE,
            default => null,
        };
        if ($outcome === null) {
            return $response;
        }
        $message = $this->message($request);
        if ($message === null) {
            return $response;
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $name = $params['name'] ?? $params['uri'] ?? null;
        $id = $message['id'] ?? null;

        $this->writer->write(new AuditRecord(
            $message['method'],
            is_string($name) ? (string)AuditRecord::cap($name) : null,
            $outcome,
            $outcome === AuditRecord::INSUFFICIENT_SCOPE ? $this->identity->getIdentifier() : null,
            null,
            null,
            is_string($id) || is_int($id) ? AuditRecord::cap($id) : null,
            $message['method'] === 'resources/read' ? [] : $this->writer->arguments($params['arguments'] ?? null),
            $outcome === AuditRecord::UNAUTHORIZED ? 'Unauthorized' : 'insufficient_scope',
            null,
        ));

        return $response;
    }

    /**
     * The JSON-RPC request in the body, when it is one request of an audited method.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request The HTTP request.
     * @return array{method: string, id?: mixed, params?: mixed}|null
     */
    private function message(ServerRequestInterface $request): ?array
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $message = json_decode($body->getContents(), true);
        if (
            !is_array($message)
            || array_is_list($message)
            || !is_string($message['method'] ?? null)
            || !$this->settings->audits($message['method'])
        ) {
            return null;
        }

        /** @var array{method: string, id?: mixed, params?: mixed} $message */
        return $message;
    }
}
