<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use CakeDC\Mcp\Client\Exception\ClientException;
use Mcp\Client;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Schema\Result\ListResourcesResult;
use Mcp\Schema\Result\ListResourceTemplatesResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Result\ReadResourceResult;

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
     * @return list<\Mcp\Schema\Tool> The tools the server offers that the client's filters allow.
     */
    public function listTools(): array
    {
        $filter = $this->config()->toolFilter();
        $tools = [];
        $list = fn(Client $client, ?string $cursor) => $client->listTools($cursor);
        foreach ($this->pages('tools/list', ListToolsResult::class, $list) as $page) {
            foreach ($page->tools as $tool) {
                if ($filter->allows($tool->name)) {
                    $tools[] = $tool;
                }
            }
        }

        return $tools;
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
     * @return list<\Mcp\Schema\ResourceDefinition> The server's resources.
     */
    public function listResources(): array
    {
        $resources = [];
        $list = fn(Client $client, ?string $cursor) => $client->listResources($cursor);
        foreach ($this->pages('resources/list', ListResourcesResult::class, $list) as $page) {
            array_push($resources, ...array_values($page->resources));
        }

        return $resources;
    }

    /**
     * @return list<\Mcp\Schema\ResourceTemplate> The server's resource templates.
     */
    public function listResourceTemplates(): array
    {
        $templates = [];
        $list = fn(Client $client, ?string $cursor) => $client->listResourceTemplates($cursor);
        foreach ($this->pages('resources/templates/list', ListResourceTemplatesResult::class, $list) as $page) {
            array_push($templates, ...array_values($page->resourceTemplates));
        }

        return $templates;
    }

    /**
     * @param string $uri The resource.
     * @return \Mcp\Schema\Result\ReadResourceResult
     */
    public function readResource(string $uri): ReadResourceResult
    {
        return $this->run(fn(Client $client): ReadResourceResult => $client->readResource($uri));
    }

    /**
     * @return list<\Mcp\Schema\Prompt> The server's prompts.
     */
    public function listPrompts(): array
    {
        $prompts = [];
        $list = fn(Client $client, ?string $cursor) => $client->listPrompts($cursor);
        foreach ($this->pages('prompts/list', ListPromptsResult::class, $list) as $page) {
            array_push($prompts, ...array_values($page->prompts));
        }

        return $prompts;
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
     * Every page of a list, each from the list cache when it holds a fresh one for this caller.
     *
     * @template P of \Mcp\Schema\Result\ListToolsResult|\Mcp\Schema\Result\ListResourcesResult|\Mcp\Schema\Result\ListResourceTemplatesResult|\Mcp\Schema\Result\ListPromptsResult
     * @param string $method The list method.
     * @param class-string<P> $class The result class.
     * @param callable(\Mcp\Client, ?string): P $list Lists one page.
     * @return list<P>
     */
    private function pages(string $method, string $class, callable $list): array
    {
        $cache = $this->listCache();
        $pages = [];
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
            $pages[] = $page;
            $cursor = $page->nextCursor;
            $left--;
        } while ($cursor !== null && $cursor !== '' && $left > 0);

        return $pages;
    }
}
