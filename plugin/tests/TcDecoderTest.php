<?php

use PHPUnit\Framework\TestCase;

/**
 * Fixtures written by the official IAB library (@iabtechlabtcf/core).
 */
final class TcDecoderTest extends TestCase
{
    // purposes 1,3,4,7; LI 2,10; special feature 1; vendors 2,8,10,755; vendor LI 8,32 (range encoding)
    private const KNOWN = 'CQsE7wAQsE7wAGcB4BENAzCoALIAAEBAAB5YF5wBAABAAIAAUAvMAIACAAACAAAA.IAAA.YAAAAAAAAAAA';

    // Real string from another CMP (OneTrust): no consents yet, LI for purposes 2,7-11 and 244 vendors
    private const FOREIGN = 'CQsE7wAQsE7wAAcABBPLC0FgAAAAAEPgAAYgAAAXAABMNDogBLIgUCBQEAIEACgjCACgQBAAAkBRAQAkAAByBgAOsIgAAAQAAAQAgABBgACAAACABCIAIACAQAgQCBQABgAQBAQAMCAAGAChAAgABANAxCAggEAwASIwoDBAhAAQCAksqAEgCBAGAFIscAAgQEQQAACAABQAAAB4SABIAAAAAABUQTQAAAAACQYAIAAQEwBBQGQJAVgScBkaYAgeYJEEOgiAJAhIyDIBJEAAYURQAAByAkIAgQACAkUcABASKSAAgJFKAAQEigAA.YAAACHwAAAAA.ILpNR3G7_bWlv-bb2qbpkeYxf57hr5sQxBgbIsm4FyIPG7JwGH2EQJAyYtiAKmRIAsyDBIQNlHAhUQUAhCIAVLzDsaEyEoDtKJ-BkiHMRYwJQCEhmm4hjWQCYYurv50dwmR-E6Nr6WdxSwxgmHQKseuSFUJCMIQWtAfioZBCQ-ZAE8agQEwl47IZoEiUcSRN8oEkI4jcwwvP6chuwJeTyffyfv_fqmfS7T1vc__32t8iUGsLLqasu5xyMAAE';

    public function testDecodesAKnownString(): void
    {
        $tc = FOCMP_TC_Decoder::decode(self::KNOWN);
        $this->assertSame(412, $tc['cmpId']);
        $this->assertSame(120, $tc['cmpVersion']);
        $this->assertSame('EN', $tc['consentLanguage']); // the library took it from the GVL
        $this->assertSame('PL', $tc['publisherCountryCode']);
        $this->assertSame('2026-10-14T00:00:00+00:00', $tc['lastUpdated']);
        $this->assertSame([1, 3, 4, 7], $tc['purposesConsent']);
        $this->assertSame([2, 10], $tc['purposesLegitimateInterest']);
        $this->assertSame([1], $tc['specialFeatures']);
        $this->assertSame([2, 8, 10, 755], $tc['vendorsConsent']);
        $this->assertSame([8, 32], $tc['vendorsLegitimateInterest']);
    }

    public function testDecodesAStringFromAnotherCmp(): void
    {
        $tc = FOCMP_TC_Decoder::decode(self::FOREIGN);
        $this->assertSame(28, $tc['cmpId']);
        $this->assertSame(5, $tc['policyVersion']);
        $this->assertSame([], $tc['purposesConsent']);
        $this->assertSame([2, 7, 8, 9, 10, 11], $tc['purposesLegitimateInterest']);
        $this->assertCount(244, $tc['vendorsLegitimateInterest']);
    }

    public function testRejectsGarbage(): void
    {
        $this->assertNull(FOCMP_TC_Decoder::decode(''));
        $this->assertNull(FOCMP_TC_Decoder::decode('not a tc string!'));
        $this->assertNull(FOCMP_TC_Decoder::decode('AAAA'));
    }
}
