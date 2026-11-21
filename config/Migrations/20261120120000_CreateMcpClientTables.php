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
 *
 * Rows are found by a SHA-256 key (`connection_key` of client and user id, `registration_key` of client and
 * issuer), never by the plain values: the default collation of some databases (MySQL's utf8mb4_0900_ai_ci) would
 * match "alice" with "Alice" or "jose" with "josé", and the plain values can be as long as they need.
 */
class CreateMcpClientTables extends CakeDCMcpMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->table('mcp_client_connections')
            ->addColumn('connection_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('client', 'text', ['null' => false])
            ->addColumn('user_id', 'text', ['null' => false])
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
            // The scopes a server asked this user for with `403 insufficient_scope`, which the next connect asks
            // the authorization server for (scope step-up).
            ->addColumn('step_up_scope', 'text', ['null' => true])
            // The request refreshing the grant, so that two requests never send the same refresh token.
            ->addColumn('refresh_claim_id', 'string', ['limit' => 36, 'null' => true])
            ->addColumn('refresh_claimed_at', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['connection_key'], ['unique' => true])
            ->create();

        $this->table('mcp_client_registrations')
            ->addColumn('registration_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('client', 'text', ['null' => false])
            ->addColumn('issuer', 'text', ['null' => false])
            ->addColumn('redirect_uri', 'text', ['null' => false])
            ->addColumn('oauth_client_id', 'text', ['null' => false])
            ->addColumn('client_secret', 'text', ['null' => true])
            ->addColumn('auth_method', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['registration_key'], ['unique' => true])
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
