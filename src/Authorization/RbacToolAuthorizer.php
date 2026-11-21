<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Authorization;

use ArrayAccess;
use Cake\Http\ServerRequest;
use CakeDC\Auth\Rbac\Rbac;
use CakeDC\Auth\Rbac\RbacInterface;

/**
 * Per-tool permissions with cakedc/auth's Rbac, in the `config/permissions.php`
 * format CakeDC projects already use. Tool `X` is checked as plugin
 * `CakeDC/Mcp`, controller `Tools`, action `X`:
 *
 * ['role' => 'editor', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => ['create_draft']]
 *
 * Requires `cakedc/auth`.
 */
final class RbacToolAuthorizer implements
    ToolAuthorizerInterface,
    ResourceAuthorizerInterface,
    PromptAuthorizerInterface
{
    public const PLUGIN = 'CakeDC/Mcp';
    public const CONTROLLER = 'Tools';
    public const RESOURCES = 'Resources';
    public const PROMPTS = 'Prompts';

    private RbacInterface $rbac;

    /**
     * Per controller (`Resources`, `Prompts`), whether a rule names it with plugin `CakeDC/Mcp`.
     *
     * @var array<string, bool>
     */
    private array $decides = [];

    /**
     * @param \CakeDC\Auth\Rbac\RbacInterface|array<string, mixed> $rbac An Rbac instance, or the config for one.
     */
    public function __construct(RbacInterface|array $rbac = [])
    {
        $this->rbac = $rbac instanceof RbacInterface ? $rbac : new Rbac($rbac);
    }

    /**
     * The rules the Rbac loaded (from `permissions`, or from config/permissions.php when it has none).
     *
     * @internal Used by `bin/cake mcp check`; not part of the public API.
     * @return array<mixed>
     */
    public function permissions(): array
    {
        return $this->rbac->getPermissions();
    }

    /**
     * @inheritDoc
     */
    public function canCall(mixed $identity, string $tool): bool
    {
        return $this->check($identity, self::CONTROLLER, $tool);
    }

    /**
     * Resource `X` is checked as controller `Resources`, action `X` (the URI read, or a template's URI when
     * templates are listed or completed). Only when a rule names plugin `CakeDC/Mcp` and controller `Resources`:
     * with no such rule every resource stays readable, as in 2.1.
     *
     * @inheritDoc
     */
    public function canReadResource(mixed $identity, string $uri): bool
    {
        return !$this->decidesFor(self::RESOURCES) || $this->check($identity, self::RESOURCES, $uri);
    }

    /**
     * Prompt `X` is checked as controller `Prompts`, action `X`, only when a rule names plugin `CakeDC/Mcp` and
     * controller `Prompts`: with no such rule every prompt stays available, as in 2.1.
     *
     * @inheritDoc
     */
    public function canGetPrompt(mixed $identity, string $name): bool
    {
        return !$this->decidesFor(self::PROMPTS) || $this->check($identity, self::PROMPTS, $name);
    }

    /**
     * Whether a rule names plugin `CakeDC/Mcp` (exactly, or in a list) and $controller (exactly, or in a list).
     * Wildcards do not count, so a 2.1 rule set, or a host rule for a controller of its own with that name,
     * changes nothing.
     *
     * @internal Used by `bin/cake mcp check`; not part of the public API.
     * @param string $controller `Resources` or `Prompts`.
     * @return bool
     */
    public function decidesFor(string $controller): bool
    {
        if (!isset($this->decides[$controller])) {
            $names = fn(mixed $value, string $name): bool => $value === $name
                || (is_array($value) && in_array($name, $value, true));
            $this->decides[$controller] = array_filter(
                $this->permissions(),
                fn(mixed $rule): bool => is_array($rule)
                    && $names($rule['plugin'] ?? null, self::PLUGIN)
                    && $names($rule['controller'] ?? null, $controller),
            ) !== [];
        }

        return $this->decides[$controller];
    }

    /**
     * @param mixed $identity The identity.
     * @param string $controller The controller.
     * @param string $action The action.
     * @return bool
     */
    private function check(mixed $identity, string $controller, string $action): bool
    {
        if (!is_array($identity) && !$identity instanceof ArrayAccess) {
            return false;
        }

        $request = (new ServerRequest(['url' => '/mcp']))->withAttribute('params', [
            'plugin' => self::PLUGIN,
            'prefix' => null,
            'controller' => $controller,
            'action' => $action,
            '_ext' => null,
            'pass' => [],
        ]);

        return $this->rbac->checkPermissions($identity, $request);
    }
}
