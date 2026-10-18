<?php

use PHPUnit\Framework\TestCase;

// FakeWpdb is defined in tests/bootstrap.php (shared across test classes).

/**
 * Unit tests for the vendor-filtering helpers in FOCMP_API. The class has no
 * constructor and no load-time WordPress dependency, so we instantiate it
 * directly and reach the private helpers via reflection.
 */
final class VendorFilteringTest extends TestCase
{
    private FOCMP_API $api;

    protected function setUp(): void
    {
        $this->api = new FOCMP_API();
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /** @param array<int,mixed> $args */
    private function call(string $method, array $args): mixed
    {
        $ref = new ReflectionMethod(FOCMP_API::class, $method);
        return $ref->invokeArgs($this->api, $args);
    }

    private function wpdb(): FakeWpdb
    {
        return $GLOBALS['wpdb'];
    }

    // --- get_visible_vendor_groups (pure) ---------------------------------

    public function testNoListAssignedKeepsIabVisible(): void
    {
        // No list assigned (null) → no restriction, IAB stays visible.
        $groups = $this->call('get_visible_vendor_groups', [
            null,
            ['google' => [], 'other' => []],
            [],
        ]);
        $this->assertTrue($groups['iab']);
        $this->assertFalse($groups['google']);
        $this->assertFalse($groups['other']);
    }

    public function testListWithoutIabIdsHidesIab(): void
    {
        // The §5 bug: a list assigned but with no enabled IAB ids → IAB hidden,
        // not left dangling visible. Google group has a vendor → visible.
        $groups = $this->call('get_visible_vendor_groups', [
            5,
            ['google' => ['43' => []], 'other' => []],
            [],
        ]);
        $this->assertFalse($groups['iab']);
        $this->assertTrue($groups['google']);
        $this->assertFalse($groups['other']);
    }

    public function testListWithIabIdsShowsIab(): void
    {
        $groups = $this->call('get_visible_vendor_groups', [
            5,
            ['google' => [], 'other' => ['2' => []]],
            [8, 10],
        ]);
        $this->assertTrue($groups['iab']);
        $this->assertFalse($groups['google']);
        $this->assertTrue($groups['other']);
    }

    // --- is_vendor_enabled_in_list ----------------------------------------

    public function testNullListEnablesEveryVendor(): void
    {
        // null list → true, no DB lookup (backward compatible).
        $this->assertTrue($this->call('is_vendor_enabled_in_list', [null, 42, 'iab']));
    }

    /** Entries are loaded per (list, type) once and memoised — distinct list ids per test. */
    private function entries(array $map): void
    {
        $this->wpdb()->results = array_map(
            fn ($id, $on) => (object) ['vendor_id' => (string) $id, 'is_enabled' => $on ? '1' : '0'],
            array_keys($map),
            $map
        );
    }

    public function testEntryPresentAndEnabled(): void
    {
        $this->entries([42 => true]);
        $this->assertTrue($this->call('is_vendor_enabled_in_list', [501, 42, 'custom']));
    }

    public function testMissingEntryIsDisabled(): void
    {
        // No row for this vendor in the list → disabled (this is exactly why a
        // newly imported vendor disappears until backfilled).
        $this->entries([7 => true]);
        $this->assertFalse($this->call('is_vendor_enabled_in_list', [502, 42, 'custom']));
    }

    public function testEntryPresentButDisabled(): void
    {
        $this->entries([42 => false]);
        $this->assertFalse($this->call('is_vendor_enabled_in_list', [503, 42, 'iab']));
    }

    public function testEntriesAreLoadedOncePerListAndType(): void
    {
        $this->entries([1 => true, 2 => false]);
        $this->assertTrue($this->call('is_vendor_enabled_in_list', [504, 1, 'iab']));
        $this->wpdb()->results = []; // a second query would now return nothing
        $this->assertFalse($this->call('is_vendor_enabled_in_list', [504, 2, 'iab']));
        $this->assertTrue($this->call('is_vendor_enabled_in_list', [504, 1, 'iab']));
    }

    // --- get_enabled_iab_vendor_ids ---------------------------------------

    public function testNoListReturnsNull(): void
    {
        // null = no restriction (the SDK then shows all GVL vendors).
        $this->assertNull($this->call('get_enabled_iab_vendor_ids', [null]));
    }

    public function testReturnsIntIdsFromList(): void
    {
        // Rows come back as strings from the DB; helper must intval them.
        $this->wpdb()->col = ['8', '10', '755'];
        $this->assertSame([8, 10, 755], $this->call('get_enabled_iab_vendor_ids', [5]));
    }

    public function testEmptyListReturnsEmptyArray(): void
    {
        $this->wpdb()->col = [];
        $this->assertSame([], $this->call('get_enabled_iab_vendor_ids', [5]));
    }
}
