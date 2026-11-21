<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Authorization;

/**
 * Implemented by an authorizer (next to `ToolAuthorizerInterface`) that decides who may get which prompt. A
 * denied prompt is left out of `prompts/list`, getting it answers like an unknown prompt, and its completions
 * return no values. An authorizer that does not implement it lets every caller get every prompt, as in 2.1.
 */
interface PromptAuthorizerInterface
{
    /**
     * Whether $identity may get the prompt $name.
     *
     * @param mixed $identity The identity, or null when anonymous.
     * @param string $name The prompt name.
     * @return bool
     */
    public function canGetPrompt(mixed $identity, string $name): bool;
}
