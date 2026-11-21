<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Authorization;

use CakeDC\Mcp\Identity\CurrentIdentity;
use Closure;

/**
 * Asks the authorizer whether the current identity may read a resource or get a prompt. An authorizer that does
 * not implement `ResourceAuthorizerInterface` (or `PromptAuthorizerInterface`) allows them all, as in 2.1, and so
 * does a trusted server (stdio started by the developer).
 */
final class ContentGate
{
    /**
     * @param \CakeDC\Mcp\Authorization\ToolAuthorizerInterface $authorizer The configured authorizer.
     * @param \CakeDC\Mcp\Identity\CurrentIdentity $identity The current identity.
     * @param bool $trusted Allow everything.
     * @param (\Closure(string): ?string)|null $templateOf The URI template that serves a concrete URI, or null
     *   when none does (a registered resource, or no match).
     */
    public function __construct(
        private readonly ToolAuthorizerInterface $authorizer,
        private readonly CurrentIdentity $identity,
        private readonly bool $trusted = false,
        private readonly ?Closure $templateOf = null,
    ) {
    }

    /**
     * Whether the authorizer decides for resources or prompts at all; when not, nothing is filtered.
     *
     * @return bool
     */
    public function active(): bool
    {
        return $this->decidesResources() || $this->decidesPrompts();
    }

    /**
     * Whether the authorizer can give callers different resources: it implements `ResourceAuthorizerInterface`
     * and, for `RbacToolAuthorizer`, has rules for controller `Resources`.
     *
     * @return bool
     */
    public function decidesResources(): bool
    {
        return !$this->trusted && $this->authorizer instanceof ResourceAuthorizerInterface && (
            !$this->authorizer instanceof RbacToolAuthorizer
            || $this->authorizer->decidesFor(RbacToolAuthorizer::RESOURCES)
        );
    }

    /**
     * Whether the authorizer can give callers different prompts.
     *
     * @return bool
     */
    public function decidesPrompts(): bool
    {
        return !$this->trusted && $this->authorizer instanceof PromptAuthorizerInterface && (
            !$this->authorizer instanceof RbacToolAuthorizer
            || $this->authorizer->decidesFor(RbacToolAuthorizer::PROMPTS)
        );
    }

    /**
     * @param string $uri A resource URI, a concrete URI read through a template, or a template's URI.
     * @return bool
     */
    public function allowsResource(string $uri): bool
    {
        return $this->trusted
            || !$this->authorizer instanceof ResourceAuthorizerInterface
            || $this->authorizer->canReadResource($this->identity->get(), $uri);
    }

    /**
     * Whether a URI may be read, subscribed to or completed: the URI itself is allowed and, when a resource
     * template serves it, so is the template's URI. A template that is denied denies every URI it serves.
     *
     * @param string $uri A resource URI or a concrete URI a template serves.
     * @return bool
     */
    public function allowsRead(string $uri): bool
    {
        if (!$this->allowsResource($uri)) {
            return false;
        }
        $template = $this->trusted || $this->templateOf === null ? null : ($this->templateOf)($uri);

        return $template === null || $template === $uri || $this->allowsResource($template);
    }

    /**
     * @param string $name The prompt name.
     * @return bool
     */
    public function allowsPrompt(string $name): bool
    {
        return $this->trusted
            || !$this->authorizer instanceof PromptAuthorizerInterface
            || $this->authorizer->canGetPrompt($this->identity->get(), $name);
    }
}
