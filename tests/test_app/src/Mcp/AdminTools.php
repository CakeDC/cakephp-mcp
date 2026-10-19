<?php
declare(strict_types=1);

namespace TestApp\Mcp;

use Mcp\Capability\Attribute\McpTool;

class AdminTools
{
    /**
     * Only admins should see this.
     */
    #[McpTool(name: 'admin_only')]
    public function adminOnly(): string
    {
        return 'admin secret';
    }
}
