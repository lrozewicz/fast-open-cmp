<?php

use PHPUnit\Framework\TestCase;

/**
 * uninstall.php must keep the data unless the admin opted in. Runs against the
 * fake $wpdb (no database), in a separate process because uninstall.php defines
 * nothing but executes on include.
 */
final class UninstallTest extends TestCase
{
    private function runUninstall(array $settings): FakeWpdb
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['focmp_test_options'] = ['focmp_settings' => $settings];
        $GLOBALS['focmp_deleted_options'] = [];
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'fast-open-cmp/fast-open-cmp.php');
        }
        include dirname(__DIR__) . '/uninstall.php';
        return $GLOBALS['wpdb'];
    }

    public function testKeepsDataByDefault(): void
    {
        $wpdb = $this->runUninstall([]);
        $this->assertSame([], $wpdb->queries);
        $this->assertSame([], $GLOBALS['focmp_deleted_options']);
    }

    public function testRemovesEverythingWhenOptedIn(): void
    {
        $wpdb = $this->runUninstall(['delete_data_on_uninstall' => true]);
        $drops = array_filter($wpdb->queries, fn ($q) => str_starts_with($q, 'DROP TABLE'));
        $this->assertCount(16, $drops);
        $this->assertContains('DROP TABLE IF EXISTS wp_cmp_consent_log', $drops);
        $this->assertContains('DROP TABLE IF EXISTS wp_cmp_templates', $drops);
        $this->assertContains('focmp_settings', $GLOBALS['focmp_deleted_options']);
        $this->assertContains('focmp_cache_version', $GLOBALS['focmp_deleted_options']);
    }
}
