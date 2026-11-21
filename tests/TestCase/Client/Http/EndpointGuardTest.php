<?php
declare(strict_types=1);

namespace CakeDC\Mcp\Test\TestCase\Client\Http;

use Cake\TestSuite\TestCase;
use CakeDC\Mcp\Client\Http\BlockedUrlException;
use CakeDC\Mcp\Client\Http\EndpointGuard;
use CakeDC\Mcp\Test\Support\StaticHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;

class EndpointGuardTest extends TestCase
{
    private StaticHostResolver $resolver;

    private EndpointGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new StaticHostResolver(['auth.example.com' => ['93.184.216.34', '2606:2800:220:1::1']]);
        $this->guard = new EndpointGuard($this->resolver);
    }

    public function testAPublicHostIsPinnedToItsFirstAddress(): void
    {
        $checked = $this->guard->check('https://Auth.Example.com/oauth/token?x=1');

        $this->assertSame('https://auth.example.com', $checked->origin);
        $this->assertSame('auth.example.com', $checked->host);
        $this->assertSame(443, $checked->port);
        $this->assertSame('93.184.216.34', $checked->ip);
    }

    public function testBehindAProxyAHostTheAppCannotResolvePasses(): void
    {
        $guard = new EndpointGuard($this->resolver, proxied: true);

        $checked = $guard->check('https://auth.partner.example/oauth/token');

        $this->assertSame('auth.partner.example', $checked->host);
        $this->assertSame('', $checked->ip);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedBehindAProxy(): array
    {
        return [
            'private literal' => ['https://10.0.0.5/token'],
            'loopback literal' => ['https://127.0.0.1/token'],
            'ipv6 literal' => ['https://[::1]/token'],
            'numeric host' => ['https://2130706433/token'],
            'localhost' => ['https://localhost/token'],
            'a localhost name' => ['https://auth.localhost/token'],
            'an mdns name' => ['https://printer.local/token'],
            'an internal name' => ['https://sso.internal/token'],
            'home.arpa' => ['https://router.home.arpa/token'],
            'a reverse lookup name' => ['https://1.0.0.127.in-addr.arpa/token'],
            'plain http' => ['http://auth.example.com/token'],
            'a single label' => ['https://intranet/token'],
        ];
    }

    #[DataProvider('refusedBehindAProxy')]
    public function testBehindAProxyNameChecksStay(string $url): void
    {
        $guard = new EndpointGuard($this->resolver, proxied: true);

        $this->expectException(BlockedUrlException::class);
        $guard->check($url);
    }

    public function testBehindAProxyAHostThatResolvesToAPrivateAddressIsStillRefused(): void
    {
        $this->resolver->add('evil.example.com', ['10.0.0.8']);
        $guard = new EndpointGuard($this->resolver, proxied: true);

        $this->expectException(BlockedUrlException::class);
        $guard->check('https://evil.example.com/token');
    }

    public function testSpecialUseNamesAreRefusedWithoutAProxyToo(): void
    {
        $this->resolver->add('auth.localhost', ['93.184.216.34']);

        $this->expectException(BlockedUrlException::class);
        $this->guard->check('https://auth.localhost/token');
    }

    public function testAllowingThePrivateNetworkLetsPrivateLoopbackAndLinkLocalAddressesThrough(): void
    {
        $guard = $this->guard->allowingPrivateNetwork(false);
        foreach (['sso.corp.internal' => '10.1.2.3', 'auth.localhost' => '127.0.0.1', 'meta.example.com' => '169.254.1.1'] as $host => $ip) {
            $this->resolver->add($host, [$ip]);
            $this->assertSame($ip, $guard->check('https://' . $host . '/x')->ip);
        }
        foreach (['https://10.1.2.3/x', 'https://intranet/x', 'http://sso.corp.internal/x'] as $refused) {
            try {
                $guard->check($refused);
                $this->fail('Accepted ' . $refused);
            } catch (BlockedUrlException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('http://sso.corp.internal', $this->guard->allowingPrivateNetwork(true)->check('http://sso.corp.internal/x')->origin);
    }

    public function testATrailingDotIsRefused(): void
    {
        // curl would not match the pin for "auth.example.com" against "auth.example.com." and resolve again.
        $this->expectException(BlockedUrlException::class);

        $this->guard->check('https://auth.example.com./oauth/token');
    }

    public function testThePortIsKept(): void
    {
        $checked = $this->guard->check('https://auth.example.com:8443/token');

        $this->assertSame('https://auth.example.com:8443', $checked->origin);
        $this->assertSame(8443, $checked->port);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function privateAddresses(): array
    {
        return [
            'rfc1918 10' => ['10.0.0.1'],
            'rfc1918 192' => ['192.168.1.1'],
            'rfc1918 172' => ['172.16.0.1'],
            'loopback' => ['127.0.0.1'],
            'link local metadata' => ['169.254.169.254'],
            'shared cgnat' => ['100.64.0.1'],
            'unspecified' => ['0.0.0.0'],
            'multicast' => ['224.0.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 unique local' => ['fc00::1'],
            'ipv6 link local' => ['fe80::1'],
            'ipv4 mapped private' => ['::ffff:10.0.0.1'],
            'nat64 private' => ['64:ff9b::a00:1'],
            '6to4 private' => ['2002:a00:1::1'],
            'documentation' => ['2001:db8::1'],
        ];
    }

    #[DataProvider('privateAddresses')]
    public function testAHostResolvingToAPrivateAddressIsRefused(string $address): void
    {
        $this->resolver->add('internal.example.com', [$address]);

        $this->expectException(BlockedUrlException::class);
        $this->expectExceptionMessage('internal.example.com is not a public address');

        $this->guard->check('https://internal.example.com/token');
    }

    public function testOnePrivateAddressAmongPublicOnesIsRefused(): void
    {
        $this->resolver->add('mixed.example.com', ['93.184.216.34', '10.0.0.1']);

        $this->expectException(BlockedUrlException::class);

        $this->guard->check('https://mixed.example.com/');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedUrls(): array
    {
        return [
            'http' => ['http://auth.example.com/token'],
            'ip literal' => ['https://93.184.216.34/token'],
            'ipv6 literal' => ['https://[2606:2800:220:1::1]/token'],
            'decimal ip' => ['https://2130706433/token'],
            'short ip' => ['https://127.1/token'],
            'hex ip' => ['https://0x7f000001/token'],
            'single label' => ['https://intranet/token'],
            'userinfo' => ['https://user:pass@auth.example.com/token'],
            'fragment' => ['https://auth.example.com/token#x'],
            'not resolving' => ['https://nowhere.example.com/token'],
            'no host' => ['https:///token'],
            'port zero' => ['https://auth.example.com:0/token'],
            'other scheme' => ['file:///etc/passwd'],
            'spaces' => ['https://auth.example.com/to ken'],
        ];
    }

    #[DataProvider('refusedUrls')]
    public function testRefusedUrls(string $url): void
    {
        $this->expectException(BlockedUrlException::class);

        $this->guard->check($url);
    }

    public function testTheMessageNamesOnlyTheHost(): void
    {
        $this->resolver->add('internal.example.com', ['10.0.0.1']);
        try {
            $this->guard->check('https://internal.example.com/token?secret=abc');
            $this->fail('Not refused');
        } catch (BlockedUrlException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringNotContainsString('10.0.0.1', $e->getMessage());
        }
    }
}
