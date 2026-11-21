<?php
declare(strict_types=1);

/**
 * The plugin's MCP client under the client side of the official conformance suite: the suite runs
 * `php client.php <server-url>` with MCP_CONFORMANCE_SCENARIO, MCP_CONFORMANCE_PROTOCOL_VERSION and, for some
 * scenarios, MCP_CONFORMANCE_CONTEXT (JSON) set, and checks what the client sends to its scenario server.
 *
 * The auth scenarios run the user mode sign-in without a browser: the suite's authorization server approves at
 * once, so the authorization URL answers with a redirect to the callback, whose query completes the sign-in.
 * Their servers are on localhost over plain http, so the client allows that (`allowPrivateNetwork`,
 * `allowInsecure`), which a real deployment would not.
 */

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Routing\Router;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Client\ClientRegistry;
use CakeDC\Mcp\Client\Exception\NotConnectedException;
use CakeDC\Mcp\Client\McpClients;
use CakeDC\Mcp\Client\OAuth\ClientMetadataDocument;
use CakeDC\Mcp\Client\UserConnection;
use CakeDC\Mcp\McpPlugin;
use CakeDC\Mcp\Test\Conformance\MemoryConnectionStore;
use Mcp\Exception\ExceptionInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/MemoryConnectionStore.php';

const USER = 'conformance-user';

$url = $argv[$argc - 1] ?? '';
$scenario = (string)getenv('MCP_CONFORMANCE_SCENARIO');
$version = getenv('MCP_CONFORMANCE_PROTOCOL_VERSION');
$context = json_decode((string)getenv('MCP_CONFORMANCE_CONTEXT'), true);
$context = is_array($context) ? $context : [];
$auth = str_starts_with($scenario, 'auth/');

Configure::write('Mcp.client', ['name' => 'cakephp-mcp-conformance', 'version' => '1.0.0']);
$config = [
    'url' => $url,
    'timeout' => 20,
    'protocolVersion' => is_string($version) && $version !== '' ? $version : null,
];
if ($auth) {
    $config += ['auth' => 'user', 'allowPrivateNetwork' => true, 'allowInsecure' => true];
    foreach (['client_id' => 'clientId', 'client_secret' => 'clientSecret'] as $from => $to) {
        if (is_string($context[$from] ?? null)) {
            $config[$to] = $context[$from];
        }
    }
    if (isset($config['clientSecret']) && is_string($context['issuer'] ?? null)) {
        $config['issuer'] = $context['issuer'];
    }
}
Configure::write('Mcp.clients.conformance', array_filter($config, fn(mixed $value): bool => $value !== null));
McpClients::setRegistry(new ClientRegistry(null, new MemoryConnectionStore()));
// The connect, callback and client.json routes, on a base URL the authorization server can fetch for the Client
// ID Metadata Document scenario (it does not fetch it).
Router::reload();
Router::fullBaseUrl($scenario === 'auth/basic-cimd' ? 'https://conformance.example.com' : 'http://localhost:3000');
(new McpPlugin())->routes(Router::createRouteBuilder('/'));

/**
 * Signs the user in: the authorization URL, its redirect to the callback, and the code exchange.
 */
$signIn = function (): void {
    $callback = ClientMetadataDocument::callbackUrl(ClientConfig::fromConfig('conformance'));
    [$pending, $authorize] = McpClients::registry()->flow('conformance')->start(USER, $callback, '/');
    $response = (new Client(['redirect' => 0, 'timeout' => 20]))->get($authorize);
    $location = $response->getHeaderLine('Location');
    parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
    McpClients::registry()->flow('conformance')->complete($pending, array_map('strval', $query));
};

/**
 * Runs $work for the user, signing in first when needed, and again (at most twice) when the server asks for
 * more scope.
 */
$asUser = function (callable $work) use ($signIn): void {
    $user = McpClients::get('conformance')->for(USER);
    for ($attempt = 0;; $attempt++) {
        try {
            $work($user);

            return;
        } catch (NotConnectedException) {
            if ($attempt > 2) {
                throw new RuntimeException('Still not connected after signing in.');
            }
            $signIn();
        } catch (ExceptionInterface $e) {
            if (!$user instanceof UserConnection || !$user->needsMoreScope() || $attempt > 2) {
                throw $e;
            }
            $signIn();
        }
    }
};

$work = function ($client) use ($scenario, $context): void {
    $tools = $client->listTools();
    if (is_array($context['toolCalls'] ?? null)) {
        foreach ($context['toolCalls'] as $call) {
            $client->callTool((string)$call['name'], (array)($call['arguments'] ?? []));
        }
    } elseif ($scenario === 'tools_call') {
        $client->callTool('add_numbers', ['a' => 2, 'b' => 3]);
    } elseif ($scenario === 'json-schema-2020-12-preservation') {
        foreach ($tools as $tool) {
            if ($tool->name === 'json_schema_2020_12_tool') {
                $client->callTool('json_schema_echo', ['schema' => $tool->inputSchema]);
            }
        }
    } elseif ($scenario !== 'initialize') {
        // Exercise every tool once, with no arguments.
        foreach ($tools as $tool) {
            $client->callTool($tool->name);
        }
    }
};

try {
    $auth ? $asUser($work) : $work(McpClients::get('conformance'));
    McpClients::closeAtShutdown();
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
