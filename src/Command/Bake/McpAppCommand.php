<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Command\Bake;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use CakeDC\Mcp\Bake\McpBakeCommand;

/**
 * `bin/cake bake mcp_app <Name>`: an MCP App (a `ui://` resource rendered from a Cake template, and a tool linked
 * to it) in `src/Mcp/`, its page in `templates/Mcp/`, and its test.
 */
class McpAppCommand extends McpBakeCommand
{
    /**
     * @inheritDoc
     */
    public static function getDescription(): string
    {
        return 'Bake an MCP App: its resource and tool, its page template and its test.';
    }

    /**
     * @inheritDoc
     */
    protected function kind(): string
    {
        return 'app';
    }

    /**
     * @inheritDoc
     */
    public function templateData(Arguments $arguments): array
    {
        $data = parent::templateData($arguments);
        $data['pageTemplate'] = ($this->plugin ? $this->plugin . '.' : '') . 'Mcp/' . $data['mcpName'];

        return $data;
    }

    /**
     * Bakes the class, then the page template.
     *
     * @param string $name The name, as given.
     * @param \Cake\Console\Arguments $args The arguments.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return void
     */
    protected function bake(string $name, Arguments $args, ConsoleIo $io): void
    {
        parent::bake($name, $args, $io);
        $data = $this->templateData($args);
        $contents = $this->createTemplateRenderer()->set($data)->generate('CakeDC/Mcp.Mcp/app_template');
        $base = $this->plugin ? $this->_pluginPath($this->plugin) : ROOT . DS;
        $io->createFile(
            $base . 'templates' . DS . 'Mcp' . DS . $data['mcpName'] . '.php',
            $contents,
            $this->force,
        );
    }
}
