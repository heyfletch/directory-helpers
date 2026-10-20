<?php
/**
 * Link Status Helper Class
 *
 * Pure status-to-behavior decisions shared by the WP-CLI link commands.
 * No WordPress calls: safe to unit test with plain PHP.
 *
 * @package Directory_Helpers
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class DH_Link_Status {

    /**
     * Bucket a raw HTTP status code.
     *
     * @param int $code HTTP status code (0 for connection failure)
     * @return string ok|blocked|broken|unknown
     */
    public static function classify($code) {
        $code = (int) $code;
        if ($code >= 200 && $code < 400) {
            return 'ok';
        }
        if (in_array($code, array(401, 403, 429, 999), true)) {
            return 'blocked';
        }
        if ($code === 0 || $code === 404 || $code === 410 || ($code >= 500 && $code < 600)) {
            return 'broken';
        }
        return 'unknown';
    }

    /**
     * Whether a status code renders as a link in render_shortcoded_link().
     *
     * @param int $code HTTP status code (0 for connection failure)
     * @return bool
     */
    public static function renders_as_link($code) {
        $code = (int) $code;
        return !($code === 404 || $code === 0 || ($code >= 500 && $code < 600));
    }

    /**
     * Effective status code: an unexpired override wins over the stored code.
     *
     * @param int $status_code Stored status code
     * @param int|null $override_code Override code, if any
     * @param string|null $override_expires Override expiry (MySQL datetime)
     * @param string $now Current time (MySQL datetime, same clock as $override_expires)
     * @return int
     */
    public static function effective_code($status_code, $override_code, $override_expires, $now) {
        $base = (int) $status_code;
        $ov_code = $override_code === null ? 0 : (int) $override_code;
        $ov_exp = ($override_expires === null || $override_expires === '') ? 0 : strtotime((string) $override_expires);
        if ($ov_code && $ov_exp && $ov_exp > strtotime((string) $now)) {
            return $ov_code;
        }
        return $base;
    }

    /**
     * Whether a link needs a recheck: never checked, older than the cutoff,
     * or currently sitting in the broken bucket (transient failures retry every run).
     *
     * @param string|null $last_checked Last check time (MySQL datetime)
     * @param int $older_than_days Cutoff in days
     * @param string $now Current time (MySQL datetime, same clock as $last_checked)
     * @param int $code Last known status code
     * @return bool
     */
    public static function needs_recheck($last_checked, $older_than_days, $now, $code) {
        if ($last_checked === null || $last_checked === '') {
            return true;
        }
        $checked = strtotime((string) $last_checked);
        if ($checked === false) {
            return true;
        }
        if ($checked < strtotime((string) $now) - ((int) $older_than_days * 86400)) {
            return true;
        }
        return self::classify($code) === 'broken';
    }
}
