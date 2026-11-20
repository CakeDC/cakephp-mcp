<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use CakeDC\Mcp\Client\Exception\ClientException;
use Mcp\Client;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\GetPromptResult;
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
     * @return list<\Mcp\Schema\Tool> The tools the server offers that the client's filters allow.
     */
    public function listTools(): array
    {
        $filter = $this->config()->toolFilter();
        $tools = [];
        foreach ($this->pages(fn(Client $client, ?string $cursor) => $client->listTools($cursor)) as $page) {
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
        foreach ($this->pages(fn(Client $client, ?string $cursor) => $client->listResources($cursor)) as $page) {
            array_push($resources, ...array_values($page->resources));
        }

        return $resources;
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
        foreach ($this->pages(fn(Client $client, ?string $cursor) => $client->listPrompts($cursor)) as $page) {
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
     * @template P of \Mcp\Schema\Result\ListToolsResult|\Mcp\Schema\Result\ListResourcesResult|\Mcp\Schema\Result\ListPromptsResult
     * @param callable(\Mcp\Client, ?string): P $list Lists one page.
     * @return list<P>
     */
    private function pages(callable $list): array
    {
        $pages = [];
        $cursor = null;
        $left = self::$maxPages;
        do {
            $page = $this->run(fn(Client $client) => $list($client, $cursor));
            $pages[] = $page;
            $cursor = $page->nextCursor;
            $left--;
        } while ($cursor !== null && $cursor !== '' && $left > 0);

        return $pages;
    }
}
