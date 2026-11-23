<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\App;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\App\AppBridge;

class AppBridgeTest extends TestCase
{
    private const VENDORED = __DIR__ . '/../../../resources/ext-apps/app-with-deps-2.0.4.js';

    public function testTheExportBecomesAGlobal(): void
    {
        $script = AppBridge::script();

        $this->assertMatchesRegularExpression('/globalThis\.McpApps=\{[^}]*App:[A-Za-z0-9_$]+[^}]*\};$/', $script);
        $this->assertDoesNotMatchRegularExpression('/export\{[^}]*\};?\s*$/', $script);
        $this->assertStringNotContainsString('</script', $script);
    }

    public function testTheVendoredFileIsUnchanged(): void
    {
        $readme = (string)file_get_contents(dirname(self::VENDORED) . '/README.md');
        $hash = base64_encode(hash_file('sha512', self::VENDORED, true));

        $this->assertStringContainsString($hash, $readme);
    }
}
