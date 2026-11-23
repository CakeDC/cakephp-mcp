<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Test\Support\McpHttpTrait;
use Mcp\Server\Builder;
use TestApp\TestTokens;

/**
 * `tools/list` over HTTP with most tools behind the catalog: the pages the filter empties are filled from the next.
 */
class McpCatalogPagesTest extends TestCase
{
    use IntegrationTestTrait;
    use McpHttpTrait;

    public function testTheFirstPageHoldsTheToolsLeftInTheList(): void
    {
        Configure::write('Mcp.discovery.scanDirs', []);
        Configure::write('Mcp.catalog', ['enabled' => true, 'tools' => ['report_*']]);
        Configure::write('Mcp.server.configure', function (Builder $builder): void {
            for ($i = 0; $i < 120; $i++) {
                $builder->addTool(fn(): string => 'report', sprintf('report_%03d', $i), description: 'Report ' . $i);
            }
        });

        $this->listTools(TestTokens::EDITOR);

        $this->assertResponseOk();
        $result = $this->responseJson()['result'] ?? [];
        $this->assertSame(['find_tools', 'call_tools'], array_column($result['tools'] ?? [], 'name'));
        $this->assertArrayNotHasKey('nextCursor', $result);
    }
}
