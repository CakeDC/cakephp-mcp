<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client\Http;

use Cake\Http\Client;
use Cake\Http\Client\Adapter\Curl;
use Cake\Http\Client\AdapterInterface;
use Cake\Http\Client\Request;
use CakeDC\Mcp\Client\Exception\ClientException;
use InvalidArgumentException;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Sends a guarded request with Cake's HTTP client and its Curl adapter, pinned with `CURLOPT_RESOLVE` to the address
 * EndpointGuard checked, so the connection reaches that address whatever the name resolves to later. No proxy (curl
 * would otherwise read `https_proxy` from the environment), https only, no redirects, and a size cap enforced while
 * reading (`CURLOPT_MAXFILESIZE` when the length is announced, a progress callback when it is not) and again on the
 * body.
 *
 * Options go to the adapter as given, never merged with a client config: Cake merges integer keys by appending,
 * which would lose the curl options.
 */
final class CakeGuardedTransport implements GuardedTransportInterface
{
    /**
     * @param \Cake\Http\Client\AdapterInterface|null $adapter The adapter; Cake's Curl adapter by default. Another
     *   adapter ignores the `curl` options, so it is for tests only.
     */
    public function __construct(private ?AdapterInterface $adapter = null)
    {
    }

    /**
     * @inheritDoc
     */
    public function send(HttpRequest $request, CheckedEndpoint $pin, int $maxBytes): HttpResponse
    {
        $parts = parse_url($request->url);
        $host = is_array($parts) ? strtolower(trim((string)($parts['host'] ?? ''), '[]')) : '';
        $host = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        $port = is_array($parts) ? (int)($parts['port'] ?? 443) : 0;
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        if ($scheme !== 'https' || $host !== $pin->host || $port !== $pin->port) {
            throw new BlockedUrlException('The request left the address that was checked.');
        }

        $tooLarge = false;
        $ip = str_contains($pin->ip, ':') ? '[' . $pin->ip . ']' : $pin->ip;
        $curl = [
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $pin->host, $pin->port, $ip)],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function ($handle, $total, $downloaded) use ($maxBytes, &$tooLarge): int {
                if ($downloaded > $maxBytes) {
                    $tooLarge = true;

                    return 1;
                }

                return 0;
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $curl[CURLOPT_PROTOCOLS_STR] = 'https';
        } else {
            $curl[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        $headers = array_filter(
            $request->headers,
            fn(string $name): bool => strcasecmp($name, 'host') !== 0,
            ARRAY_FILTER_USE_KEY,
        );

        try {
            $client = new Client(['adapter' => $this->adapter ?? Curl::class]);
            $response = $client->send(
                new Request($request->url, $request->method, $headers, $request->body),
                [
                    'timeout' => $request->timeout,
                    'ssl_verify_peer' => true,
                    'ssl_verify_host' => true,
                    'curl' => $curl,
                ],
            );
        } catch (ClientExceptionInterface | InvalidArgumentException $e) {
            // The message is left out: it quotes the URL, whose query may carry a secret.
            if ($tooLarge || str_contains($e->getMessage(), '(' . CURLE_FILESIZE_EXCEEDED . ')')) {
                throw self::tooLarge();
            }

            throw new ClientException(sprintf('The server at %s could not be reached.', $pin->host));
        }
        $body = (string)$response->getBody();
        if (strlen($body) > $maxBytes) {
            throw self::tooLarge();
        }

        return new HttpResponse($response->getStatusCode(), $response->getHeaders(), $body);
    }

    /**
     * @return \CakeDC\Mcp\Client\Exception\ClientException
     */
    private static function tooLarge(): ClientException
    {
        return new ClientException('The server sent a larger answer than the MCP client accepts.');
    }
}
