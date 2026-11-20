<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Model\Table;

use Cake\ORM\Table;

/**
 * The OAuth clients registered for user mode clients (`mcp_client_registrations`). Secrets are stored encrypted by
 * TableConnectionStore; read them through it.
 */
class McpClientRegistrationsTable extends Table
{
    /**
     * @param array<string, mixed> $config The config.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('mcp_client_registrations');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }
}
