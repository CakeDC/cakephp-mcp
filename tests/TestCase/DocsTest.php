<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\McpConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class DocsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /**
     * @return list<string>
     */
    private function markdownFiles(): array
    {
        $files = [self::ROOT . 'README.md', self::ROOT . 'CHANGELOG.md'];
        foreach (glob(self::ROOT . 'docs/*.md') ?: [] as $file) {
            $files[] = $file;
        }

        return $files;
    }

    public function testEveryAuthSettingIsDocumented(): void
    {
        $configuration = (string)file_get_contents(self::ROOT . 'docs/configuration.md');
        $keys = [];
        foreach (McpConfig::DEFAULTS['auth'] as $key => $value) {
            $keys[] = 'auth.' . $key;
            if (is_array($value) && !array_is_list($value)) {
                foreach (array_keys($value) as $child) {
                    $keys[] = 'auth.' . $key . '.' . $child;
                }
            }
        }
        foreach ($keys as $key) {
            $this->assertStringContainsString('`' . $key . '`', $configuration, $key . ' is not documented');
        }
    }

    public function testRelativeLinksResolve(): void
    {
        foreach ($this->markdownFiles() as $file) {
            preg_match_all('/\]\((?!https?:|mailto:|#)([^)#\s]+)/', (string)file_get_contents($file), $matches);
            foreach ($matches[1] as $link) {
                $this->assertFileExists(dirname($file) . '/' . $link, basename($file) . ' links to ' . $link);
            }
        }
    }

    public function testNoEmOrEnDashes(): void
    {
        $files = $this->markdownFiles();
        $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT . 'src'));
        foreach ($sources as $source) {
            if ($source->isFile() && $source->getExtension() === 'php') {
                $files[] = $source->getPathname();
            }
        }
        foreach ($files as $file) {
            $text = (string)file_get_contents($file);
            $this->assertStringNotContainsString("\u{2014}", $text, $file . ' has an em-dash');
            $this->assertStringNotContainsString("\u{2013}", $text, $file . ' has an en-dash');
        }
    }
}
