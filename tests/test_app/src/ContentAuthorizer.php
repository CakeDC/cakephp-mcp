<?php
declare(strict_types=1);

namespace TestApp;

use CakeDC\Mcp\Authorization\PromptAuthorizerInterface;
use CakeDC\Mcp\Authorization\ResourceAuthorizerInterface;
use CakeDC\Mcp\Authorization\ToolAuthorizerInterface;

/**
 * Every tool for everyone. Nobody reads `app://salaries` or `orders://2001`, nor gets `payroll`; a guest
 * (`role` guest) also loses the template `orders://{number}` and the prompt `greet`.
 */
final class ContentAuthorizer implements ToolAuthorizerInterface, ResourceAuthorizerInterface, PromptAuthorizerInterface
{
    /**
     * @inheritDoc
     */
    public function canCall(mixed $identity, string $tool): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function canReadResource(mixed $identity, string $uri): bool
    {
        if (in_array($uri, ['app://salaries', 'orders://2001'], true)) {
            return false;
        }

        return !($uri === 'orders://{number}' && self::guest($identity));
    }

    /**
     * @inheritDoc
     */
    public function canGetPrompt(mixed $identity, string $name): bool
    {
        return $name !== 'payroll' && !($name === 'greet' && self::guest($identity));
    }

    /**
     * @param mixed $identity The identity.
     * @return bool
     */
    private static function guest(mixed $identity): bool
    {
        return is_array($identity) && ($identity['role'] ?? null) === 'guest';
    }
}
