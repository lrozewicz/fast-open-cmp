<?php
/**
 * Plugin Activator
 *
 * Creates database tables and sets up initial options
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_Activator {

    /**
     * Database schema version. Bump it when tables change or data needs a
     * migration, and add a step to migrations(); maybe_upgrade() runs everything
     * newer than the stored focmp_db_version, independent of FOCMP_VERSION.
     *
     * 1 = schema up to plugin 1.2.0
     * 2 = consent log (cmp_consent_log)
     * 3 = consent log: record_id (idempotent retries)
     * 4 = consent log: deduplicated payloads + daily statistics
     * 5 = domains: random public key for snippets
     */
    const DB_VERSION = 5;

    /**
     * Activate the plugin
     */
    public static function activate() {
        self::create_tables();
        self::create_default_options();
        self::set_version();

        if (class_exists('FOCMP_Scheduler')) {
            FOCMP_Scheduler::ensure_scheduled();
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Run database upgrade if needed (called from admin_init)
     */
    public static function maybe_upgrade() {
        $stored_version = get_option('focmp_version', '0');
        $db_version = (int) get_option('focmp_db_version', 0);

        if ($db_version >= self::DB_VERSION && !version_compare($stored_version, FOCMP_VERSION, '<')) {
            return;
        }

        // One upgrade at a time (concurrent requests right after an update).
        if (get_transient('focmp_upgrading')) {
            return;
        }
        set_transient('focmp_upgrading', 1, 5 * MINUTE_IN_SECONDS);

        // dbDelta is idempotent: creates missing tables and adds missing columns.
        self::create_tables();

        foreach (self::migrations() as $version => $migration) {
            if ($version > $db_version) {
                call_user_func($migration);
            }
        }

        update_option('focmp_db_version', self::DB_VERSION);
        self::set_version();
        delete_transient('focmp_upgrading');
    }

    /**
     * Data migrations keyed by the DB_VERSION that introduced them. Each step must
     * be idempotent. Schema changes go into create_tables() (dbDelta); use a step
     * here only for data (e.g. rewriting template config JSON).
     *
     * @return array<int, callable>
     */
    private static function migrations() {
        return array(
            // 2: cmp_consent_log is created by create_tables(); nothing to migrate.
            2 => '__return_true',
            // 3: record_id column + unique key come from create_tables(); dbDelta does
            // not change existing indexes, so widen consent_id here.
            3 => array(__CLASS__, 'migrate_consent_id_index'),
            // 4: payload/stat tables come from create_tables(); fill the statistics
            // from the rows logged so far.
            4 => array(__CLASS__, 'migrate_consent_log_v4'),
            // 5: public_key column comes from create_tables(); give existing domains a key.
            5 => array(__CLASS__, 'assign_domain_keys'),
        );
    }

    /**
     * Public snippet key for every domain that has none (domains created before
     * DB version 5). Idempotent.
     */
    public static function assign_domain_keys() {
        global $wpdb;
        require_once __DIR__ . '/class-domain-access.php';
        $table = $wpdb->prefix . 'cmp_domains';
        $ids = $wpdb->get_col("SELECT id FROM $table WHERE public_key IS NULL OR public_key = ''");
        foreach ($ids as $id) {
            $wpdb->update($table, array('public_key' => FOCMP_Domain_Access::generate_key()), array('id' => (int) $id));
        }
    }

    /**
     * Schema 4 details dbDelta cannot apply to existing tables: ASCII UUID columns
     * (index entries ~3x smaller than utf8mb4) and the slot column in the stats
     * primary key (counters spread over 16 rows instead of one hot row). Then the
     * statistics are rebuilt from the log.
     */
    public static function migrate_consent_log_v4() {
        global $wpdb;
        $log = $wpdb->prefix . 'cmp_consent_log';
        $stats = $wpdb->prefix . 'cmp_consent_stats';

        $charset = $wpdb->get_var($wpdb->prepare(
            "SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'consent_id'",
            $log
        ));
        if ($charset && $charset !== 'ascii') {
            $wpdb->query("ALTER TABLE $log
                MODIFY consent_id char(36) CHARACTER SET ascii NOT NULL,
                MODIFY record_id char(36) CHARACTER SET ascii DEFAULT NULL");
        }

        $pk = $wpdb->get_col($wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX",
            $stats
        ));
        if (!in_array('slot', $pk, true)) {
            $has_slot = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'slot'",
                $stats
            ));
            if (!$has_slot) {
                $wpdb->query("ALTER TABLE $stats ADD slot tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER action");
            }
            $wpdb->query("ALTER TABLE $stats DROP PRIMARY KEY, ADD PRIMARY KEY (day, domain_id, action, slot)");
        }

        self::backfill_consent_stats();
    }

    /**
     * Daily decision counts for the rows logged so far (slot 0), replacing any
     * previous counts.
     */
    public static function backfill_consent_stats() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}cmp_consent_stats");
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}cmp_consent_stats (day, domain_id, action, slot, total)
             SELECT DATE(created_at), domain_id, action, 0, COUNT(*) FROM {$wpdb->prefix}cmp_consent_log
             GROUP BY DATE(created_at), domain_id, action"
        );
    }

    /**
     * consent_id index → (consent_id, created_at), used by the per-visitor limit.
     */
    public static function migrate_consent_id_index() {
        global $wpdb;
        $table = $wpdb->prefix . 'cmp_consent_log';
        $columns = $wpdb->get_col($wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'consent_id' ORDER BY SEQ_IN_INDEX",
            $table
        ));
        if ($columns !== array('consent_id', 'created_at')) {
            if ($columns) {
                $wpdb->query("ALTER TABLE $table DROP INDEX consent_id");
            }
            $wpdb->query("ALTER TABLE $table ADD INDEX consent_id (consent_id, created_at)");
        }
    }

    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Companies (Corki) table
        $table_companies = $wpdb->prefix . 'cmp_companies';
        $sql_companies = "CREATE TABLE $table_companies (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($sql_companies);

        // Domains table
        $table_domains = $wpdb->prefix . 'cmp_domains';
        $sql_domains = "CREATE TABLE $table_domains (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL,
            domain varchar(255) NOT NULL,
            prod_template_id bigint(20) unsigned DEFAULT NULL,
            test_template_id bigint(20) unsigned DEFAULT NULL,
            current_reconsent_id bigint(20) unsigned DEFAULT NULL,
            public_key varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY public_key (public_key),
            KEY company_id (company_id),
            KEY domain (domain)
        ) $charset_collate;";

        dbDelta($sql_domains);

        // Templates table
        $table_templates = $wpdb->prefix . 'cmp_templates';
        $sql_templates = "CREATE TABLE $table_templates (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            language varchar(10) NOT NULL DEFAULT 'pl',
            vendor_list_id bigint(20) unsigned DEFAULT NULL,
            config longtext NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY language (language),
            KEY vendor_list_id (vendor_list_id)
        ) $charset_collate;";

        dbDelta($sql_templates);

        // Reconsents table
        $table_reconsents = $wpdb->prefix . 'cmp_reconsents';
        $sql_reconsents = "CREATE TABLE $table_reconsents (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            domain_id bigint(20) unsigned NOT NULL,
            title varchar(255) NOT NULL,
            description text,
            reconsent_timestamp bigint(20) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY domain_id (domain_id),
            KEY reconsent_timestamp (reconsent_timestamp)
        ) $charset_collate;";

        dbDelta($sql_reconsents);

        // IAB Vendors table
        $table_vendors_iab = $wpdb->prefix . 'cmp_vendors_iab';
        $sql_vendors_iab = "CREATE TABLE $table_vendors_iab (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            vendor_id int(10) unsigned NOT NULL,
            name varchar(255) NOT NULL,
            data longtext NOT NULL,
            is_enabled tinyint(1) NOT NULL DEFAULT 1,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY vendor_id (vendor_id),
            KEY is_enabled (is_enabled)
        ) $charset_collate;";

        dbDelta($sql_vendors_iab);

        // Vendor Lists table
        $table_vendor_lists = $wpdb->prefix . 'cmp_vendor_lists';
        $sql_vendor_lists = "CREATE TABLE $table_vendor_lists (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            description text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        dbDelta($sql_vendor_lists);

        // Vendor List Entries (per-list vendor overrides)
        $table_vendor_list_entries = $wpdb->prefix . 'cmp_vendor_list_entries';
        $sql_vendor_list_entries = "CREATE TABLE $table_vendor_list_entries (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            vendor_list_id bigint(20) unsigned NOT NULL,
            vendor_id int(10) unsigned NOT NULL,
            vendor_type enum('iab','google','custom') NOT NULL DEFAULT 'iab',
            is_enabled tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY list_vendor (vendor_list_id, vendor_id, vendor_type),
            KEY vendor_list_id (vendor_list_id)
        ) $charset_collate;";

        dbDelta($sql_vendor_list_entries);

        // Consent log (proof of consent, GDPR Art. 7(1)): one row per decision
        $table_consent_log = $wpdb->prefix . 'cmp_consent_log';
        $sql_consent_log = "CREATE TABLE $table_consent_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            consent_id char(36) CHARACTER SET ascii NOT NULL,
            record_id char(36) CHARACTER SET ascii DEFAULT NULL,
            payload_id bigint(20) unsigned DEFAULT NULL,
            domain_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            action varchar(20) NOT NULL,
            mode varchar(10) NOT NULL,
            template_id bigint(20) unsigned DEFAULT NULL,
            env varchar(4) NOT NULL DEFAULT 'prod',
            policy_version smallint(5) unsigned DEFAULT NULL,
            gvl_version int(10) unsigned DEFAULT NULL,
            cmp_version smallint(5) unsigned DEFAULT NULL,
            reconsent_id bigint(20) unsigned DEFAULT NULL,
            tc_string text,
            ac_string text,
            custom_consents varchar(1000) DEFAULT NULL,
            custom_vendors text,
            page_url varchar(500) DEFAULT NULL,
            ip_anon varchar(45) DEFAULT NULL,
            user_agent varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY record_id (record_id),
            KEY consent_id (consent_id, created_at),
            KEY domain_created (domain_id, created_at),
            KEY created_at (created_at),
            KEY payload_id (payload_id)
        ) $charset_collate;";

        dbDelta($sql_consent_log);

        // Consent payloads, stored once: most decisions are identical (same TC
        // String within a day, same AC string and vendor lists), so log rows only
        // reference them. The legacy text columns of cmp_consent_log stay NULL.
        $table_consent_payloads = $wpdb->prefix . 'cmp_consent_payloads';
        $sql_consent_payloads = "CREATE TABLE $table_consent_payloads (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            hash char(64) NOT NULL,
            tc_string text,
            ac_string text,
            custom_consents varchar(1000) DEFAULT NULL,
            custom_vendors text,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY hash (hash),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql_consent_payloads);

        // Decisions per day, domain and action (statistics without scanning the log)
        $table_consent_stats = $wpdb->prefix . 'cmp_consent_stats';
        $sql_consent_stats = "CREATE TABLE $table_consent_stats (
            day date NOT NULL,
            domain_id bigint(20) unsigned NOT NULL,
            action varchar(20) NOT NULL,
            slot tinyint(3) unsigned NOT NULL DEFAULT 0,
            total int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (day, domain_id, action, slot)
        ) $charset_collate;";

        dbDelta($sql_consent_stats);

        // Drop old template_vendors table if it exists (was never populated)
        $old_table = $wpdb->prefix . 'cmp_template_vendors';
        $wpdb->query("DROP TABLE IF EXISTS $old_table");

        // Google Vendors table
        $table_vendors_google = $wpdb->prefix . 'cmp_vendors_google';
        $sql_vendors_google = "CREATE TABLE $table_vendors_google (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider_id int(10) unsigned NOT NULL,
            provider_name varchar(255) NOT NULL,
            privacy_url varchar(500) DEFAULT NULL,
            is_enabled tinyint(1) NOT NULL DEFAULT 1,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY provider_id (provider_id),
            KEY is_enabled (is_enabled)
        ) $charset_collate;";

        dbDelta($sql_vendors_google);

        // Custom Vendors table
        $table_vendors_custom = $wpdb->prefix . 'cmp_vendors_custom';
        $sql_vendors_custom = "CREATE TABLE $table_vendors_custom (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            privacy_url varchar(500) DEFAULT NULL,
            category varchar(50) NOT NULL DEFAULT 'other',
            is_enabled tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY category (category),
            KEY is_enabled (is_enabled)
        ) $charset_collate;";

        dbDelta($sql_vendors_custom);

        // IAB Purposes table
        $table_purposes_iab = $wpdb->prefix . 'cmp_purposes_iab';
        $sql_purposes_iab = "CREATE TABLE $table_purposes_iab (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            purpose_id int(10) unsigned NOT NULL,
            language varchar(10) NOT NULL DEFAULT 'pl',
            data longtext NOT NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY purpose_lang (purpose_id, language),
            KEY language (language)
        ) $charset_collate;";

        dbDelta($sql_purposes_iab);

        // Custom Consents table
        $table_custom_consents = $wpdb->prefix . 'cmp_custom_consents';
        $sql_custom_consents = "CREATE TABLE $table_custom_consents (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            template_id bigint(20) unsigned DEFAULT NULL,
            title varchar(255) NOT NULL,
            description text NOT NULL,
            legitimate_interest_allowed tinyint(1) NOT NULL DEFAULT 0,
            sort_order int(10) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY template_id (template_id),
            KEY sort_order (sort_order)
        ) $charset_collate;";

        dbDelta($sql_custom_consents);

        // GVL Metadata table (for storing imported GVL data)
        $table_gvl_meta = $wpdb->prefix . 'cmp_gvl_metadata';
        $sql_gvl_meta = "CREATE TABLE $table_gvl_meta (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            meta_key varchar(100) NOT NULL,
            language varchar(10) NOT NULL DEFAULT 'en',
            data longtext NOT NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY meta_key_lang (meta_key, language)
        ) $charset_collate;";

        dbDelta($sql_gvl_meta);
    }

    /**
     * Create default plugin options
     */
    private static function create_default_options() {
        // Default CMP settings
        $default_settings = array(
            'cmp_id' => 28,
            'cmp_version' => 1,
            'tcf_policy_version' => FOCMP_TCF::POLICY_VERSION,
            'publisher_country_code' => 'PL',
            'default_language' => 'pl',
            'cookie_name' => FOCMP_TCF::COOKIE_NAME,
            'cookie_expiration_days' => 180,
            'cache_ttl' => 60,
            'gvl_url' => FOCMP_TCF::GVL_URL,
            'google_atp_url' => 'https://storage.googleapis.com/tcfac/additional-consent-providers.csv',
            // Stack 42 groups purposes 2-10 (personalized ads & content, measurement, audience research)
            'enabled_stacks' => array(42),
        );

        add_option('focmp_settings', $default_settings);

        // Default texts (Polish)
        $default_texts = array(
            'pl' => array(
                'firstLayer' => array(
                    'title' => 'Zarządzanie prywatnością',
                    'description' => 'Strona używa plików cookies, aby ułatwić Ci korzystanie z serwisu oraz w celach statystycznych. Pamiętaj, że w każdej chwili możesz zmienić ustawienia prywatności w swojej przeglądarce. Szanujemy Twoją prywatność, dlatego też dajemy Ci możliwość wyboru, czy i jak chcesz, aby Twoje dane były wykorzystywane.',
                    'partnersLinkText' => 'Lista Zaufanych Partnerów',
                    'settingsButtonText' => 'Ustawienia zaawansowane',
                    'acceptAllButtonText' => 'Akceptuję'
                ),
                'secondLayer' => array(
                    'title' => 'Ustawienia zaawansowane',
                    'description' => 'Poniżej możesz dostosować ustawienia dotyczące przetwarzania danych.',
                    'partnersLinkText' => 'Lista Zaufanych Partnerów',
                    'backButtonText' => 'wstecz',
                    'preferencesTitle' => 'Zarządzaj preferencjami zgody',
                    'confirmButtonText' => 'Potwierdzam moje wybory',
                    'rejectButtonText' => 'Nie akceptuję',
                    'acceptAllButtonText' => 'Akceptuję bez zmian'
                ),
                'thirdLayer' => array(
                    'title' => 'Lista zaufanych partnerów',
                    'description' => 'Poniżej znajduje się lista zaufanych partnerów, którzy mogą przetwarzać Twoje dane osobowe.',
                    'backButtonText' => 'wstecz',
                    'confirmButtonText' => 'Potwierdzam moje wybory',
                    'rejectButtonText' => 'Nie akceptuję',
                    'acceptAllButtonText' => 'Akceptuję bez zmian',
                    'privacyPolicyText' => 'Wyświetl politykę prywatności',
                    'legitimateInterestText' => 'Wyświetl oświadczenie dotyczące prawnie uzasadnionego interesu',
                    'objectButtonText' => 'Zgłoś sprzeciw wobec prawnie uzasadnionych interesów',
                    'alwaysActiveText' => 'Zawsze aktywne',
                    'vendorGroups' => array(
                        'iab' => 'Zaufani Partnerzy IAB',
                        'google' => 'Partnerzy Google',
                        'other' => 'Inni partnerzy'
                    )
                ),
                'common' => array(
                    'exampleUseText' => 'Przyklad uzycia',
                    'objectButtonText' => 'Zglos sprzeciw wobec prawnie uzasadnionych interesów'
                )
            )
        );

        add_option('focmp_texts', $default_texts);
    }

    /**
     * Set plugin version in database
     */
    private static function set_version() {
        update_option('focmp_version', FOCMP_VERSION);
    }
}
