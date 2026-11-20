<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use CakeDC\Mcp\Client\Http\CheckedEndpoint;
use CakeDC\Mcp\Client\Http\GuardedTransportInterface;
use CakeDC\Mcp\Client\Http\HttpRequest;
use CakeDC\Mcp\Client\Http\HttpResponse;
use Closure;

/**
 * The pinned transport for tests: canned answers by method and exact URL (404 otherwise), and a record of what was
 * sent and to which address.
 */
final class ScriptedGuardedTransport implements GuardedTransportInterface
{
    /**
     * @var list<array{request: \CakeDC\Mcp\Client\Http\HttpRequest, ip: string}>
     */
    public array $sent = [];

    /**
     * @var array<string, \CakeDC\Mcp\Client\Http\HttpResponse|Closure(\CakeDC\Mcp\Client\Http\HttpRequest): \CakeDC\Mcp\Client\Http\HttpResponse>
     */
    private array $answers = [];

    /**
     * @param string $method HTTP method.
     * @param string $url The exact URL.
     * @param \CakeDC\Mcp\Client\Http\HttpResponse|Closure(\CakeDC\Mcp\Client\Http\HttpRequest): \CakeDC\Mcp\Client\Http\HttpResponse $answer The answer.
     * @return void
     */
    public function on(string $method, string $url, HttpResponse|Closure $answer): void
    {
        $this->answers[$method . ' ' . $url] = $answer;
    }

    /**
     * @param string $url The exact URL.
     * @param array<string, mixed> $document The JSON document a GET answers.
     * @return void
     */
    public function json(string $url, array $document): void
    {
        $this->on('GET', $url, new HttpResponse(
            200,
            ['Content-Type' => ['application/json']],
            (string)json_encode($document, JSON_UNESCAPED_SLASHES),
        ));
    }

    /**
     * @return list<string> "METHOD url" of every request sent.
     */
    public function urls(): array
    {
        return array_map(fn(array $sent): string => $sent['request']->method . ' ' . $sent['request']->url, $this->sent);
    }

    /**
     * @inheritDoc
     */
    public function send(HttpRequest $request, CheckedEndpoint $pin, int $maxBytes): HttpResponse
    {
        $this->sent[] = ['request' => $request, 'ip' => $pin->ip];
        $answer = $this->answers[$request->method . ' ' . $request->url] ?? new HttpResponse(404, [], '{}');

        return $answer instanceof Closure ? $answer($request) : $answer;
    }
}
