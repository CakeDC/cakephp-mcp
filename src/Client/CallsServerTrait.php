<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use CakeDC\Mcp\Client\Exception\ClientException;
use Generator;
use InvalidArgumentException;
use Mcp\Client;
use Mcp\Schema\Implementation;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Schema\Result\ListResourceTemplatesResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Schema\ServerCapabilities;

/**
 * The calls a client makes to its server, for app mode (McpClient) and user mode (UserConnection) alike. Lists follow
 * every page; the client's tool filters apply to listTools() and callTool(), and a refused tool is never sent.
 *
 * Errors of the server or the connection are the SDK's exceptions (`Mcp\Exception\ExceptionInterface`).
 */
trait CallsServerTrait
{
    /**
     * Pages of a list followed at most, so a server cannot keep a call going.
     */
    private static int $maxPages = 50;

    /**
     * Runs $call on a connected SDK client.
     *
     * @template T
     * @param callable(\Mcp\Client): T $call The call.
     * @return T
     */
    abstract protected function run(callable $call): mixed;

    /**
     * @return \CakeDC\Mcp\Client\ClientConfig
     */
    abstract public function config(): ClientConfig;

    /**
     * @return \CakeDC\Mcp\Client\ListCache|null The list cache for this caller, or null to fetch every list.
     */
    abstract protected function listCache(): ?ListCache;

    /**
     * @param string $method A list method.
     * @return array<string, mixed>|null The raw `result` of the last answer to it.
     */
    abstract protected function lastResult(string $method): ?array;

    /**
     * Checks that the server answers: `ping`, or `server/discover` in the 2026-07-28 revision, which has no ping.
     *
     * @return void
     */
    public function ping(): void
    {
        $this->run(fn(Client $client) => $client->ping());
    }

    /**
     * @return \Mcp\Schema\Implementation|null The server's name, version and other details, from the handshake or
     *   `server/discover`.
     */
    public function serverInfo(): ?Implementation
    {
        return $this->run(fn(Client $client): ?Implementation => $client->getServerInfo());
    }

    /**
     * @return \Mcp\Schema\ServerCapabilities|null What the server declared it supports.
     */
    public function capabilities(): ?ServerCapabilities
    {
        return $this->run(fn(Client $client): ?ServerCapabilities => $client->getServerCapabilities());
    }

    /**
     * @return string|null The protocol revision the client and the server speak (`2026-07-28`, `2025-11-25`...):
     *   the one the handshake agreed, which may be another than `protocolVersion` asked for.
     */
    public function protocolVersion(): ?string
    {
        return $this->run(fn(Client $client): ?string => $client->getProtocolVersion()?->value);
    }

    /**
     * @return string|null The server's instructions for the model, if it gives any.
     */
    public function instructions(): ?string
    {
        return $this->run(fn(Client $client): ?string => $client->getInstructions());
    }

    /**
     * @param int|null $limit At most this many; pages past it are not fetched. Null for every tool.
     * @return list<\Mcp\Schema\Tool> The tools the server offers that the client's filters allow.
     */
    public function listTools(?int $limit = null): array
    {
        $filter = $this->config()->toolFilter();
        $tools = [];
        $list = fn(Client $client, ?string $cursor) => $client->listTools($cursor);
        foreach ($this->pages('tools/list', ListToolsResult::class, $list, $limit) as $page) {
            foreach ($page->tools as $tool) {
                if ($filter->allows($tool->name)) {
                    $tools[] = $tool;
                }
            }
            if ($limit !== null && count($tools) >= $limit) {
                break;
            }
        }

        return self::limited($tools, $limit);
    }

    /**
     * @param string $name The tool.
     * @param array<string, mixed> $arguments Its arguments.
     * @return \Mcp\Schema\Result\CallToolResult What the server answered; `isError` for a tool error.
     * @throws \CakeDC\Mcp\Client\Exception\ClientException When the client's filters refuse the tool.
     */
    public function callTool(string $name, array $arguments = []): CallToolResult
    {
        if (!$this->config()->toolFilter()->allows($name)) {
            throw new ClientException(sprintf(
                'The tool "%s" is not allowed by "Mcp.clients.%s.tools".',
                $name,
                $this->config()->name,
            ));
        }

        return $this->run(fn(Client $client): CallToolResult => $client->callTool($name, $arguments));
    }

    /**
     * @param int|null $limit At most this many; pages past it are not fetched. Null for every resource.
     * @return list<\Mcp\Schema\ResourceDefinition> The server's resources.
     */
    public function listResources(?int $limit = null): array
    {
        $resources = [];
        $list = fn(Client $client, ?string $cursor) => $client->listResources($cursor);
        foreach ($this->pages('resources/list', ListResourcesResult::class, $list, $limit) as $page) {
            array_push($resources, ...array_values($page->resources));
            if ($limit !== null && count($resources) >= $limit) {
                break;
            }
        }

        return self::limited($resources, $limit);
    }

    /**
     * @param int|null $limit At most this many; pages past it are not fetched. Null for every template.
     * @return list<\Mcp\Schema\ResourceTemplate> The server's resource templates.
     */
    public function listResourceTemplates(?int $limit = null): array
    {
        $templates = [];
        $list = fn(Client $client, ?string $cursor) => $client->listResourceTemplates($cursor);
        foreach ($this->pages('resources/templates/list', ListResourceTemplatesResult::class, $list, $limit) as $page) {
            array_push($templates, ...array_values($page->resourceTemplates));
            if ($limit !== null && count($templates) >= $limit) {
                break;
            }
        }

        return self::limited($templates, $limit);
    }

    /**
     * @param string $uri The resource.
     * @return \Mcp\Schema\Result\ReadResourceResult From the list cache when it holds a fresh read for this caller.
     */
    public function readResource(string $uri): ReadResourceResult
    {
        $cache = $this->listCache();
        $cached = $cache?->get('resources/read', $uri);
        if ($cached instanceof ReadResourceResult) {
            return $cached;
        }
        $read = $this->run(fn(Client $client): ReadResourceResult => $client->readResource($uri));
        $result = $cache === null ? null : $this->lastResult('resources/read');
        if ($cache !== null && $result !== null) {
            $cache->put('resources/read', $uri, $result, $read);
        }

        return $read;
    }

    /**
     * @param int|null $limit At most this many; pages past it are not fetched. Null for every prompt.
     * @return list<\Mcp\Schema\Prompt> The server's prompts.
     */
    public function listPrompts(?int $limit = null): array
    {
        $prompts = [];
        $list = fn(Client $client, ?string $cursor) => $client->listPrompts($cursor);
        foreach ($this->pages('prompts/list', ListPromptsResult::class, $list, $limit) as $page) {
            array_push($prompts, ...array_values($page->prompts));
            if ($limit !== null && count($prompts) >= $limit) {
                break;
            }
        }

        return self::limited($prompts, $limit);
    }

    /**
     * @param string $name The prompt.
     * @param array<string, string> $arguments Its arguments.
     * @return \Mcp\Schema\Result\GetPromptResult
     */
    public function getPrompt(string $name, array $arguments = []): GetPromptResult
    {
        return $this->run(fn(Client $client): GetPromptResult => $client->getPrompt($name, $arguments));
    }

    /**
     * The pages of a list, fetched as they are read, each from the list cache when it holds a fresh one for this
     * caller.
     *
     * @template P of \Mcp\Schema\Result\ListToolsResult|\Mcp\Schema\Result\ListResourcesResult|\Mcp\Schema\Result\ListResourceTemplatesResult|\Mcp\Schema\Result\ListPromptsResult
     * @param string $method The list method.
     * @param class-string<P> $class The result class.
     * @param callable(\Mcp\Client, ?string): P $list Lists one page.
     * @param int|null $limit The caller's limit, checked before any page is fetched.
     * @return \Generator<int, P>
     * @throws \InvalidArgumentException When $limit is below 1.
     */
    private function pages(string $method, string $class, callable $list, ?int $limit = null): Generator
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('A list limit must be 1 or more.');
        }
        $cache = $this->listCache();
        $cursor = null;
        $left = self::$maxPages;
        do {
            $cached = $cache?->get($method, $cursor);
            if ($cached instanceof $class) {
                $page = $cached;
            } else {
                $page = $this->run(fn(Client $client) => $list($client, $cursor));
                $result = $cache === null ? null : $this->lastResult($method);
                if ($cache !== null && $result !== null) {
                    $cache->put($method, $cursor, $result, $page);
                }
            }
            yield $page;
            $cursor = $page->nextCursor;
            $left--;
        } while ($cursor !== null && $cursor !== '' && $left > 0);
    }

    /**
     * @template I
     * @param list<I> $items The items.
     * @param int|null $limit At most this many.
     * @return list<I>
     */
    private static function limited(array $items, ?int $limit): array
    {
        return $limit === null ? $items : array_slice($items, 0, $limit);
    }
}
