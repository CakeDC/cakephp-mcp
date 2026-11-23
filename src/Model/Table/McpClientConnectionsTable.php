<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Model\Table;

use Cake\ORM\Table;

/**
 * Each user's grant for a user mode client (`mcp_client_connections`). Tokens and secrets are stored encrypted by
 * TableConnectionStore; read them through it.
 */
class McpClientConnectionsTable extends Table
{
    /**
     * @param array<string, mixed> $config The config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('mcp_client_connections');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }
}
