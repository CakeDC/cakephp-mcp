<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

class UsersFixture extends TestFixture
{
    public array $records = [
        ['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.com', 'role' => 'admin', 'active' => true],
        ['id' => 2, 'name' => 'Grace', 'email' => 'grace@example.com', 'role' => 'editor', 'active' => true],
        ['id' => 3, 'name' => 'Mallory', 'email' => 'mallory@example.com', 'role' => 'editor', 'active' => false],
    ];
}
