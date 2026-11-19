<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Event;

use Cake\Log\Log;
use CakeDC\Mcp\Audit\AuditRecord;
use CakeDC\Mcp\Audit\AuditSettings;
use CakeDC\Mcp\Audit\AuditWriter;
use CakeDC\Mcp\Authorization\ToolGate;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Log\McpLogger;
use Closure;
use Mcp\Event\ErrorEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Session\SessionInterface;
use Throwable;

/**
 * Records the audited MCP calls (`Mcp.audit.methods`) from the SDK's request,
 * response and error events. A denied tool and an unknown tool get the same
 * answer from the server; the record tells them apart by asking the same gate.
 */
final class AuditListener
{
    /**
     * Longest error text kept in a record, in characters.
     */
    private const MAX_ERROR_LENGTH = 200;

    /**
     * Start times of the requests in progress, keyed by session and request id.
     *
     * @var array<string, float>
     */
    private array $started = [];

    private Closure $clock;

    private ?Closure $toolExists;

    /**
     * @param \CakeDC\Mcp\Audit\AuditSettings $settings The audit settings.
     * @param \CakeDC\Mcp\Audit\AuditWriter $writer Writes the records.
     * @param \CakeDC\Mcp\Authorization\ToolGate $gate The gate of the tool call guard.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity The caller.
     * @param \Closure|null $clock `fn(): float`, seconds; `microtime(true)` by default.
     * @param \Closure|null $toolExists `fn(string $name): ?bool`, whether the tool exists (null: unknown). Without
     *   it, every tool the gate refuses is recorded as denied.
     */
    public function __construct(
        private readonly AuditSettings $settings,
        private readonly AuditWriter $writer,
        private readonly ToolGate $gate,
        private readonly CurrentIdentity $identity,
        ?Closure $clock = null,
        ?Closure $toolExists = null,
    ) {
        $this->clock = $clock ?? fn(): float => microtime(true);
        $this->toolExists = $toolExists;
    }

    /**
     * The OAuth client of an access token: `client_id`, `azp`, or a single `aud`.
     *
     * @param \Mcp\Server\Authorization\AccessToken|null $token The token.
     * @return string|null
     */
    public static function clientOf(?AccessToken $token): ?string
    {
        if ($token === null) {
            return null;
        }
        $client = $token->getClientId();
        if ($client !== null) {
            return $client;
        }
        $audience = $token->getClaim('aud');
        if (is_array($audience) && count($audience) === 1) {
            $audience = reset($audience);
        }

        return is_string($audience) && $audience !== '' ? $audience : null;
    }

    /**
     * @param \Mcp\Event\RequestEvent $event The event.
     * @return void
     */
    public function onRequest(RequestEvent $event): void
    {
        $this->safely(fn() => $this->started($event));
    }

    /**
     * @param \Mcp\Event\ResponseEvent $event The event.
     * @return void
     */
    public function onResponse(ResponseEvent $event): void
    {
        $this->safely(fn() => $this->responded($event));
    }

    /**
     * @param \Mcp\Event\ErrorEvent $event The event.
     * @return void
     */
    public function onError(ErrorEvent $event): void
    {
        $this->safely(fn() => $this->failed($event));
    }

    /**
     * Runs $work, and logs under `mcp` what it throws: the answer to the client must not depend on the
     * record (the stateless era dispatches these events outside any try block).
     *
     * @param \Closure $work The work.
     * @return void
     */
    private function safely(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            try {
                Log::error(sprintf('MCP audit: could not record a call: %s', $exception->getMessage()), [
                    'scope' => [McpLogger::SCOPE],
                ]);
            } catch (Throwable) {
                // Nothing left to tell: the call goes on.
            }
        }
    }

    /**
     * @param \Mcp\Event\RequestEvent $event The event.
     * @return void
     */
    private function started(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($this->settings->audits($request::getMethod())) {
            $this->started[$this->key($request, $event->getSession())] = ($this->clock)();
        }
    }

    /**
     * @param \Mcp\Event\ResponseEvent $event The event.
     * @return void
     */
    private function responded(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $result = $event->getResponse()->result;
        // A tool waiting for the user's input has not finished: the round that finishes it is recorded.
        if (!$this->settings->audits($request::getMethod()) || $result instanceof InputRequiredResult) {
            return;
        }
        $failed = $result instanceof CallToolResult && $result->isError;

        $this->record(
            $request,
            $event->getSession(),
            $failed ? AuditRecord::TOOL_ERROR : AuditRecord::OK,
            $failed ? $this->firstText($result) : null,
        );
    }

    /**
     * @param \Mcp\Event\ErrorEvent $event The event.
     * @return void
     */
    private function failed(ErrorEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->settings->audits($request::getMethod())) {
            return;
        }
        $error = $event->getError();
        $outcome = match (true) {
            $request instanceof CallToolRequest && $this->isDenied($request->name) => AuditRecord::DENIED,
            $error->code === Error::INVALID_PARAMS => AuditRecord::INVALID,
            default => AuditRecord::ERROR,
        };

        $this->record($request, $event->getSession(), $outcome, $error->message);
    }

    /**
     * @param \Mcp\Schema\JsonRpc\Request $request The audited request.
     * @param \Mcp\Server\Session\SessionInterface $session Its session.
     * @param string $outcome The outcome.
     * @param string|null $error The error text.
     * @return void
     */
    private function record(Request $request, SessionInterface $session, string $outcome, ?string $error): void
    {
        $key = $this->key($request, $session);
        $start = $this->started[$key] ?? null;
        unset($this->started[$key]);
        $token = $session->get(AccessToken::class);

        [$name, $arguments] = match (true) {
            $request instanceof CallToolRequest => [$request->name, $request->arguments],
            $request instanceof GetPromptRequest => [$request->name, $request->arguments],
            $request instanceof ReadResourceRequest => [$request->uri, []],
            default => [null, []],
        };

        $this->writer->write(new AuditRecord(
            $request::getMethod(),
            $name === null ? null : (string)AuditRecord::cap($name),
            $outcome,
            $this->identity->getIdentifier(),
            self::clientOf($token instanceof AccessToken ? $token : null),
            $session->getId()->toRfc4122(),
            AuditRecord::cap($request->getId()),
            $this->writer->arguments($arguments),
            $error === null ? null : mb_substr($error, 0, self::MAX_ERROR_LENGTH),
            $start === null ? null : (int)round((($this->clock)() - $start) * 1000),
        ));
    }

    /**
     * Whether the gate refused an existing tool (an unknown tool is not a denial).
     *
     * @param string $name The tool.
     * @return bool
     */
    private function isDenied(string $name): bool
    {
        if ($this->gate->allows($name)) {
            return false;
        }

        return ($this->toolExists === null ? null : ($this->toolExists)($name)) !== false;
    }

    /**
     * @param \Mcp\Schema\Result\CallToolResult $result A tool error.
     * @return string|null Its first text.
     */
    private function firstText(CallToolResult $result): ?string
    {
        foreach ($result->content as $content) {
            if ($content instanceof TextContent && is_string($content->text)) {
                return $content->text;
            }
        }

        return null;
    }

    /**
     * @param \Mcp\Schema\JsonRpc\Request $request A request.
     * @param \Mcp\Server\Session\SessionInterface $session Its session.
     * @return string
     */
    private function key(Request $request, SessionInterface $session): string
    {
        return $session->getId()->toRfc4122() . '|' . $request->getId();
    }
}
