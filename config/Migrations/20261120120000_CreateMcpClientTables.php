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
 * The tables of the MCP client's user mode: each user's grant, and the OAuth clients registered at authorization
 * servers. Needed only when a client in `Mcp.clients` uses `auth` `user` with the default store:
 * `bin/cake migrations migrate -p CakeDC/Mcp`.
 *
 * Tokens and secrets are stored encrypted (base64 of Security::encrypt()), hence text columns.
 */
class CreateMcpClientTables extends CakeDCMcpMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('mcp_client_connections')
            ->addColumn('client', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('user_id', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('access_token', 'text', ['null' => false])
            ->addColumn('refresh_token', 'text', ['null' => true])
            ->addColumn('expires', 'datetime', ['null' => true])
            ->addColumn('scope', 'text', ['null' => true])
            ->addColumn('issuer', 'text', ['null' => false])
            ->addColumn('token_url', 'text', ['null' => false])
            ->addColumn('revocation_url', 'text', ['null' => true])
            ->addColumn('oauth_client_id', 'text', ['null' => false])
            ->addColumn('client_secret', 'text', ['null' => true])
            ->addColumn('auth_method', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('resource', 'text', ['null' => false])
            // The request refreshing the grant, so that two requests never send the same refresh token.
            ->addColumn('refresh_claim_id', 'string', ['limit' => 36, 'null' => true])
            ->addColumn('refresh_claimed_at', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['client', 'user_id'], ['unique' => true])
            ->create();

        $this->table('mcp_client_registrations')
            ->addColumn('client', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('issuer', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('redirect_uri', 'text', ['null' => false])
            ->addColumn('oauth_client_id', 'text', ['null' => false])
            ->addColumn('client_secret', 'text', ['null' => true])
            ->addColumn('auth_method', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['client', 'issuer'], ['unique' => true])
            ->create();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('mcp_client_registrations')->drop()->save();
        $this->table('mcp_client_connections')->drop()->save();
    }
}
