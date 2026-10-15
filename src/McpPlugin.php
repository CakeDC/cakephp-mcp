<?php
declare(strict_types=1);

namespace CakeDC\Mcp;

use Cake\Cache\Cache;
use Cake\Cache\Engine\FileEngine;
use Cake\Core\BasePlugin;
use Cake\Core\PluginApplicationInterface;

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
}
