<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit test for FOCMP_Importer::backfill_vendor_list_entries() (todo §5). Asserts
 * the SQL shape — one INSERT IGNORE ... CROSS JOIN per vendor type, against the
 * right source table and id column — without a database. INSERT IGNORE is what
 * makes the backfill safe to run repeatedly: it adds missing rows but never
 * overrides an entry an admin toggled off.
 */
final class ImporterBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function testIssuesOneInsertIgnorePerVendorType(): void
    {
        FOCMP_Importer::backfill_vendor_list_entries();

        $queries = $GLOBALS['wpdb']->queries;
        $this->assertCount(3, $queries, 'one backfill query per vendor type');

        $joined = implode("\n", $queries);
        foreach ($queries as $q) {
            $this->assertStringContainsStringIgnoringCase('INSERT IGNORE', $q);
            $this->assertStringContainsStringIgnoringCase('CROSS JOIN', $q);
            $this->assertStringContainsString('is_enabled = 1', $q);
        }

        // Each vendor type hits its own source table + id column.
        $this->assertStringContainsString('cmp_vendors_iab', $joined);
        $this->assertStringContainsString('cmp_vendors_google', $joined);
        $this->assertStringContainsString('cmp_vendors_custom', $joined);
        $this->assertStringContainsString('provider_id', $joined); // google id column
        $this->assertStringContainsString('cmp_vendor_lists', $joined);
        $this->assertStringContainsString('cmp_vendor_list_entries', $joined);
    }
}
