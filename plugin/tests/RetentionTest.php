<?php

use PHPUnit\Framework\TestCase;

/**
 * Cutoff of the automatic consent log clean-up: rolling days and/or a fixed date.
 */
final class RetentionTest extends TestCase
{
    private int $now;

    protected function setUp(): void
    {
        $this->now = gmmktime(12, 0, 0, 10, 15, 2026); // 2026-10-15 12:00 UTC
    }

    public function testDefaultIsThreeYears(): void
    {
        $this->assertSame('2023-10-16 12:00:00', FOCMP_Scheduler::retention_cutoff([], $this->now));
    }

    public function testZeroDaysAndNoDateKeepsEverything(): void
    {
        $this->assertNull(FOCMP_Scheduler::retention_cutoff(['consent_log_retention_days' => 0], $this->now));
        $this->assertNull(FOCMP_Scheduler::retention_cutoff(['consent_log_retention_days' => 0, 'consent_log_delete_before' => ''], $this->now));
    }

    public function testFixedDateAlone(): void
    {
        $settings = ['consent_log_retention_days' => 0, 'consent_log_delete_before' => '2025-01-01'];
        $this->assertSame('2025-01-01 00:00:00', FOCMP_Scheduler::retention_cutoff($settings, $this->now));
    }

    public function testTheCutoffThatDeletesMoreWins(): void
    {
        $later_date = ['consent_log_retention_days' => 1095, 'consent_log_delete_before' => '2025-01-01'];
        $this->assertSame('2025-01-01 00:00:00', FOCMP_Scheduler::retention_cutoff($later_date, $this->now));
        $earlier_date = ['consent_log_retention_days' => 30, 'consent_log_delete_before' => '2025-01-01'];
        $this->assertSame('2026-09-15 12:00:00', FOCMP_Scheduler::retention_cutoff($earlier_date, $this->now));
    }

    public function testInvalidDatesAreIgnored(): void
    {
        foreach (['2025-02-30', '2025-13-01', '25-01-01', "2025-01-01' OR 1=1", null] as $date) {
            $this->assertFalse(FOCMP_Scheduler::is_date($date));
            $this->assertNull(FOCMP_Scheduler::retention_cutoff(['consent_log_retention_days' => 0, 'consent_log_delete_before' => $date], $this->now));
        }
        $this->assertTrue(FOCMP_Scheduler::is_date('2024-02-29'));
    }
}
