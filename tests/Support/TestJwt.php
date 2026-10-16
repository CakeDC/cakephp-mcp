<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\Support;

use Firebase\JWT\JWT;
use OpenSSLAsymmetricKey;
use RuntimeException;
use TestApp\OAuthTestConfig;

/**
 * An external authorization server for tests: an RSA key, its JWKS and the tokens it signs.
 */
final class TestJwt
{
    public const ISSUER = 'https://issuer.example';
    public const KID = 'test-key';

    private static ?OpenSSLAsymmetricKey $key = null;

    /**
     * A signed access token; $claims override the defaults (a null claim is sent as null).
     *
     * @param array<string, mixed> $claims Claims.
     * @return string
     */
    public static function token(array $claims = []): string
    {
        $pem = '';
        openssl_pkey_export(self::key(), $pem);

        return JWT::encode($claims + [
            'iss' => self::ISSUER,
            'aud' => OAuthTestConfig::MCP,
            'sub' => 'user-1',
            'iat' => time(),
            'exp' => time() + 300,
            'scope' => 'notes:read',
        ], $pem, 'RS256', self::KID);
    }

    /**
     * A client serving the issuer's RFC 8414 metadata and its JWKS.
     *
     * @return \CakeDC\Mcp\Test\Support\FakeHttpClient
     */
    public static function client(): FakeHttpClient
    {
        $details = openssl_pkey_get_details(self::key());
        if ($details === false) {
            throw new RuntimeException('Cannot read the test key.');
        }
        $client = new FakeHttpClient();
        $client->serveJson(self::ISSUER . '/.well-known/oauth-authorization-server', [
            'issuer' => self::ISSUER,
            'jwks_uri' => self::ISSUER . '/jwks',
        ]);
        $client->serveJson(self::ISSUER . '/jwks', ['keys' => [[
            'kty' => 'RSA',
            'kid' => self::KID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ]]]);

        return $client;
    }

    /**
     * @return \OpenSSLAsymmetricKey
     */
    private static function key(): OpenSSLAsymmetricKey
    {
        if (self::$key === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false) {
                throw new RuntimeException('Cannot create the test key.');
            }
            self::$key = $key;
        }

        return self::$key;
    }

    /**
     * @param string $bytes Bytes.
     * @return string
     */
    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
