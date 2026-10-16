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
use CakeDC\Mcp\Command\McpInspectorCommand;
use CakeDC\Mcp\Command\McpServerCommand;
use CakeDC\Mcp\Identity\CurrentIdentity;
use CakeDC\Mcp\Server\ServerFactory;

/**
 * Exposes an MCP (Model Context Protocol) server from a CakePHP application.
 */
class McpPlugin extends BasePlugin
{
    /**
     * Defines the discovery and session cache configs when the host did not.
     *
     * @param \Cake\Core\PluginApplicationInterface $app The host application.
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);

        foreach ([McpConfig::get('discovery.cache'), McpConfig::get('session.cache')] as $name) {
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
        $path = McpConfig::get('route');
        if (!is_string($path) || $path === '') {
            return;
        }

        $routes->connect($path, ['plugin' => 'CakeDC/Mcp', 'controller' => 'Mcp', 'action' => 'handle'])
            ->setMethods(['GET', 'POST', 'DELETE', 'OPTIONS']);

        // Always connected: routes are cached, and a mode switch must not need a cache clear.
        // In host mode the controller answers 404.
        $routes->connect(
            McpConfig::METADATA_PREFIX . '/' . ltrim($path, '/'),
            ['plugin' => 'CakeDC/Mcp', 'controller' => 'ProtectedResource', 'action' => 'metadata'],
        )->setMethods(['GET', 'OPTIONS']);
    }

    /**
     * Registers `bin/cake mcp server`, the stdio transport, and `bin/cake mcp inspector`.
     *
     * @param \Cake\Console\CommandCollection $commands The collection.
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        return $commands
            ->add('mcp server', McpServerCommand::class)
            ->add('mcp inspector', McpInspectorCommand::class);
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
    }
}
