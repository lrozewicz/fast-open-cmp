<?php
/**
 * Admin Panel
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/admin/trait-menu.php';
require_once __DIR__ . '/admin/trait-entities.php';
require_once __DIR__ . '/admin/trait-settings-ajax.php';
require_once __DIR__ . '/admin/trait-consent-log.php';
require_once __DIR__ . '/admin/trait-backup.php';

class FOCMP_Admin {
    use FOCMP_Admin_Menu, FOCMP_Admin_Entities, FOCMP_Admin_SettingsAjax, FOCMP_Admin_ConsentLog, FOCMP_Admin_Backup;
}
