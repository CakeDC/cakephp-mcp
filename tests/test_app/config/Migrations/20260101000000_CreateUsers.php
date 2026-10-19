<?php
declare(strict_types=1);

if (!class_exists('CakeDCMcpTestMigration', false)) {
    class_alias(
        class_exists('Migrations\\AbstractMigration') ? 'Migrations\\AbstractMigration' : 'Migrations\\BaseMigration',
        'CakeDCMcpTestMigration',
    );
}

class CreateUsers extends CakeDCMcpTestMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('name', 'string', ['limit' => 100])
            ->addColumn('email', 'string', ['limit' => 100])
            ->addColumn('role', 'string', ['limit' => 20])
            ->addColumn('active', 'boolean', ['default' => true])
            ->create();
    }

    public function down(): void
    {
        $this->table('users')->drop()->save();
    }
}
