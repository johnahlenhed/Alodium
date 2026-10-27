<?php

namespace Tests\Unit;

use App\Services\SafeImageDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The SSRF address check used for every image download from an imported export. */
class PublicAddressTest extends TestCase
{
    public static function rejected(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'loopback range' => ['127.255.0.9'],
            'private 10/8' => ['10.0.0.1'],
            'private 172.16/12' => ['172.16.0.1'],
            'private 172.31' => ['172.31.255.255'],
            'private 192.168/16' => ['192.168.1.1'],
            'cloud metadata (link-local)' => ['169.254.169.254'],
            'CGNAT' => ['100.64.0.1'],
            'CGNAT top' => ['100.127.255.254'],
            'unspecified' => ['0.0.0.0'],
            'this network' => ['0.1.2.3'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'documentation TEST-NET-1' => ['192.0.2.10'],
            'documentation TEST-NET-2' => ['198.51.100.7'],
            'documentation TEST-NET-3' => ['203.0.113.5'],
            'benchmarking' => ['198.18.0.1'],
            'IETF protocol assignments' => ['192.0.0.8'],
            'IPv6 loopback' => ['::1'],
            'IPv6 unspecified' => ['::'],
            'IPv6 link-local' => ['fe80::1'],
            'IPv6 unique-local' => ['fc00::1'],
            'IPv6 unique-local fd' => ['fd12:3456::1'],
            'IPv6 multicast' => ['ff02::1'],
            'IPv6 documentation' => ['2001:db8::1'],
            'IPv6 Teredo' => ['2001:0:4136:e378::1'],
            'IPv6 6to4' => ['2002:7f00:1::1'],
            'IPv6 NAT64' => ['64:ff9b::7f00:1'],
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'IPv4-mapped metadata' => ['::ffff:169.254.169.254'],
            'IPv4-mapped private, hex form' => ['::ffff:a00:1'],
            'not an address' => ['localhost'],
            'decimal spelling is not an address' => ['2130706433'],
            'empty' => [''],
        ];
    }

    public static function accepted(): array
    {
        return [
            'Google DNS' => ['8.8.8.8'],
            'Google DNS IPv6' => ['2001:4860:4860::8888'],
            'Cloudflare' => ['1.1.1.1'],
            'just outside 172.16/12' => ['172.32.0.1'],
            'just outside CGNAT' => ['100.128.0.1'],
            'IPv4-mapped public' => ['::ffff:8.8.8.8'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_non_public_addresses_are_rejected(string $ip): void
    {
        $this->assertFalse(SafeImageDownloader::isPublicAddress($ip));
    }

    #[DataProvider('accepted')]
    public function test_public_addresses_are_accepted(string $ip): void
    {
        $this->assertTrue(SafeImageDownloader::isPublicAddress($ip));
    }
}
