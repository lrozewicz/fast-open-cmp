<?php
/**
 * WPML Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_WPML {

    /**
     * Register the language-detection filters (same contract as FOCMP_Polylang).
     */
    public function init() {
        add_filter('focmp_detect_language', array($this, 'detect_language'));
        add_filter('focmp_available_languages', array($this, 'get_languages'));
    }

    /**
     * Detect the current front-end language using WPML.
     *
     * @param string|null $default Fallback when WPML reports nothing.
     * @return string|null Language code (slug).
     */
    public function detect_language($default) {
        $lang = apply_filters('wpml_current_language', null);
        if (!$lang && defined('ICL_LANGUAGE_CODE')) {
            $lang = ICL_LANGUAGE_CODE;
        }
        return $lang ?: $default;
    }

    /**
     * Active WPML languages as a list of slugs.
     *
     * @param array $languages Default list.
     * @return array
     */
    public function get_languages($languages) {
        $active = apply_filters('wpml_active_languages', null);
        if (is_array($active) && !empty($active)) {
            return array_keys($active);
        }
        return $languages;
    }
}
