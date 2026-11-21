<?php
declare(strict_types=1);

namespace CakeDC\Mcp;

use Cake\Cache\Cache;
use Cake\Cache\Engine\FileEngine;
use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Cake\Core\ContainerInterface;
use Cake\Core\PluginApplicationInterface;
use Cake\Routing\RouteBuilder;
use CakeDC\Mcp\Client\ClientConfig;
use CakeDC\Mcp\Command\McpCheckCommand;
use CakeDC\Mcp\Command\McpInspectorCommand;
use CakeDC\Mcp\Command\McpServerCommand;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Log\ConfigMistakes;
use CakeDC\Mcp\OAuth\AuthSettings;
use CakeDC\Mcp\Server\ServerFactory;

/**
 * Exposes an MCP (Model Context Protocol) server from a CakePHP application.
 */
class McpPlugin extends BasePlugin
{
    /**
     * Defines the discovery and session cache configs, and in external mode the authorization server's,
     * when the host did not.
     *
     * @param \Cake\Core\PluginApplicationInterface $app The host application.
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        // A mistake in one entry stops that entry only: it is logged, its routes are not connected and
        // `bin/cake mcp check` fails on it, while the rest of the application keeps working.
        foreach (McpConfig::serverErrors() as $name => $error) {
            ConfigMistakes::log(sprintf('MCP server "%s" is not served: %s', $name, $error));
        }
        foreach (ClientConfig::errors() as $name => $error) {
            ConfigMistakes::log(sprintf('MCP client "%s" is not usable: %s', $name, $error));
        }

        $caches = [];
        foreach (McpConfig::servers() as $server) {
            $caches[] = McpConfig::get('discovery.cache', $server);
            $caches[] = McpConfig::get('session.cache', $server);
            if (McpConfig::get('auth.mode', $server) === AuthSettings::EXTERNAL) {
                // Discovery metadata and signing keys of the external authorization server.
                $caches[] = McpConfig::get('auth.external.cache', $server);
            }
        }
        foreach ($caches as $name) {
            if (!is_string($name) || $name === '' || Cache::getConfig($name) !== null) {
                continue;
            }
            Cache::setConfig($name, [
                'className' => FileEngine::class,
                'path' => CACHE . 'mcp' . DS,
                'prefix' => $name . '_',
                'duration' => '+1 hours',
            ]);
        }
    }

    /**
     * Connects the MCP endpoint at `Mcp.route` (nothing when it is false), and its protected resource
     * metadata under `/.well-known/oauth-protected-resource`.
     *
     * @param \Cake\Routing\RouteBuilder $routes The route builder.
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        // Named servers first: the default server's routes carry no `server` param, so connected first they would
        // also match reverse routing to a named server.
        foreach (array_reverse(McpConfig::servers()) as $server) {
            $path = McpConfig::get('route', $server);
            if (!is_string($path) || $path === '') {
                continue;
            }
            // The default server keeps the 2.1 routes, without a `server` param, so reverse routing to them still
            // matches; the controllers read a missing param as `default`.
            $param = $server === McpConfig::DEFAULT_SERVER ? [] : ['server' => $server];
            $routes->connect(
                $path,
                ['plugin' => 'CakeDC/Mcp', 'controller' => 'Mcp', 'action' => 'handle'] + $param,
            )->setMethods(['GET', 'POST', 'DELETE', 'OPTIONS']);
            // Always connected: routes are cached, and a mode switch must not need a cache clear.
            // In host mode the controller answers 404.
            $routes->connect(
                McpConfig::METADATA_PREFIX . '/' . ltrim($path, '/'),
                ['plugin' => 'CakeDC/Mcp', 'controller' => 'ProtectedResource', 'action' => 'metadata'] + $param,
            )->setMethods(['GET', 'OPTIONS']);
        }

        // The sign-in of user mode clients (Mcp.clients with `auth` `user`), only when one is configured.
        if (ClientConfig::anyUserMode()) {
            $base = '/' . trim((string)ClientConfig::setting('route'), '/');
            // The Client ID Metadata Document (client.json), which authorization servers fetch.
            $actions = ['connect' => 'connect', 'callback' => 'callback', 'client.json' => 'metadata'];
            foreach ($actions as $path => $action) {
                $routes->connect(
                    $base . '/{client}/' . $path,
                    ['plugin' => 'CakeDC/Mcp', 'controller' => 'McpClient', 'action' => $action],
                )->setPass(['client'])->setPatterns(['client' => '[A-Za-z0-9_-]+'])->setMethods(['GET']);
            }
        }
    }

    /**
     * Registers `bin/cake mcp server`, the stdio transport, `bin/cake mcp inspector` and `bin/cake mcp check`.
     *
     * @param \Cake\Console\CommandCollection $commands The collection.
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        return $commands
            ->add('mcp server', McpServerCommand::class)
            ->add('mcp inspector', McpInspectorCommand::class)
            ->add('mcp check', McpCheckCommand::class);
    }

    /**
     * @param \Cake\Core\ContainerInterface $container The host container.
     * @return void
     */
    public function services(ContainerInterface $container): void
    {
        $container->addShared(CurrentIdentity::class);
        $container->addShared(ServerFactory::class)
            ->addArgument($container)
            ->addArgument(CurrentIdentity::class);
        $container->add(McpServerCommand::class)
            ->addArgument(ServerFactory::class)
            ->addArgument(CurrentIdentity::class);
        $container->add(McpCheckCommand::class)
            ->addArgument(ServerFactory::class);
    }
}
