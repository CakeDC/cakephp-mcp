<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Catalog;

use CakeDC\Mcp\Authorization\ToolGate;
use CakeDC\Mcp\Schema\SchemaNormalizer;
use Fiber;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Event\ErrorEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Suspension\RequestSuspension;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use WeakMap;

/**
 * The tool catalog: tools left out of `tools/list` that a client finds with `find_tools` and runs with `call_tools`.
 *
 * Every inner call goes through the gate (a denied tool answers like an unknown one) and through the SDK's own
 * `tools/call` handler, so its arguments are validated and its result built as for a direct call.
 */
final class ToolCatalog
{
    public const FIND = 'find_tools';
    public const CALL = 'call_tools';

    /**
     * The longest `query` find_tools takes, in characters.
     */
    public const MAX_QUERY_LENGTH = 200;

    /**
     * The default of `Mcp.catalog.maxResultBytes`: the most JSON bytes of tools one find_tools result holds (past
     * it, `hasMore` says there are more), and of results one call_tools answer holds.
     */
    public const MAX_RESULT_BYTES = 65536;

    /**
     * @param \Mcp\Capability\RegistryInterface $registry The server's registry.
     * @param \CakeDC\Mcp\Authorization\ToolGate $gate The server's gate.
     * @param list<string> $patterns `Mcp.catalog.tools`.
     * @param int $maxCalls `Mcp.catalog.maxCalls`.
     * @param \Mcp\Capability\Registry\ReferenceHandlerInterface $references Runs the tools, with the plugin container.
     * @param \Psr\Log\LoggerInterface $logger The server's logger.
     * @param \Mcp\Server\Transport\Http\OAuth\ScopePolicy|null $policy The scope policy, null when tokens are not
     *   checked (host mode).
     * @param \Psr\EventDispatcher\EventDispatcherInterface|null $events The server's events: each inner call is
     *   announced like a direct one, so the audit records it.
     * @param bool $normalize `Mcp.schema.normalize`: the schemas find_tools returns follow it, as tools/list does.
     * @param int $maxResultBytes `Mcp.catalog.maxResultBytes`.
     */
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly ToolGate $gate,
        private readonly array $patterns,
        private readonly int $maxCalls,
        private readonly ReferenceHandlerInterface $references,
        private readonly LoggerInterface $logger,
        private readonly ?ScopePolicy $policy = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly bool $normalize = false,
        private readonly int $maxResultBytes = self::MAX_RESULT_BYTES,
    ) {
        $this->made = new WeakMap();
    }

    /**
     * The requests this catalog made for the tools it ran: the audit marks them `via=call_tools`. Kept out of
     * band, so a client cannot claim it through the request's `_meta`.
     *
     * @var \WeakMap<\Mcp\Schema\JsonRpc\Request, true>
     */
    private WeakMap $made;

    /**
     * Whether $request is one this catalog made for a tool it ran.
     *
     * @param \Mcp\Schema\JsonRpc\Request $request A request.
     * @return bool
     */
    public function made(Request $request): bool
    {
        return isset($this->made[$request]);
    }

    /**
     * Whether $tool is in the catalog of this server.
     *
     * @param string $tool A tool name.
     * @return bool
     */
    public function has(string $tool): bool
    {
        return $this->registry->hasTool($tool)
            && CatalogPatterns::catalogues($this->patterns, $tool, $this->registry->getTool($tool)->tool->meta);
    }

    /**
     * Finds catalogued tools the caller may call, best matches first, as many as $limit and maxResultBytes allow.
     *
     * @param \Mcp\Server\RequestContext $context The call (injected by the SDK).
     * @param string $query Words to look for in the tool names, titles, descriptions and the names and descriptions
     *   of their arguments. Empty: every tool.
     * @param int $limit How many tools, 1 to 50.
     * @return array{tools: list<array<string, mixed>>, hasMore: bool}
     * @throws \Mcp\Exception\ToolCallException With a query longer than MAX_QUERY_LENGTH.
     */
    public function find(RequestContext $context, string $query = '', int $limit = 10): array
    {
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new ToolCallException(sprintf(
                'The query is too long: at most %d characters. Use a few words.',
                self::MAX_QUERY_LENGTH,
            ));
        }
        // Words of any script, so a query in another language matches whole words, not their ASCII letters.
        $terms = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ranked = [];
        foreach ($this->registry->getTools()->references as $tool) {
            $name = $tool->name;
            if (!$this->callable($name, $context)) {
                continue;
            }
            $score = $terms === [] ? 1 : $this->score($tool, $terms);
            if ($score > 0) {
                $ranked[] = [$score, $tool];
            }
        }
        usort($ranked, fn(array $a, array $b): int => [$b[0], $a[1]->name] <=> [$a[0], $b[1]->name]);
        $limit = max(1, min(50, $limit));
        $tools = [];
        $bytes = 2;
        foreach ($ranked as [, $tool]) {
            if (count($tools) >= $limit) {
                break;
            }
            $entry = [
                'name' => $tool->name,
                'title' => $tool->title,
                'description' => $tool->description,
                // A host asks before calling a tool it does not know to be read-only: tell the model what it is.
                'annotations' => $tool->annotations?->jsonSerialize(),
                'inputSchema' => $this->normalize
                    ? SchemaNormalizer::normalize($tool->inputSchema)
                    : $tool->inputSchema,
            ];
            $size = strlen((string)json_encode($entry)) + 1;
            if ($bytes + $size > $this->maxResultBytes && $tools !== []) {
                break;
            }
            $bytes += $size;
            $tools[] = $entry;
        }

        return ['tools' => $tools, 'hasMore' => count($tools) < count($ranked)];
    }

    /**
     * Runs catalogued tools in order, stopping at the first error.
     *
     * @param \Mcp\Server\RequestContext $context The call (injected by the SDK).
     * @param array<mixed> $calls The tools to run: `{name, arguments}` objects from the client.
     * @return \Mcp\Schema\Result\CallToolResult
     * @throws \Mcp\Exception\ToolCallException With more calls than `Mcp.catalog.maxCalls`.
     */
    public function call(RequestContext $context, array $calls): CallToolResult
    {
        if (count($calls) > $this->maxCalls) {
            throw new ToolCallException(sprintf('At most %d calls at a time.', $this->maxCalls));
        }
        $handler = new CallToolHandler($this->registry, $this->references, $this->logger);
        $parent = $context->getRequest();
        $results = [];
        $failed = false;
        $full = null;
        $bytes = 2;
        foreach (array_values($calls) as $index => $call) {
            $name = is_array($call) && is_string($call['name'] ?? null) ? $call['name'] : '';
            if ($full !== null) {
                // The answer is full: what comes after is not run, and says so.
                $results[] = $this->notRun($name, $full);
                continue;
            }
            $arguments = is_array($call) ? $call['arguments'] ?? [] : null;
            $result = is_array($arguments)
                ? $this->runOne($handler, $context, $parent->getId() . '.' . $index, $name, $arguments)
                : $this->error($name, sprintf('The arguments of %s must be an object.', $name));
            $size = strlen((string)json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) + 1;
            if ($bytes + $size > $this->maxResultBytes) {
                // The tool ran, so its result is never dropped silently: the model must not repeat its effects.
                $result = $this->leftOut($name, $size, (bool)$result['isError']);
                $full = $name;
                $size = strlen((string)json_encode($result)) + 1;
            }
            $bytes += $size;
            $results[] = $result;
            if ($result['isError']) {
                $failed = true;
                break;
            }
        }
        $data = ['results' => $results];

        // The summary comes first: it is what the audit records for this call, never the output of the tools.
        return new CallToolResult([
            new TextContent(
                $full !== null && !$failed ? $this->fullSummary($results) : $this->summary($results, $failed),
            ),
            new TextContent((string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ], $failed, $data);
    }

    /**
     * The JSON schemas of the two tools.
     *
     * @return array<string, array<string, mixed>>
     */
    public function inputSchemas(): array
    {
        return [
            self::FIND => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'maxLength' => self::MAX_QUERY_LENGTH,
                        'description' => 'Words to look for in tool names, descriptions and arguments. '
                            . 'Leave it out for all tools.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'description' => 'How many tools to return, 1 to 50. Default 10.',
                    ],
                ],
            ],
            self::CALL => [
                'type' => 'object',
                'properties' => [
                    'calls' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => $this->maxCalls,
                        'description' => 'The tools to run, in order, with the names and arguments find_tools gave. '
                            . 'The run stops at the first error.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'A tool name from find_tools.'],
                                // No type: the SDK decodes an empty JSON object as an empty PHP array, which a
                                // `type: object` check refuses. The inner tool validates its own arguments.
                                'arguments' => [
                                    'description' => 'An object with the arguments, as the tool\'s input schema '
                                        . 'describes them. {} for none.',
                                ],
                            ],
                            'required' => ['name'],
                        ],
                    ],
                ],
                'required' => ['calls'],
            ],
        ];
    }

    /**
     * Whether the caller may call $tool through the catalog.
     *
     * @param string $tool A tool name.
     * @param \Mcp\Server\RequestContext $context The call, for its access token.
     * @return bool
     */
    private function callable(string $tool, RequestContext $context): bool
    {
        if (!$this->has($tool) || !$this->gate->allows($tool)) {
            return false;
        }
        $token = $context->getAccessToken();
        if ($this->policy === null || $token === null) {
            return true;
        }
        // The HTTP step already refused a call_tools whose token lacks these; this check stands behind it, and
        // keeps find_tools from offering tools the token cannot call.
        $required = $this->policy->requiredFor(['method' => 'tools/call', 'params' => ['name' => $tool]]);

        return array_diff($required, $this->policy->expand($token->getScopes())) === [];
    }

    /**
     * @param \Mcp\Server\Handler\Request\CallToolHandler $handler The SDK handler.
     * @param \Mcp\Server\RequestContext $context The outer call.
     * @param string $id The inner request id.
     * @param string $name The tool.
     * @param array<mixed> $arguments Its arguments.
     * @return array{name: string, isError: bool, content: array<mixed>, structuredContent?: mixed}
     */
    private function runOne(
        CallToolHandler $handler,
        RequestContext $context,
        string $id,
        string $name,
        array $arguments,
    ): array {
        // Without the outer progress token: progress must increase, and each tool would start again from zero.
        $meta = $context->getRequest()->getMeta() ?? [];
        unset($meta['progressToken']);
        $request = (new CallToolRequest($name, $arguments))->withId($id)->withMeta($meta === [] ? null : $meta);
        $this->made[$request] = true;
        $session = $context->getSession();
        $this->events?->dispatch(new RequestEvent($request, $session));
        // What is thrown here ends this call only, so the results of the calls before it are kept: a whole
        // call_tools error (-32021 for a missing capability) would make the client send the batch again.
        $thrown = null;
        try {
            if (!$this->callable($name, $context)) {
                // Like a direct call: a denied tool answers as an unknown one.
                $response = Error::forInvalidParams(sprintf('Tool not found: "%s".', $name), $id);
            } else {
                $response = $this->handle($handler, $request, $session, $context->getProtocolVersion()->isModern());
            }
        } catch (MissingRequiredClientCapabilityException $e) {
            $thrown = $e;
            $response = Error::forInternalError(sprintf(
                '%s needs a capability the client did not declare: call it on its own, not through call_tools.',
                $name,
            ), $id);
        } catch (Throwable $e) {
            $thrown = $e;
            $this->logger->error('Unhandled error during tool execution', ['name' => $name, 'exception' => $e]);
            // The message is left out, as for a direct call: it may hold what the caller must not see.
            $response = Error::forInternalError('Error while executing tool', $id);
        }
        if ($response instanceof Error) {
            $this->events?->dispatch(new ErrorEvent($response, $request, $session, $thrown));

            return $this->error($name, $response->message);
        }
        if ($response === null || $response->result instanceof InputRequiredResult) {
            // The client would answer by sending the whole batch again, and the tools before this one would run
            // a second time: this call ends here instead, with what ran before it kept.
            $response = new Response($id, new CallToolResult([new TextContent(sprintf(
                '%s needs input from the user: call it on its own, not through call_tools.',
                $name,
            ))], true));
        }
        $this->events?->dispatch(new ResponseEvent($response, $request, $session));
        $result = $response->result;
        if (!$result instanceof CallToolResult) {
            return $this->error($name, 'The tool returned no result.');
        }
        $entry = [
            'name' => $name,
            'isError' => $result->isError,
            'content' => array_map(
                fn(mixed $item): mixed => json_decode((string)json_encode($item), true),
                $result->content,
            ),
        ];
        if ($result->structuredContent !== null) {
            $entry['structuredContent'] = $result->structuredContent;
        }

        return $entry;
    }

    /**
     * Runs one inner call. In the 2026-07-28 revision it runs in a fiber of its own, so a tool that asks the
     * client (an elicitation through ClientGateway) is stopped there instead of turning the whole `call_tools`
     * into an `input_required` answer. Notifications (progress, logs) go on to the client as before.
     *
     * @param \Mcp\Server\Handler\Request\CallToolHandler $handler The SDK handler.
     * @param \Mcp\Schema\Request\CallToolRequest $request The inner request.
     * @param \Mcp\Server\Session\SessionInterface $session The session.
     * @param bool $modern Whether the call is in the 2026-07-28 revision.
     * @return \Mcp\Schema\JsonRpc\Response<\Mcp\Schema\Result\CallToolResult|\Mcp\Schema\Result\InputRequiredResult>|\Mcp\Schema\JsonRpc\Error|null
     *   Null when the tool asked the client.
     */
    private function handle(
        CallToolHandler $handler,
        CallToolRequest $request,
        SessionInterface $session,
        bool $modern,
    ): Response|Error|null {
        if (!$modern || Fiber::getCurrent() === null) {
            return $handler->handle($request, $session);
        }
        $fiber = new Fiber(fn(): Response|Error => $handler->handle($request, $session));
        $suspended = $fiber->start();
        while (!$fiber->isTerminated()) {
            if ($suspended instanceof RequestSuspension) {
                // Left suspended: the fiber is dropped, which unwinds the tool.
                return null;
            }
            $suspended = $fiber->resume(Fiber::suspend($suspended));
        }

        return $fiber->getReturn();
    }

    /**
     * @param list<array<string, mixed>> $results The results so far.
     * @param bool $failed Whether the last one failed.
     * @return string `3 calls ok: a, b, c`, or `b failed: <its message>` after the ones that ran.
     */
    private function summary(array $results, bool $failed): string
    {
        if (!$failed) {
            return sprintf('%d calls ok: %s', count($results), implode(', ', array_column($results, 'name')));
        }
        $last = end($results);
        if ($last === false) {
            return 'no calls';
        }
        $content = is_array($last['content'] ?? null) ? $last['content'] : [];
        $text = is_array($content[0] ?? null) ? ($content[0]['text'] ?? '') : '';

        return sprintf('%s failed: %s', (string)$last['name'], is_string($text) ? $text : '');
    }

    /**
     * @param string $name A tool that ran.
     * @param int $size The bytes of its result.
     * @param bool $isError Whether that result was an error.
     * @return array{name: string, isError: bool, outputLeftOut: true, content: list<array{type: string, text: string}>}
     */
    private function leftOut(string $name, int $size, bool $isError): array
    {
        return ['name' => $name, 'isError' => $isError, 'outputLeftOut' => true, 'content' => [[
            'type' => 'text',
            'text' => sprintf(
                '%s ran, but its output (%d bytes) was left out: one call_tools answer holds at most %d bytes. '
                . 'Do not run it again for its effects; call %s on its own to get its output.',
                $name,
                $size,
                $this->maxResultBytes,
                $name,
            ),
        ]]];
    }

    /**
     * @param string $name A tool after the one that filled the answer.
     * @param string $full The tool whose output filled it.
     * @return array{name: string, isError: false, notRun: true, content: list<array{type: string, text: string}>}
     */
    private function notRun(string $name, string $full): array
    {
        return ['name' => $name, 'isError' => false, 'notRun' => true, 'content' => [[
            'type' => 'text',
            'text' => sprintf('%s was not run: the answer was full after %s. Call it again.', $name, $full),
        ]]];
    }

    /**
     * @param list<array<string, mixed>> $results The results of an answer that filled up.
     * @return string `3 calls: a, b ran (output left out for its size); c not run`.
     */
    private function fullSummary(array $results): string
    {
        $parts = [];
        $skipped = [];
        foreach ($results as $result) {
            $name = (string)$result['name'];
            if (isset($result['notRun'])) {
                $skipped[] = $name;
            } elseif (isset($result['outputLeftOut'])) {
                $parts[] = $name . ' ran (output left out for its size)';
            } else {
                $parts[] = $name;
            }
        }

        return sprintf('%d calls: %s', count($results), implode(', ', $parts))
            . ($skipped === [] ? '' : '; ' . implode(', ', $skipped) . ' not run');
    }

    /**
     * @param string $name The tool.
     * @param string $message The error.
     * @return array{name: string, isError: true, content: list<array{type: string, text: string}>}
     */
    private function error(string $name, string $message): array
    {
        return ['name' => $name, 'isError' => true, 'content' => [['type' => 'text', 'text' => $message]]];
    }

    /**
     * @param \Mcp\Schema\Tool $tool A tool.
     * @param list<string> $terms Lowercase query words.
     * @return int Name matches count 6, title 4, description 2, the name or description of an argument 2, and
     *   the rest of the input schema (allowed values, nested arguments) 1.
     */
    private function score(Tool $tool, array $terms): int
    {
        $arguments = [];
        $properties = $tool->inputSchema['properties'] ?? [];
        foreach (is_array($properties) ? $properties : [] as $property => $schema) {
            $arguments[] = (string)$property;
            if (is_array($schema) && is_string($schema['description'] ?? null)) {
                $arguments[] = $schema['description'];
            }
        }
        $rest = [];
        foreach (is_array($properties) ? $properties : [] as $schema) {
            if (is_array($schema)) {
                self::schemaWords($schema, $rest);
            }
        }
        // Each searched field with the points a query word found in it is worth.
        $fields = [
            [$tool->name, 6],
            [(string)$tool->title, 4],
            [(string)$tool->description, 2],
            [implode(' ', $arguments), 2],
            [implode(' ', $rest), 1],
        ];

        return array_sum(array_map(
            fn(array $field): int => (int)$field[1] * count(array_filter(
                $terms,
                fn(string $term): bool => str_contains(mb_strtolower($field[0]), $term),
            )),
            $fields,
        ));
    }

    /**
     * Collects the words of an argument's schema below its own name and description: its allowed values (`enum`,
     * `const`), and the names and descriptions of nested properties and list items, at every depth.
     *
     * @param array<mixed> $schema An argument's schema.
     * @param list<string> $words Where the words go.
     * @param int $depth How deep this is; deep or cyclic schemas stop at 10.
     * @return void
     */
    private static function schemaWords(array $schema, array &$words, int $depth = 0): void
    {
        if ($depth > 10) {
            return;
        }
        foreach (['enum', 'examples'] as $key) {
            foreach (is_array($schema[$key] ?? null) ? $schema[$key] : [] as $value) {
                if (is_scalar($value)) {
                    $words[] = (string)$value;
                }
            }
        }
        if (is_scalar($schema['const'] ?? null)) {
            $words[] = (string)$schema['const'];
        }
        $nested = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($nested as $property => $child) {
            $words[] = (string)$property;
            if (is_array($child)) {
                if (is_string($child['description'] ?? null)) {
                    $words[] = $child['description'];
                }
                self::schemaWords($child, $words, $depth + 1);
            }
        }
        foreach (['items', 'additionalProperties'] as $key) {
            if (is_array($schema[$key] ?? null)) {
                self::schemaWords($schema[$key], $words, $depth + 1);
            }
        }
        foreach (['anyOf', 'oneOf', 'allOf'] as $key) {
            foreach (is_array($schema[$key] ?? null) ? $schema[$key] : [] as $child) {
                if (is_array($child)) {
                    self::schemaWords($child, $words, $depth + 1);
                }
            }
        }
    }
}
