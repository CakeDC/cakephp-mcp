<?php
declare(strict_types=1);

namespace TestApp\McpTenant;

use Cake\Routing\Router;
use CakeDC\Mcp\Server\McpRoute;
use Mcp\Capability\Attribute\McpTool;

/**
 * A tool of a server on a route with a parameter (`/mcp/{tenant}`).
 */
class TenantTools
{
    /**
     * @param \CakeDC\Mcp\Server\McpRoute $route The values of the route's parameters.
     */
    public function __construct(private readonly McpRoute $route)
    {
    }

    /**
     * The tenant of the request.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'tenant_info')]
    public function info(): array
    {
        return [
            'service' => $this->route->get('tenant'),
            'all' => $this->route->values(),
            'param' => Router::getRequest()?->getParam('tenant'),
        ];
    }
}
