<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\ServerUrlPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class ServerUrlPolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function allowed(): array
    {
        return [
            'public https' => ['https://mcp.example.com/mcp', false],
            'internal https' => ['https://mcp.internal:8443/mcp', false],
            'private address https' => ['https://10.0.0.5/mcp', false],
            'localhost http' => ['http://localhost:8765/mcp', false],
            'loopback http' => ['http://127.0.0.1/mcp', false],
            'ipv6 loopback http' => ['http://[::1]:8080/mcp', false],
            'dot localhost http' => ['http://tools.localhost/mcp', false],
            'insecure allowed' => ['http://mcp.internal/mcp', true],
            'query kept' => ['https://mcp.example.com/mcp?team=1', false],
        ];
    }

    #[DataProvider('allowed')]
    public function testAllowed(string $url, bool $allowInsecure): void
    {
        ServerUrlPolicy::check($url, $allowInsecure, 'Mcp.clients.x.url');
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function refused(): array
    {
        return [
            'remote http' => ['http://mcp.example.com/mcp', false],
            'private http' => ['http://10.0.0.5/mcp', false],
            'localhost lookalike' => ['http://localhost.example.com/mcp', false],
            'userinfo' => ['https://user:pass@mcp.example.com/mcp', false],
            'fragment' => ['https://mcp.example.com/mcp#x', false],
            'other scheme' => ['ftp://mcp.example.com/mcp', true],
            'no host' => ['https:///mcp', false],
            'relative' => ['/mcp', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('refused')]
    public function testRefused(string $url, bool $allowInsecure): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mcp.clients.x.url"');

        ServerUrlPolicy::check($url, $allowInsecure, 'Mcp.clients.x.url');
    }

    public function testPlainHttpMessageNamesTheWayOut(): void
    {
        $this->expectExceptionMessage('allowInsecure');

        ServerUrlPolicy::check('http://mcp.example.com/mcp', false, 'Mcp.clients.x.url');
    }
}
