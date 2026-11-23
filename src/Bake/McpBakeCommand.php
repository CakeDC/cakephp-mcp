<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Bake;

use Bake\Command\SimpleBakeCommand;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Utility\Inflector;

/**
 * Bakes an invokable MCP class into `src/Mcp/` and its test into `tests/TestCase/Mcp/`. Outside `Command/Bake/`
 * because bake registers every class of that folder, abstract ones included.
 */
abstract class McpBakeCommand extends SimpleBakeCommand
{
    /**
     * @var string
     */
    public string $pathFragment = 'Mcp/';

    /**
     * What is baked: `tool`, `resource` or `prompt`.
     *
     * @return string
     */
    abstract protected function kind(): string;

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'mcp_' . $this->kind();
    }

    /**
     * Refuses a name that is not a class name (`Admin/ListOrders`, `list-orders`, `2fa`), which would write a file
     * of invalid PHP.
     *
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $raw = $args->getArgumentAt(0);
        if (is_string($raw) && $raw !== '') {
            [, $name] = pluginSplit($raw);
            if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1) {
                $io->error(sprintf(
                    '"%s" is not a class name: use letters, digits and "_", starting with a letter (ListOrders).',
                    $name,
                ));

                return static::CODE_ERROR;
            }
        }

        return parent::execute($args, $io);
    }

    /**
     * @inheritDoc
     */
    public function fileName(string $name): string
    {
        return $this->className($name) . '.php';
    }

    /**
     * @inheritDoc
     */
    public function template(): string
    {
        return 'CakeDC/Mcp.Mcp/' . $this->kind();
    }

    /**
     * @inheritDoc
     */
    public function templateData(Arguments $arguments): array
    {
        // `Plugin.Name` names the plugin too; execute() already took the plugin from it.
        [, $raw] = pluginSplit((string)$arguments->getArgumentAt(0));
        $name = $this->baseName(Inflector::camelize($raw));

        return parent::templateData($arguments) + [
            'className' => $this->className($name),
            'mcpName' => Inflector::underscore($name),
            'uriName' => Inflector::dasherize($name),
        ];
    }

    /**
     * Bakes the class, then its test unless `--no-test` is given.
     *
     * @param string $className The class name, as given.
     * @param \Cake\Console\Arguments $args The arguments.
     * @param \Cake\Console\ConsoleIo $io The console.
     * @return void
     */
    public function bakeTest(string $className, Arguments $args, ConsoleIo $io): void
    {
        if ($args->getOption('no-test')) {
            return;
        }
        $data = $this->templateData($args);
        $namespace = (string)$data['namespace'];
        $contents = $this->createTemplateRenderer()
            ->set($data + ['testNamespace' => $namespace . '\\Test\\TestCase\\Mcp'])
            ->generate('CakeDC/Mcp.tests/' . $this->kind());

        $base = $this->plugin ? $this->_pluginPath($this->plugin) : ROOT . DS;
        $io->createFile(
            $base . 'tests' . DS . 'TestCase' . DS . 'Mcp' . DS . $data['className'] . 'Test.php',
            $contents,
            $this->force,
        );
    }

    /**
     * The class name: the base name plus the kind's suffix (`ListOrders` and `ListOrdersTool` give `ListOrdersTool`).
     *
     * @param string $name The name as given.
     * @return string
     */
    private function className(string $name): string
    {
        return $this->baseName($name) . $this->suffix();
    }

    /**
     * @param string $name The name as given.
     * @return string The name without the kind's suffix.
     */
    private function baseName(string $name): string
    {
        $suffix = $this->suffix();
        if ($name !== $suffix && str_ends_with($name, $suffix)) {
            return substr($name, 0, -strlen($suffix));
        }

        return $name;
    }

    /**
     * @return string `Tool`, `Resource` or `Prompt`.
     */
    private function suffix(): string
    {
        return ucfirst($this->kind());
    }
}
