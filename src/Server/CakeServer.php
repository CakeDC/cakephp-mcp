<?php

/*
 * This file is part of the official CakeDC CakePHP MCP Plugin.
 */

namespace CakeDC\Mcp\Server;

use Cake\Core\Configure;
use Cake\Http\BaseApplication;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Transport\TransportInterface;

/**
 * @author Cake Development Corporation
 */
final class CakeServer
{
    protected static BaseApplication $application;

    protected Server $server;

    /**
     * Bootstrap an application
     */
    public static function bootstrap(): void
    {
        self::$application->bootstrap();

        if (!Configure::read('App.base')) {
            Configure::write('App.base', '');
        }

        self::$application->pluginBootstrap();
    }

    public static function builder(BaseApplication $application): Builder
    {
        self::$application = $application;
        self::bootstrap();

        return Server::builder();
    }

    public function connect(TransportInterface $transport): void
    {
        $this->server->connect($transport);
    }
}
