<?php
/**
 * Low-level DB accessors, default texts, and helpers.
 * Split out of class-api.php (todo §3). Composed into FOCMP_API via `use`.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait FOCMP_API_Data {
    /**
     * Get purposes from database
     */
    private function get_purposes_from_db($lang = 'en') {
        global $wpdb;

        $table = $wpdb->prefix . 'cmp_purposes_iab';
        $purposes_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT purpose_id, data FROM $table WHERE language = %s",
            $lang
        ), ARRAY_A);

        // If no results for requested language, try English
        if (empty($purposes_raw) && $lang !== 'en') {
            $purposes_raw = $wpdb->get_results(
                "SELECT purpose_id, data FROM $table WHERE language = 'en'",
                ARRAY_A
            );
        }

        $purposes = array();
        foreach ($purposes_raw as $p) {
            $data = json_decode($p['data'], true);
            if ($data) {
                $purposes[$p['purpose_id']] = $data;
            }
        }

        return $purposes;
    }

    /**
     * Get GVL metadata
     */
    private function get_gvl_meta($key, $lang = 'en') {
        global $wpdb;

        $table = $wpdb->prefix . 'cmp_gvl_metadata';
        $data = $wpdb->get_var($wpdb->prepare(
            "SELECT data FROM $table WHERE meta_key = %s AND language = %s",
            $key,
            $lang
        ));

        if ($data) {
            return json_decode($data, true);
        }

        // Try English as fallback
        if ($lang !== 'en') {
            $data = $wpdb->get_var($wpdb->prepare(
                "SELECT data FROM $table WHERE meta_key = %s AND language = 'en'",
                $key
            ));
            if ($data) {
                return json_decode($data, true);
            }
        }

        return null;
    }

    /**
     * Get vendor list version
     */
    private function get_vendor_list_version() {
        global $wpdb;

        $table = $wpdb->prefix . 'cmp_gvl_metadata';
        $version = $wpdb->get_var(
            "SELECT data FROM $table WHERE meta_key = 'vendorListVersion' LIMIT 1"
        );

        return $version ? (int) $version : 69;
    }

    /**
     * Get domain ID by domain name
     */
    private function get_domain_id_by_name($domain_name) {
        global $wpdb;

        $table = $wpdb->prefix . 'cmp_domains';
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE domain = %s",
            $domain_name
        ));
    }

    /**
     * Find a template authored in the given language for a domain (multilingual).
     *
     * Prefers the domain's own prod template when it already matches the language,
     * otherwise any template in that language used by a domain of the same company
     * (publishers author one template per language). Returns null when none
     * exists, so the caller keeps the base template and just overrides the
     * rendered language.
     */
    private function find_template_for_language($domain, $lang) {
        global $wpdb;
        $templates = $wpdb->prefix . 'cmp_templates';
        $domains = $wpdb->prefix . 'cmp_domains';

        if ($domain->prod_template_id) {
            $tpl = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $templates WHERE id = %d AND language = %s",
                $domain->prod_template_id,
                $lang
            ));
            if ($tpl) {
                return $tpl;
            }
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT t.* FROM $templates t
             INNER JOIN $domains d ON (d.prod_template_id = t.id OR d.test_template_id = t.id)
             WHERE d.company_id = %d AND t.language = %s
             LIMIT 1",
            $domain->company_id,
            $lang
        ));
    }


    /**
     * Merge template texts with defaults to ensure all fields exist
     */
    private function merge_texts($config, $lang) {
        $defaults = $this->get_default_texts($lang);

        if (!isset($config['texts']) || empty($config['texts'])) {
            return $defaults;
        }

        // An empty field in the template means "use the default text" — never an
        // empty button or heading in the consent box.
        $template_texts = $this->drop_empty_strings($config['texts']);

        // Deep merge: defaults + template (template overrides defaults)
        return $this->array_merge_recursive_distinct($defaults, $template_texts);
    }

    /**
     * Recursively removes empty-string values from an array.
     */
    private function drop_empty_strings(array $values) {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->drop_empty_strings($value);
            } elseif (is_string($value) && trim($value) === '') {
                unset($values[$key]);
            }
        }
        return $values;
    }

    /**
     * Recursive array merge that replaces values instead of creating arrays
     */
    private function array_merge_recursive_distinct(array $array1, array $array2) {
        $merged = $array1;

        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->array_merge_recursive_distinct($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Get default texts for language
     */
    private function get_default_texts($lang = 'pl') {
        // Hardcoded defaults that must always be present (per language)
        $hardcoded_defaults = $this->get_hardcoded_default_texts($lang);

        $texts = get_option('focmp_texts', array());

        // Publisher overrides for THIS language only. We must not fall back to
        // another language's option texts here — that would override the correct
        // localized hardcoded defaults (e.g. an EN box getting PL option texts).
        $option_texts = null;
        if (isset($texts[$lang]) && !empty($texts[$lang])) {
            $option_texts = $texts[$lang];
        }

        // Merge option texts with hardcoded defaults (hardcoded as base, option overrides)
        if ($option_texts) {
            return $this->array_merge_recursive_distinct($hardcoded_defaults, $option_texts);
        }

        return $hardcoded_defaults;
    }

    /**
     * Get hardcoded default texts (Polish)
     */
    private function get_hardcoded_default_texts($lang = 'pl') {
        $pl = array(
            'firstLayer' => array(
                'title' => 'Zarządzanie prywatnością',
                'description' => 'Strona używa plików cookies, aby ułatwić Ci korzystanie z serwisu oraz w celach statystycznych. Pamiętaj, że w każdej chwili możesz zmienić ustawienia prywatności w swojej przeglądarce. Szanujemy Twoją prywatność, dlatego też dajemy Ci możliwość wyboru, czy i jak chcesz, aby Twoje dane były wykorzystywane.',
                'partnersLinkText' => 'Lista Zaufanych Partnerów',
                'settingsButtonText' => 'Ustawienia zaawansowane',
                'acceptAllButtonText' => 'Akceptuję',
                'rejectAllButtonText' => 'Nie wyrażam zgody',
                'closeButtonLabel' => 'Zamknij i odrzuć',
                'privacyPolicyLinkText' => 'Polityka prywatności',
                'vendorCountText' => 'Korzystamy z usług {iab} Zaufanych Partnerów IAB oraz {other} pozostałych partnerów.',
                'purposesIntroText' => 'My i nasi Zaufani Partnerzy możemy przetwarzać dane osobowe w następujących celach:',
                'reopenButtonLabel' => 'Ustawienia prywatności',
            ),
            'secondLayer' => array(
                'title' => 'Ustawienia zaawansowane',
                'description' => 'Poniżej możesz dostosować ustawienia dotyczące przetwarzania danych. Możesz zdecydować, które cele przetwarzania akceptujesz, oraz którzy partnerzy mogą przetwarzać Twoje dane. Twoje wybory zostaną zapisane w formie zaszyfrowanego ciągu znaków w pliku cookie.',
                'partnersLinkText' => 'Lista Zaufanych Partnerów',
                'backButtonText' => 'wstecz',
                'preferencesTitle' => 'Zarządzaj preferencjami zgody',
                'confirmButtonText' => 'Potwierdzam moje wybory',
                'rejectButtonText' => 'Nie akceptuję',
                'acceptAllButtonText' => 'Akceptuję bez zmian',
            ),
            'thirdLayer' => array(
                'title' => 'Lista zaufanych partnerów',
                'description' => 'Poniżej znajduje się lista zaufanych partnerów, którzy mogą przetwarzać Twoje dane osobowe. Możesz zdecydować, którym partnerom chcesz udzielić zgody.',
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
                    'other' => 'Inni partnerzy',
                ),
                'categoriesText' => array(
                    'dataDeclaration' => 'Kategorie przetwarzanych danych',
                    'stdRetention' => 'Okresy przechowywania',
                    'cookieMaxAgeSeconds' => 'Maksymalny czas życia plików cookie w sekundach',
                    'purposes' => 'Cele zgody',
                    'specialPurposes' => 'Cele specjalne',
                    'features' => 'Funkcje',
                    'specialFeatures' => 'Funkcje specjalne',
                    'legIntPurposes' => 'Cele uzasadnionego interesu',
                ),
            ),
            'common' => array(
                'exampleUseText' => 'Przykład użycia',
                'objectButtonText' => 'Zgłoś sprzeciw wobec prawnie uzasadnionych interesów',
            ),
        );

        $en = array(
            'firstLayer' => array(
                'title' => 'Privacy management',
                'description' => 'This site uses cookies to make it easier for you to use the site and for statistical purposes. You can change your privacy settings in your browser at any time. We respect your privacy, so we give you a choice over whether and how your data is used.',
                'partnersLinkText' => 'List of Trusted Partners',
                'settingsButtonText' => 'Advanced settings',
                'acceptAllButtonText' => 'Accept',
                'rejectAllButtonText' => 'Reject all',
                'closeButtonLabel' => 'Close and reject',
                'privacyPolicyLinkText' => 'Privacy policy',
                'vendorCountText' => 'We work with {iab} IAB Trusted Partners and {other} other partners.',
                'purposesIntroText' => 'We and our Trusted Partners may process personal data for the following purposes:',
                'reopenButtonLabel' => 'Privacy settings',
            ),
            'secondLayer' => array(
                'title' => 'Advanced settings',
                'description' => 'Below you can adjust how your data is processed. You can decide which processing purposes you accept and which partners may process your data. Your choices are saved as an encrypted string in a cookie.',
                'partnersLinkText' => 'List of Trusted Partners',
                'backButtonText' => 'back',
                'preferencesTitle' => 'Manage consent preferences',
                'confirmButtonText' => 'Confirm my choices',
                'rejectButtonText' => 'Reject',
                'acceptAllButtonText' => 'Accept without changes',
            ),
            'thirdLayer' => array(
                'title' => 'List of trusted partners',
                'description' => 'Below is the list of trusted partners that may process your personal data. You can decide which partners to grant consent to.',
                'backButtonText' => 'back',
                'confirmButtonText' => 'Confirm my choices',
                'rejectButtonText' => 'Reject',
                'acceptAllButtonText' => 'Accept without changes',
                'privacyPolicyText' => 'View privacy policy',
                'legitimateInterestText' => 'View the legitimate interest statement',
                'objectButtonText' => 'Object to legitimate interests',
                'alwaysActiveText' => 'Always active',
                'vendorGroups' => array(
                    'iab' => 'Trusted IAB Partners',
                    'google' => 'Google Partners',
                    'other' => 'Other partners',
                ),
                'categoriesText' => array(
                    'dataDeclaration' => 'Categories of processed data',
                    'stdRetention' => 'Retention periods',
                    'cookieMaxAgeSeconds' => 'Maximum cookie lifetime in seconds',
                    'purposes' => 'Consent purposes',
                    'specialPurposes' => 'Special purposes',
                    'features' => 'Features',
                    'specialFeatures' => 'Special features',
                    'legIntPurposes' => 'Legitimate interest purposes',
                ),
            ),
            'common' => array(
                'exampleUseText' => 'Example use',
                'objectButtonText' => 'Object to legitimate interests',
            ),
        );

        return ($lang === 'en') ? $en : $pl;
    }

    /**
     * Build GVL URL with lang and optional stacks parameter
     */
    private function build_gvl_url($lang, $config, $settings) {
        $gvl_url = add_query_arg('lang', $lang, rest_url(self::NAMESPACE . '/vendor-list'));

        $enabled_stacks = isset($config['enabledStacks']) ? $config['enabledStacks'] : (isset($settings['enabled_stacks']) ? $settings['enabled_stacks'] : array(42));
        if (!empty($enabled_stacks)) {
            $gvl_url = add_query_arg('stacks', implode(',', $enabled_stacks), $gvl_url);
        }

        return $gvl_url;
    }

    /**
     * Filter stacks to only return enabled ones
     * Default: only stack 42 (like original CMP project)
     */
    private function filter_enabled_stacks($all_stacks, $override_stack_ids = null) {
        if (empty($all_stacks) || !is_array($all_stacks)) {
            return new stdClass();
        }

        if ($override_stack_ids !== null) {
            $enabled_stack_ids = $override_stack_ids;
        } else {
            $settings = get_option('focmp_settings', array());
            // Default to stack 42 if not configured (like original project)
            $enabled_stack_ids = isset($settings['enabled_stacks']) ? $settings['enabled_stacks'] : array(42);
        }

        if (empty($enabled_stack_ids)) {
            return new stdClass();
        }

        $filtered = array();
        foreach ($enabled_stack_ids as $stack_id) {
            $stack_id = (string) $stack_id;
            if (isset($all_stacks[$stack_id])) {
                $filtered[$stack_id] = $all_stacks[$stack_id];
            }
        }

        return !empty($filtered) ? $filtered : new stdClass();
    }

    /**
     * Add cache headers to response
     */
    private function add_cache_headers($response) {
        $settings = get_option('focmp_settings', array());
        $ttl = isset($settings['cache_ttl']) ? (int) $settings['cache_ttl'] : 60;

        $response->header('Cache-Control', 'public, max-age=' . $ttl);
        $response->header('Access-Control-Allow-Origin', '*');

        return $response;
    }
}
