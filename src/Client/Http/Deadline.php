<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

use CakeDC\Mcp\Client\Exception\ClientException;
use Closure;

/**
 * A time limit for all the requests of one step together, such as starting a sign-in (the probe, the metadata
 * documents, their redirects and the registration): each request gets what is left of it, so a slow server holds
 * a web worker for the step's limit at most, not for every request's timeout in a row.
 */
final class Deadline
{
    private Closure $clock;

    private float $at;

    /**
     * @param float $seconds Seconds for the whole step, from now.
     * @param \Closure(): float|null $clock The current time in seconds; `microtime(true)` when null.
     */
    public function __construct(private float $seconds, ?Closure $clock = null)
    {
        $this->clock = $clock ?? fn(): float => microtime(true);
        $this->at = ($this->clock)() + $seconds;
    }

    /**
     * @param float $timeout The request's own timeout.
     * @param string $url The URL about to be requested.
     * @return float Seconds for that request: its timeout, or what is left when that is less.
     * @throws \CakeDC\Mcp\Client\Exception\ClientException When no time is left.
     */
    public function timeout(float $timeout, string $url): float
    {
        $left = $this->at - ($this->clock)();
        if ($left <= 0) {
            throw new ClientException(sprintf(
                'The server at %s took too long: the requests of this step may take %s %s in all.',
                (string)parse_url($url, PHP_URL_HOST),
                (string)round($this->seconds, 3),
                round($this->seconds, 3) === 1.0 ? 'second' : 'seconds',
            ));
        }

        return min($timeout, $left);
    }
}
