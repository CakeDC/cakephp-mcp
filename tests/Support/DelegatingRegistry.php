<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use Mcp\Capability\Registry;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;

/**
 * A registry of the host's own, as a 2.1 application could set in `Mcp.server.configure`: it implements the SDK's
 * RegistryInterface and hands every call to an SDK Registry (which is final, so it cannot be extended).
 */
final class DelegatingRegistry implements RegistryInterface
{
    /**
     * @param \Mcp\Capability\Registry $inner The SDK registry it hands every call to.
     * @param bool $listPages Whether its pages are plain lists (keys 0..n), as a decorator that sorts or filters
     *   them gets from `usort()` or `array_values()`; `Mcp\Schema\Page` allows it.
     */
    public function __construct(
        private readonly Registry $inner = new Registry(),
        private readonly bool $listPages = false,
    ) {
    }

    /**
     * @param \Mcp\Schema\Page $page A page of the inner registry.
     * @return \Mcp\Schema\Page
     */
    private function page(Page $page): Page
    {
        return $this->listPages ? new Page(array_values($page->references), $page->nextCursor) : $page;
    }

    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        return $this->inner->registerTool($tool, $handler);
    }

    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        return $this->inner->registerResource($resource, $handler);
    }

    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
    ): ResourceTemplateReference {
        return $this->inner->registerResourceTemplate($template, $handler, $completionProviders);
    }

    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
    ): PromptReference {
        return $this->inner->registerPrompt($prompt, $handler, $completionProviders);
    }

    public function unregisterTool(string $name): void
    {
        $this->inner->unregisterTool($name);
    }

    public function unregisterResource(string $uri): void
    {
        $this->inner->unregisterResource($uri);
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        $this->inner->unregisterResourceTemplate($uriTemplate);
    }

    public function unregisterPrompt(string $name): void
    {
        $this->inner->unregisterPrompt($name);
    }

    public function publishesListChanges(): bool
    {
        return $this->inner->publishesListChanges();
    }

    public function hasTool(string $name): bool
    {
        return $this->inner->hasTool($name);
    }

    public function hasResource(string $uri): bool
    {
        return $this->inner->hasResource($uri);
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        return $this->inner->hasResourceTemplate($uriTemplate);
    }

    public function hasPrompt(string $name): bool
    {
        return $this->inner->hasPrompt($name);
    }

    public function hasTools(): bool
    {
        return $this->inner->hasTools();
    }

    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->page($this->inner->getTools($limit, $cursor));
    }

    public function getTool(string $name): ToolReference
    {
        return $this->inner->getTool($name);
    }

    public function hasResources(): bool
    {
        return $this->inner->hasResources();
    }

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->page($this->inner->getResources($limit, $cursor));
    }

    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
    {
        return $this->inner->getResource($uri, $includeTemplates);
    }

    public function hasResourceTemplates(): bool
    {
        return $this->inner->hasResourceTemplates();
    }

    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->page($this->inner->getResourceTemplates($limit, $cursor));
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        return $this->inner->getResourceTemplate($uriTemplate);
    }

    public function hasPrompts(): bool
    {
        return $this->inner->hasPrompts();
    }

    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->page($this->inner->getPrompts($limit, $cursor));
    }

    public function getPrompt(string $name): PromptReference
    {
        return $this->inner->getPrompt($name);
    }
}
