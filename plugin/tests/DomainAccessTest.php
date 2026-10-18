<?php

use PHPUnit\Framework\TestCase;

/**
 * Public domain keys, host matching for the origin check, and the header parsing
 * of the compressed JSON responses.
 */
final class DomainAccessTest extends TestCase
{
    public function testGeneratedKeysAreRecognisedAndNeverNumeric(): void
    {
        $keys = [];
        for ($i = 0; $i < 200; $i++) {
            $key = FOCMP_Domain_Access::generate_key();
            $this->assertMatchesRegularExpression('/^[a-z0-9]{20}$/', $key);
            $this->assertTrue(FOCMP_Domain_Access::is_key($key));
            $keys[$key] = true;
        }
        $this->assertCount(200, $keys);
        $this->assertFalse(FOCMP_Domain_Access::is_key('12345678901234567890'));
        $this->assertFalse(FOCMP_Domain_Access::is_key('1'));
        $this->assertFalse(FOCMP_Domain_Access::is_key('ABCDEFGHIJKLMNOPQRST'));
        $this->assertFalse(FOCMP_Domain_Access::is_key(null));
    }

    public function testNormalizesWhatAdminsType(): void
    {
        $this->assertSame('example.pl', FOCMP_Domain_Access::normalize_host('https://www.Example.pl/path'));
        $this->assertSame('example.pl', FOCMP_Domain_Access::normalize_host('example.pl:443'));
        $this->assertSame('news.example.pl', FOCMP_Domain_Access::normalize_host(' news.example.pl. '));
        $this->assertSame('', FOCMP_Domain_Access::normalize_host(''));
    }

    public function testHostAndSubdomainsMatchButLookAlikesDoNot(): void
    {
        $this->assertTrue(FOCMP_Domain_Access::host_matches('example.pl', 'example.pl'));
        $this->assertTrue(FOCMP_Domain_Access::host_matches('www.example.pl', 'example.pl'));
        $this->assertTrue(FOCMP_Domain_Access::host_matches('a.b.example.pl', 'www.example.pl'));
        $this->assertFalse(FOCMP_Domain_Access::host_matches('badexample.pl', 'example.pl'));
        $this->assertFalse(FOCMP_Domain_Access::host_matches('example.pl.evil.com', 'example.pl'));
        $this->assertFalse(FOCMP_Domain_Access::host_matches('example.pl', 'news.example.pl'));
        $this->assertFalse(FOCMP_Domain_Access::host_matches('', 'example.pl'));
    }

    public function testRequestHostPrefersOriginAndIgnoresNullOrigins(): void
    {
        $this->assertSame('a.pl', FOCMP_Domain_Access::request_host(['HTTP_ORIGIN' => 'https://A.pl', 'HTTP_REFERER' => 'https://b.pl/x']));
        $this->assertSame('b.pl', FOCMP_Domain_Access::request_host(['HTTP_ORIGIN' => 'null', 'HTTP_REFERER' => 'https://b.pl/x?y=1']));
        $this->assertNull(FOCMP_Domain_Access::request_host([]));
        $this->assertNull(FOCMP_Domain_Access::request_host(['HTTP_REFERER' => 'not a url']));
    }

    public function testUnknownPagesAreAllowedOthersOnlyWhenListed(): void
    {
        $this->assertTrue(FOCMP_Domain_Access::host_allowed(null, 'example.pl'));
        $this->assertTrue(FOCMP_Domain_Access::host_allowed('cdn.example.pl', 'example.pl'));
        $this->assertFalse(FOCMP_Domain_Access::host_allowed('evil.com', 'example.pl'));
        $this->assertTrue(FOCMP_Domain_Access::host_allowed('cmp.host.pl', 'example.pl', ['cmp.host.pl']));
    }

    public function testAcceptEncoding(): void
    {
        $this->assertTrue(FOCMP_API::accepts_gzip('gzip, deflate, br'));
        $this->assertTrue(FOCMP_API::accepts_gzip('br;q=1.0, gzip;q=0.8'));
        $this->assertTrue(FOCMP_API::accepts_gzip('*'));
        $this->assertFalse(FOCMP_API::accepts_gzip('gzip;q=0'));
        $this->assertFalse(FOCMP_API::accepts_gzip('gzip; q=0.000'));
        $this->assertFalse(FOCMP_API::accepts_gzip('br, deflate'));
        $this->assertFalse(FOCMP_API::accepts_gzip(''));
    }

    public function testIfNoneMatch(): void
    {
        $etag = '"abc123"';
        $this->assertTrue(FOCMP_API::etag_matches('"abc123"', $etag));
        $this->assertTrue(FOCMP_API::etag_matches('W/"abc123"', $etag));
        $this->assertTrue(FOCMP_API::etag_matches('"x", "abc123"', $etag));
        $this->assertTrue(FOCMP_API::etag_matches('"abc123-gzip"', $etag));
        $this->assertTrue(FOCMP_API::etag_matches('*', $etag));
        $this->assertFalse(FOCMP_API::etag_matches('"abc124"', $etag));
        $this->assertFalse(FOCMP_API::etag_matches('', $etag));
    }
}
