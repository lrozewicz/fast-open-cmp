<?php

use PHPUnit\Framework\TestCase;

/**
 * Portable consent export/import: validation and mapping of imported records,
 * stable ids for re-imports, and reading CSV / JSON Lines (plain and gzip).
 */
final class ConsentTransferTest extends TestCase
{
    private array $ctx;
    private array $tmp = [];

    protected function setUp(): void
    {
        $this->ctx = [
            'domains' => ['example.pl' => 7, 'news.example.com' => 8],
            'domain_ids' => [7 => true, 8 => true],
            'template_ids' => [3 => true],
            'now' => gmmktime(12, 0, 0, 10, 15, 2026),
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $file) {
            @unlink($file);
        }
    }

    private function record(array $over = []): array
    {
        return array_merge([
            'created_at' => '2026-01-02 03:04:05',
            'consent_id' => '0B0D5B6E-3E8A-4F7B-9C1D-2A3B4C5D6E7F',
            'domain' => 'https://www.example.pl/',
            'domain_id' => '99',
            'action' => 'accept-all',
            'mode' => 'tcf_gcm',
            'env' => 'prod',
            'template_id' => '3',
            'tc_string' => 'CQ_abc-1.IA',
            'ip_anon' => '203.0.113.42',
            'page_url' => 'https://example.pl/a?email=jan@example.com#x',
            'record_id' => '',
        ], $over);
    }

    public function testMapsTheDomainByNameAndCleansTheRecord(): void
    {
        $r = FOCMP_Consent_Transfer::normalize_record($this->record(), $this->ctx)['record'];
        $this->assertSame(7, $r['domain_id']);
        $this->assertSame('0b0d5b6e-3e8a-4f7b-9c1d-2a3b4c5d6e7f', $r['consent_id']);
        $this->assertSame('203.0.113.0', $r['ip_anon']);
        $this->assertSame('https://example.pl/a', $r['page_url']);
        $this->assertSame(3, $r['template_id']);
        $this->assertSame('2026-01-02 03:04:05', $r['created_at']);
    }

    public function testFallsBackToTheIdOnlyWithoutAName(): void
    {
        $r = FOCMP_Consent_Transfer::normalize_record($this->record(['domain' => '', 'domain_id' => '8']), $this->ctx);
        $this->assertSame(8, $r['record']['domain_id']);
        $unknown = FOCMP_Consent_Transfer::normalize_record($this->record(['domain' => 'other.pl']), $this->ctx);
        $this->assertSame(['error' => 'unknown_domain', 'domain' => 'other.pl'], $unknown);
    }

    public function testRejectsInvalidRecords(): void
    {
        foreach ([
            ['consent_id' => 'nope'],
            ['action' => 'maybe'],
            ['mode' => 'other'],
            ['created_at' => 'yesterday-ish'],
            ['created_at' => '2030-01-01 00:00:00'], // future
        ] as $over) {
            $this->assertSame(['error' => 'invalid'], FOCMP_Consent_Transfer::normalize_record($this->record($over), $this->ctx), json_encode($over));
        }
        $this->assertSame(['error' => 'invalid'], FOCMP_Consent_Transfer::normalize_record(null, $this->ctx));
    }

    public function testDropsMalformedStringsAndUnknownTemplates(): void
    {
        $r = FOCMP_Consent_Transfer::normalize_record($this->record(['tc_string' => '<script>', 'template_id' => '42']), $this->ctx)['record'];
        $this->assertNull($r['tc_string']);
        $this->assertNull($r['template_id']);
    }

    public function testDatesWithOffsetsBecomeUtc(): void
    {
        $now = $this->ctx['now'];
        $this->assertSame('2026-01-02 01:04:05', FOCMP_Consent_Transfer::utc_datetime('2026-01-02T03:04:05+02:00', $now));
        $this->assertSame('2026-01-02 03:04:05', FOCMP_Consent_Transfer::utc_datetime('2026-01-02T03:04:05Z', $now));
        $this->assertSame('2026-01-02 03:04:00', FOCMP_Consent_Transfer::utc_datetime('2026-01-02 03:04', $now));
        $this->assertNull(FOCMP_Consent_Transfer::utc_datetime('', $now));
    }

    public function testRecordIdsAreKeptOrDerivedStably(): void
    {
        $kept = FOCMP_Consent_Transfer::normalize_record($this->record(['record_id' => 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE']), $this->ctx);
        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $kept['record']['record_id']);

        $a = FOCMP_Consent_Transfer::normalize_record($this->record(), $this->ctx)['record']['record_id'];
        $b = FOCMP_Consent_Transfer::normalize_record($this->record(), $this->ctx)['record']['record_id'];
        $c = FOCMP_Consent_Transfer::normalize_record($this->record(['action' => 'reject-all']), $this->ctx)['record']['record_id'];
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a);
    }

    public function testCsvFormulaPrefixRoundTrips(): void
    {
        foreach (['=1+1', '-x', '@a', 'plain', "'quoted"] as $value) {
            $this->assertSame($value, FOCMP_Consent_Transfer::csv_unsafe(FOCMP_Consent_Transfer::csv_safe($value)));
        }
    }

    private function file(string $content, bool $gzip = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'focmp');
        file_put_contents($path, $gzip ? gzencode($content) : $content);
        $this->tmp[] = $path;
        return $path;
    }

    public function testReadsCsvWithBomAndFormulaPrefixes(): void
    {
        $csv = "\xEF\xBB\xBFcreated_at,consent_id,user_agent\n2026-01-02 03:04:05,c1,\"'=HYPERLINK(\"\"x\"\")\"\n\n2026-01-03 00:00:00,c2,UA\n";
        $rows = iterator_to_array(FOCMP_Consent_Transfer::read_records($this->file($csv)), false);
        $this->assertCount(2, $rows);
        $this->assertSame('c1', $rows[0]['consent_id']);
        $this->assertSame('=HYPERLINK("x")', $rows[0]['user_agent']);
    }

    public function testReadsGzippedJsonLinesAndSkipsTheHeader(): void
    {
        $jsonl = json_encode(['format' => 'fast-open-cmp-consents', 'version' => 1]) . "\n"
            . json_encode(['consent_id' => 'c1']) . "\n"
            . "not json\n\n"
            . json_encode(['consent_id' => 'c2']) . "\n";
        $rows = iterator_to_array(FOCMP_Consent_Transfer::read_records($this->file($jsonl, true)), false);
        $this->assertSame([['consent_id' => 'c1'], null, ['consent_id' => 'c2']], $rows);
    }
}
