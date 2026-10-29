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
final class RbacToolAuthorizer implements ToolAuthorizerInterface
{
    public const PLUGIN = 'CakeDC/Mcp';
    public const CONTROLLER = 'Tools';

    private RbacInterface $rbac;

    /**
     * @param \CakeDC\Auth\Rbac\RbacInterface|array<string, mixed> $rbac An Rbac instance, or the config for one.
     */
    public function __construct(RbacInterface|array $rbac = [])
    {
        $this->rbac = $rbac instanceof RbacInterface ? $rbac : new Rbac($rbac);
    }

    /**
     * @inheritDoc
     */
    public function canCall(mixed $identity, string $tool): bool
    {
        if (!is_array($identity) && !$identity instanceof ArrayAccess) {
            return false;
        }

        $request = (new ServerRequest(['url' => '/mcp']))->withAttribute('params', [
            'plugin' => self::PLUGIN,
            'prefix' => null,
            'controller' => self::CONTROLLER,
            'action' => $tool,
            '_ext' => null,
            'pass' => [],
        ]);

        return $this->rbac->checkPermissions($identity, $request);
    }
}
