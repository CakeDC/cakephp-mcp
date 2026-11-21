<?php
declare(strict_types=1);

// cakephp/migrations 4 (CakePHP 5.0 to 5.2) runs phinx-style AbstractMigration classes, 5 only
// BaseMigration: extend whichever this installation has.
if (!class_exists('CakeDCMcpMigration', false)) {
    class_alias(
        class_exists('Migrations\\AbstractMigration') ? 'Migrations\\AbstractMigration' : 'Migrations\\BaseMigration',
        'CakeDCMcpMigration',
    );
}

/**
 * The scopes a server asked a user for with `403 insufficient_scope`, which the next connect of that user asks
 * the authorization server for (scope step-up).
 */
class AddStepUpScopeToMcpClientConnections extends CakeDCMcpMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('mcp_client_connections')
            ->addColumn('step_up_scope', 'text', ['null' => true])
            ->update();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('mcp_client_connections')
            ->removeColumn('step_up_scope')
            ->update();
    }
}
