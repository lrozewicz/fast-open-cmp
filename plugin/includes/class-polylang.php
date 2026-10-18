<?php
/**
 * Polylang Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_Polylang {

    /**
     * Initialize Polylang integration
     */
    public function init() {
        add_filter('focmp_detect_language', array($this, 'detect_language'));
        add_filter('focmp_available_languages', array($this, 'get_languages'));
    }

    /**
     * Detect current language using Polylang
     *
     * @param string $default Default language
     * @return string Language code
     */
    public function detect_language($default) {
        if (function_exists('pll_current_language')) {
            $lang = pll_current_language('slug');
            if ($lang) {
                return $lang;
            }
        }

        return $default;
    }

    /**
     * Get available languages from Polylang
     *
     * @param array $languages Default languages
     * @return array Available languages
     */
    public function get_languages($languages) {
        if (function_exists('pll_languages_list')) {
            $pll_languages = pll_languages_list(array('fields' => 'slug'));
            if (!empty($pll_languages)) {
                return $pll_languages;
            }
        }

        return $languages;
    }

    /**
     * Get language-specific template ID for a domain
     *
     * @param int $domain_id Domain ID
     * @param string $lang Language code
     * @return int|null Template ID
     */
    public static function get_template_for_language($domain_id, $lang) {
        global $wpdb;

        // First try to find a template with specific language
        $domains_table = $wpdb->prefix . 'cmp_domains';
        $templates_table = $wpdb->prefix . 'cmp_templates';

        $domain = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $domains_table WHERE id = %d",
            $domain_id
        ));

        if (!$domain) {
            return null;
        }

        // Check if prod template has the requested language
        if ($domain->prod_template_id) {
            $template = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $templates_table WHERE id = %d",
                $domain->prod_template_id
            ));

            if ($template && $template->language === $lang) {
                return $domain->prod_template_id;
            }
        }

        // Try to find any template with the requested language for this domain's company
        // This allows having multiple templates per language for a company
        $company_templates = $wpdb->get_results($wpdb->prepare(
            "SELECT t.* FROM $templates_table t
             INNER JOIN $domains_table d ON (d.prod_template_id = t.id OR d.test_template_id = t.id)
             WHERE d.company_id = %d AND t.language = %s
             LIMIT 1",
            $domain->company_id,
            $lang
        ));

        if (!empty($company_templates)) {
            return $company_templates[0]->id;
        }

        // Fallback to default prod template
        return $domain->prod_template_id;
    }
}
