<?php
declare(strict_types=1);

/**
 * Router for `php -S`: emits a 403 with a Bearer challenge through Cake's ResponseEmitter, the way an
 * application serves the MCP endpoint. With `?keep=1` it first applies ChallengeStatus::keep().
 */

use Cake\Http\Response;
use Cake\Http\ResponseEmitter;
use CakeDC\Mcp\Http\ChallengeStatus;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$response = (new Response(['charset' => 'UTF-8']))
    ->withStatus(403)
    ->withHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="notes:write"')
    ->withStringBody('{}');
if (($_GET['keep'] ?? '') === '1') {
    ChallengeStatus::keep($response);
}
(new ResponseEmitter())->emit($response);
