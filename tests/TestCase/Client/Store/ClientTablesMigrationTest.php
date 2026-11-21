<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\Store;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * The tables of the MCP client's user mode come from one migration of the plugin, with every column the store
 * writes: 2.2 is the first release that has them, so there is nothing to upgrade from.
 */
class ClientTablesMigrationTest extends TestCase
{
    public function testOneMigrationCreatesTheClientTables(): void
    {
        $files = array_map('basename', (array)glob(dirname(__DIR__, 4) . '/config/Migrations/*.php'));

        $this->assertSame(['20261120120000_CreateMcpClientTables.php'], $files);
    }

    public function testTheConnectionsTableHasTheStepUpColumn(): void
    {
        /** @var \Cake\Database\Connection $db */
        $db = ConnectionManager::get('test');
        $columns = $db->getSchemaCollection()->describe('mcp_client_connections')->columns();

        $this->assertContains('step_up_scope', $columns);
    }
}
