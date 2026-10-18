<?php
/**
 * Minimal reader of the core segment of an IAB TCF v2 TC String, for human-readable
 * consent exports (which purposes / special features / vendors a person agreed to).
 *
 * Field layout: IAB Tech Lab "Consent string and vendor list formats v2", core
 * segment (docs/references.md). Only reads; the SDK writes strings with the official
 * IAB library.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_TC_Decoder {

    /** @var string bits of the core segment */
    private $bits = '';
    private $pos = 0;

    /**
     * Decodes the core segment. Returns null for anything that is not a v2 string.
     *
     * @return array|null {version, created, lastUpdated, cmpId, cmpVersion, consentLanguage,
     *                     vendorListVersion, policyVersion, isServiceSpecific, specialFeatures,
     *                     purposesConsent, purposesLegitimateInterest, publisherCountryCode,
     *                     vendorsConsent, vendorsLegitimateInterest}
     */
    public static function decode($tc_string) {
        $core = explode('.', (string) $tc_string)[0];
        if ($core === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $core)) {
            return null;
        }
        $decoder = new self();
        $decoder->bits = self::to_bits($core);
        try {
            return $decoder->read_core();
        } catch (OutOfRangeException $e) {
            return null;
        }
    }

    private static function to_bits($base64url) {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $bits = '';
        $length = strlen($base64url);
        for ($i = 0; $i < $length; $i++) {
            $bits .= str_pad(decbin(strpos($alphabet, $base64url[$i])), 6, '0', STR_PAD_LEFT);
        }
        return $bits;
    }

    private function int($length) {
        if ($this->pos + $length > strlen($this->bits)) {
            throw new OutOfRangeException('TC String too short');
        }
        $value = bindec(substr($this->bits, $this->pos, $length));
        $this->pos += $length;
        return (int) $value;
    }

    private function bool() {
        return $this->int(1) === 1;
    }

    /** Ids (1-based) of the set bits in a fixed-length bitfield. */
    private function bitfield($length) {
        $ids = array();
        for ($i = 1; $i <= $length; $i++) {
            if ($this->bool()) {
                $ids[] = $i;
            }
        }
        return $ids;
    }

    private function letters() {
        return chr(65 + $this->int(6)) . chr(65 + $this->int(6));
    }

    /** Vendor section: MaxVendorId, IsRangeEncoding, then a bitfield or ranges. */
    private function vendor_section() {
        $max = $this->int(16);
        if (!$this->bool()) {
            return $this->bitfield($max);
        }
        $ids = array();
        $entries = $this->int(12);
        for ($i = 0; $i < $entries; $i++) {
            $is_range = $this->bool();
            $start = $this->int(16);
            $end = $is_range ? $this->int(16) : $start;
            for ($id = $start; $id <= $end && $id <= $max; $id++) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function read_core() {
        $version = $this->int(6);
        if ($version !== 2) {
            return null;
        }
        $created = $this->int(36);
        $last_updated = $this->int(36);

        $data = array(
            'version' => $version,
            // deciseconds since the epoch
            'created' => gmdate('c', (int) floor($created / 10)),
            'lastUpdated' => gmdate('c', (int) floor($last_updated / 10)),
            'cmpId' => $this->int(12),
            'cmpVersion' => $this->int(12),
        );
        $this->int(6); // consent screen
        $data['consentLanguage'] = $this->letters();
        $data['vendorListVersion'] = $this->int(12);
        $data['policyVersion'] = $this->int(6);
        $data['isServiceSpecific'] = $this->bool();
        $this->bool(); // use non-standard texts
        $data['specialFeatures'] = $this->bitfield(12);
        $data['purposesConsent'] = $this->bitfield(24);
        $data['purposesLegitimateInterest'] = $this->bitfield(24);
        $this->bool(); // purpose one treatment
        $data['publisherCountryCode'] = $this->letters();
        $data['vendorsConsent'] = $this->vendor_section();
        $data['vendorsLegitimateInterest'] = $this->vendor_section();
        return $data;
    }
}
