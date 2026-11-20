<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Catalog;

use CakeDC\Mcp\App\AppTools;
use CakeDC\Mcp\Authorization\ToolGate;
use CakeDC\Mcp\Schema\SchemaNormalizer;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Event\ErrorEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\RequestContext;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
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
     * @param \Mcp\Capability\Registry $registry The server's registry.
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
     */
    public function __construct(
        private readonly Registry $registry,
        private readonly ToolGate $gate,
        private readonly array $patterns,
        private readonly int $maxCalls,
        private readonly ReferenceHandlerInterface $references,
        private readonly LoggerInterface $logger,
        private readonly ?ScopePolicy $policy = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly bool $normalize = false,
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
        // App-only tools are for the app's page, which calls them directly; the model never gets them from here.
        return CatalogPatterns::matches($this->patterns, $tool)
            && $this->registry->hasTool($tool)
            && !AppTools::appOnly($this->registry->getTool($tool)->tool);
    }

    /**
     * Finds catalogued tools the caller may call, best matches first.
     *
     * @param \Mcp\Server\RequestContext $context The call (injected by the SDK).
     * @param string $query Words to look for in the tool names, titles and descriptions. Empty: every tool.
     * @param int $limit How many tools, 1 to 50.
     * @return array{tools: list<array{name: string, title: string|null, description: string|null, inputSchema: array<string, mixed>}>}
     */
    public function find(RequestContext $context, string $query = '', int $limit = 10): array
    {
        $terms = preg_split('/[^a-z0-9]+/', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ranked = [];
        foreach ($this->registry->getTools()->references as $name => $tool) {
            $name = (string)$name;
            if (!$this->callable($name, $context)) {
                continue;
            }
            $score = $terms === [] ? 1 : $this->score($tool, $terms);
            if ($score > 0) {
                $ranked[] = [$score, $tool];
            }
        }
        usort($ranked, fn(array $a, array $b): int => [$b[0], $a[1]->name] <=> [$a[0], $b[1]->name]);
        $tools = [];
        foreach (array_slice($ranked, 0, max(1, min(50, $limit))) as [, $tool]) {
            $tools[] = [
                'name' => $tool->name,
                'title' => $tool->title,
                'description' => $tool->description,
                // A host asks before calling a tool it does not know to be read-only: tell the model what it is.
                'annotations' => $tool->annotations?->jsonSerialize(),
                'inputSchema' => $this->normalize
                    ? SchemaNormalizer::normalize($tool->inputSchema)
                    : $tool->inputSchema,
            ];
        }

        return ['tools' => $tools];
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
        foreach (array_values($calls) as $index => $call) {
            $name = is_array($call) && is_string($call['name'] ?? null) ? $call['name'] : '';
            $arguments = is_array($call) ? $call['arguments'] ?? [] : null;
            $result = is_array($arguments)
                ? $this->runOne($handler, $context, $parent->getId() . '.' . $index, $name, $arguments)
                : $this->error($name, sprintf('The arguments of %s must be an object.', $name));
            $results[] = $result;
            if ($result['isError']) {
                $failed = true;
                break;
            }
        }
        $data = ['results' => $results];

        // The summary comes first: it is what the audit records for this call, never the output of the tools.
        return new CallToolResult([
            new TextContent($this->summary($results, $failed)),
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
                        'description' => 'Words to look for in tool names and descriptions. '
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
        if (!$this->callable($name, $context)) {
            // Like a direct call: a denied tool answers as an unknown one.
            $response = Error::forInvalidParams(sprintf('Tool not found: "%s".', $name), $id);
        } else {
            $response = $handler->handle($request, $session);
        }
        if ($response instanceof Error) {
            $this->events?->dispatch(new ErrorEvent($response, $request, $session, null));

            return $this->error($name, $response->message);
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
     * @param list<array{name: string, isError: bool, content: array<mixed>}> $results The results so far.
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
        $text = $last['content'][0]['text'] ?? '';

        return sprintf('%s failed: %s', $last['name'], is_string($text) ? $text : '');
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
     * @return int Name matches count 3, title 2, description 1.
     */
    private function score(Tool $tool, array $terms): int
    {
        $score = 0;
        foreach ($terms as $term) {
            $score += str_contains(strtolower($tool->name), $term) ? 3 : 0;
            $score += str_contains(strtolower((string)$tool->title), $term) ? 2 : 0;
            $score += str_contains(strtolower((string)$tool->description), $term) ? 1 : 0;
        }

        return $score;
    }
}
