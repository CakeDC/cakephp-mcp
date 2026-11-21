<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\McpClients;

class McpClientStdioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('MCP_CLIENT_TEST_PARENT_SECRET=leaked');
        Configure::write('Mcp.clients.local', [
            'command' => PHP_BINARY,
            'args' => [ROOT . 'bin' . DS . 'stdio_mcp_server.php'],
            'env' => ['MCP_CLIENT_TEST_KEY' => 'from-config'],
            'timeout' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        putenv('MCP_CLIENT_TEST_PARENT_SECRET');
        McpClients::reset();
        parent::tearDown();
    }

    public function testCallsAToolOverStdio(): void
    {
        $client = McpClients::get('local');

        $names = array_map(fn($tool): string => $tool->name, $client->listTools());
        $this->assertSame(['echo', 'env'], $names);
        $this->assertSame('echo: over stdio', $client->callTool('echo', ['text' => 'over stdio'])->content[0]->text ?? null);
    }

    public function testACommandWhosePathHasASpaceStarts(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || !is_executable('/usr/bin/env')) {
            $this->markTestSkipped('POSIX only');
        }
        $dir = TMP . 'mcp stdio ' . getmypid();
        mkdir($dir);
        symlink(PHP_BINARY, $dir . DS . 'php');
        Configure::write('Mcp.clients.local.command', $dir . DS . 'php');
        try {
            $this->assertSame('echo: spaced', McpClients::get('local')->callTool('echo', ['text' => 'spaced'])->content[0]->text ?? null);
        } finally {
            McpClients::reset();
            unlink($dir . DS . 'php');
            rmdir($dir);
        }
    }

    public function testABareNameWithASpaceIsFoundInItsCwd(): void
    {
        if (DIRECTORY_SEPARATOR === '\\' || !is_executable('/usr/bin/env')) {
            $this->markTestSkipped('POSIX only');
        }
        $dir = TMP . 'mcp stdio cwd ' . getmypid();
        mkdir($dir);
        symlink(PHP_BINARY, $dir . DS . 'my php');
        Configure::write('Mcp.clients.local.command', 'my php');
        Configure::write('Mcp.clients.local.cwd', $dir);
        try {
            $this->assertSame('echo: bare', McpClients::get('local')->callTool('echo', ['text' => 'bare'])->content[0]->text ?? null);
        } finally {
            McpClients::reset();
            unlink($dir . DS . 'my php');
            rmdir($dir);
        }
    }

    public function testEnvIsPassedAndTheParentEnvIsNot(): void
    {
        $client = McpClients::get('local');

        $this->assertSame('from-config', $client->callTool('env', ['name' => 'MCP_CLIENT_TEST_KEY'])->content[0]->text ?? null);
        $this->assertSame('unset', $client->callTool('env', ['name' => 'MCP_CLIENT_TEST_PARENT_SECRET'])->content[0]->text ?? null);
        $this->assertNotSame('unset', $client->callTool('env', ['name' => 'PATH'])->content[0]->text ?? null);
    }
}
