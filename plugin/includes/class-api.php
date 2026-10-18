<?php
/**
 * REST API Endpoints
 */

if (!defined('ABSPATH')) {
    exit;
}


require_once __DIR__ . '/api/trait-endpoints.php';
require_once __DIR__ . '/api/trait-builder.php';
require_once __DIR__ . '/api/trait-data.php';
require_once __DIR__ . '/api/trait-consent-log.php';

class FOCMP_API {

    /**
     * API namespace
     */
    const NAMESPACE = 'fast-open-cmp/v1';

    /**
     * Cache instance
     */
    private $cache;

    use FOCMP_API_Endpoints, FOCMP_API_Builder, FOCMP_API_Data, FOCMP_API_ConsentLog;
}
