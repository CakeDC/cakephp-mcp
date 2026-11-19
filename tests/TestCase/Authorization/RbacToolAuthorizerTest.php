<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Authorization;

use ArrayObject;
use Authentication\Identity;
use Cake\TestSuite\TestCase;
use CakeDC\Auth\Rbac\Rbac;
use CakeDC\Mcp\Authorization\RbacToolAuthorizer;

class RbacToolAuthorizerTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function permissions(): array
    {
        return [
            [
                'role' => 'editor',
                'plugin' => 'CakeDC/Mcp',
                'controller' => 'Tools',
                'action' => ['echo', 'whoami'],
            ],
            [
                'role' => 'admin',
                'plugin' => 'CakeDC/Mcp',
                'controller' => 'Tools',
                'action' => '*',
            ],
            [
                'role' => '*',
                'plugin' => 'CakeDC/Mcp',
                'controller' => 'Tools',
                'action' => 'own_profile',
                'allowed' => fn($user, $role, $request): bool => ($user['id'] ?? null) === 1,
            ],
        ];
    }

    private function authorizer(): RbacToolAuthorizer
    {
        return new RbacToolAuthorizer(['permissions' => $this->permissions(), 'log' => false]);
    }

    public function testRolesAllowTheirTools(): void
    {
        $editor = ['id' => 1, 'role' => 'editor'];

        $this->assertTrue($this->authorizer()->canCall($editor, 'echo'));
        $this->assertFalse($this->authorizer()->canCall($editor, 'admin_only'));
        $this->assertTrue($this->authorizer()->canCall(['id' => 2, 'role' => 'admin'], 'admin_only'));
    }

    public function testCallableRulesSeeTheIdentity(): void
    {
        $this->assertTrue($this->authorizer()->canCall(['id' => 1, 'role' => 'viewer'], 'own_profile'));
        $this->assertFalse($this->authorizer()->canCall(['id' => 2, 'role' => 'viewer'], 'own_profile'));
    }

    public function testWorksWithAuthenticationIdentities(): void
    {
        $identity = new Identity(new ArrayObject(['id' => 1, 'role' => 'editor']));

        $this->assertTrue($this->authorizer()->canCall($identity, 'whoami'));
    }

    public function testNoIdentityIsDenied(): void
    {
        $this->assertFalse($this->authorizer()->canCall(null, 'echo'));
    }

    public function testAcceptsAPreparedRbacInstanceAndCustomRoleField(): void
    {
        $rbac = new Rbac([
            'role_field' => 'group',
            'log' => false,
            'permissions' => [
                ['role' => 'staff', 'plugin' => 'CakeDC/Mcp', 'controller' => 'Tools', 'action' => 'echo'],
            ],
        ]);

        $this->assertTrue((new RbacToolAuthorizer($rbac))->canCall(['id' => 3, 'group' => 'staff'], 'echo'));
    }
}
