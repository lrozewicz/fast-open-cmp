<?php

use PHPUnit\Framework\TestCase;

/**
 * Consent payload deduplication: identical choices must hash identically even
 * though each visitor's cookie carries its own decision timestamp.
 */
final class ConsentPayloadTest extends TestCase
{
    public function testTimestampSectionIsRemoved(): void
    {
        $this->assertSame('g:1.2|o:3', FOCMP_API::strip_compact_timestamp('g:1.2|o:3|t:1792000000000'));
        $this->assertSame('c:custom-1|l:', FOCMP_API::strip_compact_timestamp('c:custom-1|l:|t:5'));
        $this->assertNull(FOCMP_API::strip_compact_timestamp(null));
        $this->assertNull(FOCMP_API::strip_compact_timestamp(''));
    }

    public function testSameChoicesShareAPayload(): void
    {
        $a = FOCMP_API::payload_hash('CQ.IA', '2~1.2~', FOCMP_API::strip_compact_timestamp('c:|l:|t:1'), FOCMP_API::strip_compact_timestamp('g:1|o:|t:111'));
        $b = FOCMP_API::payload_hash('CQ.IA', '2~1.2~', FOCMP_API::strip_compact_timestamp('c:|l:|t:2'), FOCMP_API::strip_compact_timestamp('g:1|o:|t:222'));
        $c = FOCMP_API::payload_hash('CQ.IA', '2~1~dv.2', 'c:|l:', 'g:1|o:');
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame(64, strlen($a));
    }
}
