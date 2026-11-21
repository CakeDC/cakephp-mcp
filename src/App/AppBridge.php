<?php
declare(strict_types=1);

namespace CakeDC\Mcp\App;

use RuntimeException;

/**
 * The official MCP Apps client (`@modelcontextprotocol/ext-apps`, vendored in `resources/ext-apps`), as a script an
 * app page can inline: its final `export{a as B,...}` becomes `globalThis.McpApps={B:a,...}`, so the page's own
 * script uses `McpApps.App`. Hosts load an app in a sandboxed frame, where an inline script needs no CSP entry.
 */
final class AppBridge
{
    /**
     * The vendored file, relative to the plugin root.
     */
    public const FILE = 'resources/ext-apps/app-with-deps-2.0.4.js';

    /**
     * The script, once read.
     */
    private static ?string $script = null;

    /**
     * @return string The client as an inline script that defines `globalThis.McpApps`.
     * @throws \RuntimeException When the vendored file is missing or no longer ends with an `export{...}`.
     */
    public static function script(): string
    {
        if (self::$script !== null) {
            return self::$script;
        }
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::FILE;
        $source = is_file($path) ? (string)file_get_contents($path) : '';
        if (preg_match('/export\{([^}]*)\};?\s*$/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException(sprintf('%s is missing or does not end with export{...}.', self::FILE));
        }
        $pairs = [];
        foreach (explode(',', $match[1][0]) as $export) {
            $parts = preg_split('/\s+as\s+/', trim($export)) ?: [];
            $local = $parts[0] ?? '';
            if ($local !== '') {
                $pairs[] = ($parts[1] ?? $local) . ':' . $local;
            }
        }

        return self::$script = substr($source, 0, $match[0][1]) . 'globalThis.McpApps={' . implode(',', $pairs) . '};';
    }
}
