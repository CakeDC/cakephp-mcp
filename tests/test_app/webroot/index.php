<?php
declare(strict_types=1);

/**
 * Serves the test app for manual testing:
 * php -S 127.0.0.1:8765 -t tests/test_app/webroot tests/test_app/webroot/index.php
 */

use Cake\Http\Server;
use TestApp\Application;

require dirname(__DIR__, 2) . '/bootstrap.php';

$server = new Server(new Application(CONFIG));
$server->emit($server->run());
