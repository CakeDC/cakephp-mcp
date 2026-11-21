<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Client;

use Cake\Http\Client;
use Psr\Http\Client\ClientInterface;

/**
 * Cake's HTTP client (PSR-18) for configured URLs: the client's timeout, no redirects (a redirect would re-send the
 * token to wherever it points), and `Mcp.client.proxy` for every host but this machine.
 */
final class CakeHttpClientFactory implements HttpClientFactoryInterface
{
    /**
     * @inheritDoc
     */
    public function create(ClientConfig $config): ClientInterface
    {
        $options = ['timeout' => $config->timeout, 'redirect' => 0];
        $proxy = ClientConfig::proxy();
        if ($proxy !== null) {
            // The credentials stay in the URL, so curl sends them to the proxy only (Cake's `username` option
            // would add a Proxy-Authorization header that reaches the server through the tunnel).
            $options['proxy'] = ['proxy' => $proxy];
            // This machine directly: through the proxy a token for http://localhost would cross the network in
            // clear and reach the proxy's own localhost. Nothing else bypasses it, whatever NO_PROXY says.
            $options['curl'] = [CURLOPT_NOPROXY => implode(',', self::thisMachine($config))];
        }

        return new Client($options);
    }

    /**
     * The hosts the proxy is not used for: this machine's usual names, and each configured address the URL policy
     * takes as this machine (all of 127.0.0.0/8 and `*.localhost`), named one by one so that no curl version
     * needs to know address ranges.
     *
     * @param \CakeDC\Mcp\Client\ClientConfig $config The client.
     * @return list<string>
     */
    private static function thisMachine(ClientConfig $config): array
    {
        $hosts = ['localhost', '127.0.0.1', '::1'];
        $urls = [$config->url, $config->authorizationUrl, $config->tokenUrl, $config->revocationUrl, $config->issuer];
        foreach ($urls as $url) {
            $host = is_string($url) ? strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]')) : '';
            if ($host !== '' && ServerUrlPolicy::isLocal($host)) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
