<?php
declare(strict_types=1);

/**
 * The PHPUnit bootstrap: the shared bootstrap and the test timezone. The suite needs no database.
 */

require __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php';

date_default_timezone_set('UTC');
