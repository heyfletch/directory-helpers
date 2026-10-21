<?php
/**
 * Owner Requests: text helpers (pure PHP, no WordPress calls, unit-tested).
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Text {

    /** Marker that replaces a removed link, phone or email in owner free text. */
    const REMOVED = '[removed]';

    /**
     * Free text the way a trainer will see it: links, emails and phone numbers
     * stripped, whitespace tidied, length capped.
     */
    public static function clean_problem( $text, $max = 1000 ) {
        $text = (string) $text;
        $text = html_entity_decode( self::strip_tags_safe( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = self::strip_contact( $text );
        $text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
        $text = preg_replace( '/[ \t]+/u', ' ', $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );
        $text = trim( $text );
        if ( mb_strlen( $text ) > $max ) {
            $text = rtrim( mb_substr( $text, 0, $max ) ) . '...';
        }
        return $text;
    }

    /**
     * Replace emails, links and phone numbers with a marker.
     */
    public static function strip_contact( $text ) {
        $text = (string) $text;
        // Spelled-out emails: "name at gmail dot com".
        $text = preg_replace( '/\b[\w.+-]+\s*(?:\(at\)|\[at\]|\sat\s)\s*[\w-]+\s*(?:\(dot\)|\[dot\]|\sdot\s|\.)\s*[a-z]{2,}\b/iu', self::REMOVED, $text );
        // Emails.
        $text = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', self::REMOVED, $text );
        // Links with a scheme or www.
        $text = preg_replace( '#\b(?:https?://|ftp://|www\.)[^\s<>"\']+#iu', self::REMOVED, $text );
        // Bare domains such as example.com/page (common TLDs only, so "e.g." and "Mr." survive).
        $text = preg_replace( '#\b[a-z0-9][a-z0-9\-]*\.(?:com|net|org|io|co|us|info|biz|me|app|dev|xyz|online|site|shop|store|ly|gl|link)\b(?:/[^\s<>"\']*)?#iu', self::REMOVED, $text );
        // US phone numbers in common shapes, then any long digit run.
        $text = preg_replace( '/(?<!\d)(?:\+?1[\s.\-]?)?(?:\(\d{3}\)|\d{3})[\s.\-]?\d{3}[\s.\-]?\d{4}(?!\d)/u', self::REMOVED, $text );
        $text = preg_replace( '/(?<!\d)\d{3}[\s.\-]\d{4}(?!\d)/u', self::REMOVED, $text );
        $text = preg_replace( '/(?<!\d)\d{9,}(?!\d)/u', self::REMOVED, $text );
        // Collapse repeated markers.
        $text = preg_replace( '/(?:\[removed\][\s,;:]*){2,}/u', self::REMOVED . ' ', $text );
        return $text;
    }

    /**
     * True when the text carries a link, email or phone number.
     */
    public static function has_contact( $text ) {
        return self::strip_contact( $text ) !== (string) $text;
    }

    /**
     * Lowercase and trim an email; '' when it is not a plausible address.
     */
    public static function normalize_email( $email ) {
        $email = strtolower( trim( (string) $email ) );
        if ( strlen( $email ) > 254 || ! preg_match( '/^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/', $email ) ) {
            return '';
        }
        return $email;
    }

    public static function email_domain( $email ) {
        $at = strrpos( (string) $email, '@' );
        return false === $at ? '' : strtolower( substr( $email, $at + 1 ) );
    }

    /**
     * Digits only, for a 5-digit US ZIP; '' when it is not one.
     */
    public static function normalize_zip( $zip ) {
        $zip = trim( (string) $zip );
        if ( preg_match( '/^(\d{5})(?:-\d{4})?$/', $zip, $m ) ) {
            return $m[1];
        }
        return '';
    }

    /**
     * First name for display: letters, spaces, hyphens, apostrophes; capped.
     */
    public static function clean_name( $name ) {
        $name = trim( (string) $name );
        $name = preg_replace( "/[^\p{L}\p{M}' \-.]/u", '', $name );
        $name = preg_replace( '/\s+/u', ' ', $name );
        return mb_substr( trim( $name ), 0, 40 );
    }

    /**
     * Phone: keep digits and a leading plus; '' when too short.
     */
    public static function clean_phone( $phone ) {
        $phone = trim( (string) $phone );
        $keep  = preg_replace( '/[^\d+]/', '', $phone );
        $digits = preg_replace( '/\D/', '', $keep );
        if ( strlen( $digits ) < 10 || strlen( $digits ) > 15 ) {
            return '';
        }
        return $keep;
    }

    /**
     * Words in the text, lowercase, for keyword checks.
     */
    public static function words( $text ) {
        preg_match_all( "/[a-z0-9']+/", strtolower( (string) $text ), $m );
        return $m[0];
    }

    /**
     * strip_tags that works with or without WordPress loaded (the unit tests load this file alone).
     */
    private static function strip_tags_safe( $text ) {
        if ( function_exists( 'wp_strip_all_tags' ) ) {
            return wp_strip_all_tags( $text );
        }
        return trim( strip_tags( (string) $text ) );
    }
}
