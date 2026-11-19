<?php
declare(strict_types=1);

/**
 * Console entry of the test application, for example `php tests/test_app/bin/cake.php mcp server`.
 */

use Cake\Console\CommandRunner;
use TestApp\Application;

require dirname(__DIR__, 2) . '/bootstrap.php';

exit((new CommandRunner(new Application(CONFIG), 'cake'))->run($argv));
