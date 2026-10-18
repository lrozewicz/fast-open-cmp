<?php

use PHPUnit\Framework\TestCase;

final class ConsentExportTest extends TestCase
{
    public function testParsesCompactCookieValues(): void
    {
        $this->assertSame(
            ['c' => ['custom-1', 'custom-3'], 'l' => []],
            FOCMP_Admin::parse_compact('c:custom-1.custom-3|l:|t:1792000000000')
        );
        $this->assertSame(['g' => ['43', '46'], 'o' => ['1']], FOCMP_Admin::parse_compact('g:43.46|o:1'));
        $this->assertSame([], FOCMP_Admin::parse_compact(''));
    }

    public function testReadsConsentedProvidersFromTheAcString(): void
    {
        $this->assertSame([1, 2, 89], FOCMP_Admin::parse_ac_consented('2~1.2.89~dv.3.4'));
        $this->assertSame([], FOCMP_Admin::parse_ac_consented('2~~dv.3.4'));
        $this->assertSame([], FOCMP_Admin::parse_ac_consented('garbage'));
    }
}
