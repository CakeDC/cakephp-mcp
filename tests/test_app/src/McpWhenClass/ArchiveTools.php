<?php
declare(strict_types=1);

namespace TestApp\McpWhenClass;

use CakeDC\Mcp\Attribute\McpWhen;
use Mcp\Capability\Attribute\McpTool;

/**
 * Tools registered only when `TestApp.archive` is on; `archive_export` also needs `TestApp.export`.
 */
#[McpWhen(config: 'TestApp.archive')]
class ArchiveTools
{
    /**
     * Lists the archive.
     */
    #[McpTool(name: 'archive_list')]
    public function list(): string
    {
        return 'list';
    }

    /**
     * Exports the archive.
     */
    #[McpWhen(config: 'TestApp.export')]
    #[McpTool(name: 'archive_export')]
    public function export(): string
    {
        return 'export';
    }
}
