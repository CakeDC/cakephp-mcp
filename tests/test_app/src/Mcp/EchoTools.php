<?php
declare(strict_types=1);

namespace TestApp\Mcp;

use CakeDC\Mcp\Identity\CurrentIdentity;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use RuntimeException;

class EchoTools
{
    public function __construct(private readonly CurrentIdentity $identity)
    {
    }

    /**
     * Returns the given text unchanged.
     */
    #[McpTool(name: 'echo', annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true))]
    public function echoText(string $text): string
    {
        return $text;
    }

    /**
     * Reports who is calling, from the plugin holder and the SDK access token.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'whoami')]
    public function whoami(RequestContext $context): array
    {
        return [
            'identifier' => $this->identity->getIdentifier(),
            'subject' => $context->getAccessToken()?->getSubject(),
        ];
    }

    /**
     * Always fails, to exercise error handling.
     */
    #[McpTool(name: 'explode')]
    public function explode(): string
    {
        throw new RuntimeException('boom with secret details');
    }
}
