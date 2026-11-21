<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Log;

use Cake\Log\Engine\BaseLog;
use Cake\Log\Log;

/**
 * Logs the config mistakes of `Mcp.servers` and `Mcp.clients` entries at `error` level, once per process
 * per message, to every log engine that takes an unscoped error (the application skeleton's `error`
 * logger has `'scopes' => null`) or an error in the `mcp` scope. Each engine gets it once.
 */
final class ConfigMistakes
{
    /**
     * @var array<string, true>
     */
    private static array $logged = [];

    /**
     * @param string $message The message.
     * @return void
     */
    public static function log(string $message): void
    {
        if (isset(self::$logged[$message])) {
            return;
        }
        self::$logged[$message] = true;

        $scope = [McpLogger::SCOPE];
        foreach (Log::configured() as $name) {
            $engine = Log::engine((string)$name);
            if ($engine === null) {
                continue;
            }
            $levels = $engine instanceof BaseLog ? $engine->levels() : [];
            $scopes = $engine instanceof BaseLog ? $engine->scopes() : null;
            if ($levels !== [] && !in_array('error', $levels, true)) {
                continue;
            }
            // Unscoped messages reach `null` scopes, scoped ones reach engines that list `mcp`, and `[]`
            // takes both: the same rules as Log::write(), applied once per engine.
            $takes = $scopes === null || $scopes === [] || array_intersect($scope, (array)$scopes) !== [];
            if ($takes) {
                $engine->log('error', $message, $scopes === null ? ['scope' => []] : ['scope' => $scope]);
            }
        }
    }

    /**
     * Forgets what was logged, so a test can see it logged again.
     *
     * @internal For tests.
     * @return void
     */
    public static function reset(): void
    {
        self::$logged = [];
    }
}
