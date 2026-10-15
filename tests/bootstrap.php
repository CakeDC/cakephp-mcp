<?php
declare(strict_types=1);

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Utility\Security;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

$pluginRoot = dirname(__DIR__);

define('ROOT', $pluginRoot . DS . 'tests' . DS . 'test_app' . DS);
define('APP_DIR', 'src');
define('APP', ROOT . 'src' . DS);
define('CONFIG', ROOT . 'config' . DS);
define('WWW_ROOT', ROOT . 'webroot' . DS);
define('TMP', ROOT . 'tmp' . DS);
define('LOGS', TMP . 'logs' . DS);
define('CACHE', TMP . 'cache' . DS);
define('CAKE_CORE_INCLUDE_PATH', $pluginRoot . DS . 'vendor' . DS . 'cakephp' . DS . 'cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);

require $pluginRoot . '/vendor/autoload.php';
require CORE_PATH . 'config' . DS . 'bootstrap.php';

Configure::write('debug', true);
Configure::write('App', [
    'namespace' => 'TestApp',
    'encoding' => 'UTF-8',
    'base' => false,
    'baseUrl' => false,
    'dir' => APP_DIR,
    'webroot' => 'webroot',
    'wwwRoot' => WWW_ROOT,
    'fullBaseUrl' => 'http://localhost',
    'paths' => [
        'plugins' => [ROOT . 'plugins' . DS],
        'templates' => [ROOT . 'templates' . DS],
    ],
]);

Security::setSalt('cakephp-mcp-test-salt-cakephp-mcp-test-salt-0123456789');

Cache::setConfig([
    '_cake_core_' => ['engine' => 'Array'],
    '_cake_translations_' => ['engine' => 'Array'],
    '_cake_model_' => ['engine' => 'Array'],
    '_cake_routes_' => ['engine' => 'Array'],
]);
