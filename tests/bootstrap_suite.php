<?php
declare(strict_types=1);

/**
 * The PHPUnit bootstrap: the shared bootstrap, plus a SQLite database with the oauth plugin's tables
 * and the test app's users, and the test app's authorization server configuration.
 */

use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use CakeDC\OAuthServer\OAuthServerPlugin;
use Migrations\TestSuite\Migrator;
use TestApp\OAuthTestConfig;

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

date_default_timezone_set('UTC');

// A fresh SQLite file per run, not ":memory:": cakephp/migrations reconnects between migration sets,
// which would give every set its own empty in-memory database.
$sqliteFile = TMP . 'mcp-test-' . getmypid() . '.sqlite';
if (!is_dir(TMP)) {
    mkdir(TMP, 0777, true);
}
register_shutdown_function(static function () use ($sqliteFile): void {
    if (is_file($sqliteFile)) {
        unlink($sqliteFile);
    }
});
ConnectionManager::setConfig('test', ['url' => 'sqlite:///' . $sqliteFile, 'timezone' => 'UTC']);
ConnectionManager::alias('test', 'default');

Configure::write('OAuthServer', OAuthTestConfig::SERVER);

Plugin::getCollection()->add(new OAuthServerPlugin());
(new Migrator())->runMany([['plugin' => 'CakeDC/OAuthServer'], []]);
