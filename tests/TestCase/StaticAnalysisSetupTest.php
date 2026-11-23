<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase;

use Cake\TestSuite\TestCase;

/**
 * PHPStan runs out of PHP's default memory on this code base: `composer stan` and the CI job give it 1G.
 */
class StaticAnalysisSetupTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    public function testComposerStanGivesPhpstanEnoughMemory(): void
    {
        $composer = json_decode((string)file_get_contents(self::ROOT . 'composer.json'), true);

        $this->assertSame('phpstan analyse --no-progress --memory-limit=1G', $composer['scripts']['stan'] ?? null);
    }

    public function testTheCiJobGivesPhpstanEnoughMemory(): void
    {
        $ci = (string)file_get_contents(self::ROOT . '.github/workflows/ci.yml');

        $this->assertStringContainsString('run: vendor/bin/phpstan analyse --no-progress --memory-limit=1G', $ci);
        $this->assertSame(1, preg_match_all('/vendor\/bin\/phpstan analyse/', $ci));
    }
}
