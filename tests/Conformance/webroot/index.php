<?php
declare(strict_types=1);

/**
 * Front controller of the conformance fixture: the test application and its
 * `/mcp` endpoint, open to anonymous callers, serving the elements of
 * ConformanceFixture. Run through tests/Conformance/run.sh.
 */

use Cake\Cache\Cache;
use Cake\Cache\Engine\FileEngine;
use Cake\Core\Configure;
use Cake\Http\Server;
use Cake\Log\Engine\FileLog;
use Cake\Log\Log;
use CakeDC\Mcp\Test\Conformance\ConformanceFixture;
use TestApp\Application;

ini_set('display_errors', '0');

define('TMP', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR);
define('LOGS', TMP . 'logs' . DIRECTORY_SEPARATOR);
define('CACHE', TMP . 'cache' . DIRECTORY_SEPARATOR);

require dirname(__DIR__, 2) . '/bootstrap.php';

Configure::write('debug', false);
Configure::write('App.base', '');

Cache::setConfig('mcp_conformance', ['className' => FileEngine::class, 'path' => CACHE . 'sessions' . DS]);
Cache::setConfig('mcp_conformance_bus', ['className' => FileEngine::class, 'path' => CACHE . 'bus' . DS]);
Log::setConfig('mcp', ['className' => FileLog::class, 'path' => LOGS, 'file' => 'mcp', 'scopes' => ['mcp']]);

Configure::write('Mcp', [
    'server' => [
        'name' => 'mcp-conformance-test-server',
        'version' => '1.0.0',
        'configure' => [ConformanceFixture::class, 'configure'],
    ],
    'discovery' => ['scanDirs' => [], 'cache' => null],
    'session' => ['cache' => 'mcp_conformance'],
    'identity' => ['required' => false],
    'http' => ['allowedHosts' => ['localhost', '127.0.0.1']],
]);

$server = new Server(new Application(CONFIG));
$server->emit($server->run());
