<?php
/**
 * Owner Requests: Gate A, the instant checks, and form data parsing.
 *
 * Turnstile and the honeypot run inside Fluent Forms before this code. Gate A
 * adds: a mailbox domain that can receive mail (MX lookup) and is not on the
 * CC0 disposable-domain list, a real US ZIP (Census table), text length with
 * links, emails and phone numbers stripped, and the rate caps per email, IP
 * and ZIP Joe approved.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Gates {

    const FORMATS = array( 'any', 'home', 'group', 'board', 'video' );
    const AGES    = array( 'puppy', 'adolescent', 'adult', 'senior' );

    /** The exact consent line on the form. The text a request stores is this string. */
    const CONSENT_TEXT = 'Send my request to up to five trainers near me. A trainer who opens my request sees my first name, my email, and my phone if I gave one, so they can reply. I can delete my request at any time.';

    private static $disposable = null;

    /**
     * The kinds of training an owner ticked, stored comma-separated in form order
     * ("home,group"). Nothing ticked, or only unknown values, is "any".
     */
    public static function formats( $raw ) {
        $picked = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
        $picked = array_map( function ( $v ) {
            return strtolower( preg_replace( '/[^a-z_\-]/i', '', (string) $v ) );
        }, $picked );
        $picked = array_values( array_intersect( array_diff( self::FORMATS, array( 'any' ) ), $picked ) );
        return $picked ? implode( ',', $picked ) : 'any';
    }

    /** Real client IP as the server sees it. */
    public static function client_ip() {
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
    }

    /**
     * Normalise the submitted fields. No checks here; Gate A runs on the result.
     */
    public static function parse( array $data ) {
        $g = function ( $k, $d = '' ) use ( $data ) {
            return isset( $data[ $k ] ) ? $data[ $k ] : $d;
        };

        $chips = $g( 'problem_chips', array() );
        $chips = is_array( $chips ) ? $chips : array( $chips );
        $chips = array_values( array_intersect( array_map( 'sanitize_key', $chips ), DH_OR_Screen::SPECIALTIES ) );

        $consent = $g( 'consent' );

        $age = sanitize_key( $g( 'dog_age' ) );
        $age = in_array( $age, self::AGES, true ) ? $age : '';

        $format = self::formats( $g( 'format', array() ) );

        $video_ok = $g( 'video_ok', array() );
        $video_ok = 'video' === $format || in_array( 'yes', (array) $video_ok, true );

        $zip = DH_OR_Text::normalize_zip( $g( 'zip' ) );

        // Where the form was submitted from. The embed post id is Fluent Forms' own field;
        // anything we cannot verify as a city or profile page becomes "other".
        $post_id = (int) $g( '__fluent_form_embded_post_id', (int) $g( 'source_post_id' ) );
        $type    = 'other';
        $viewed  = 0;
        if ( $post_id ) {
            $pt = get_post_type( $post_id );
            if ( 'publish' === get_post_status( $post_id ) && 'city-listing' === $pt ) {
                $type = 'city';
            } elseif ( 'publish' === get_post_status( $post_id ) && 'profile' === $pt ) {
                $type   = 'profile';
                $viewed = $post_id;
            } else {
                $post_id = 0;
            }
        }
        if ( ! $viewed && (int) $g( 'viewed_profile_id' ) ) {
            $v = (int) $g( 'viewed_profile_id' );
            if ( 'profile' === get_post_type( $v ) && 'publish' === get_post_status( $v ) ) {
                $viewed = $v;
            }
        }

        $utm = function ( $k ) use ( $g ) {
            return mb_substr( preg_replace( '/[^A-Za-z0-9._\- ]/', '', (string) $g( $k ) ), 0, 100 );
        };

        return array(
            'first_name'        => DH_OR_Text::clean_name( $g( 'first_name' ) ),
            'email'             => DH_OR_Text::normalize_email( $g( 'email' ) ),
            'phone_raw'         => trim( (string) $g( 'phone' ) ),
            'phone'             => DH_OR_Text::clean_phone( $g( 'phone' ) ),
            'zip'               => $zip,
            'problem'           => DH_OR_Text::clean_problem( $g( 'problem' ), (int) DH_OR_Settings::get( 'text_max' ) ),
            'problem_len_raw'   => mb_strlen( trim( wp_strip_all_tags( (string) $g( 'problem' ) ) ) ),
            'chips'             => $chips,
            'dog_age'           => $age,
            'format'            => $format,
            'video_ok'          => $video_ok,
            'source_post_id'    => $post_id,
            'source_type'       => $type,
            'viewed_profile_id' => $viewed,
            'utm_source'        => $utm( 'utm_source' ),
            'utm_medium'        => $utm( 'utm_medium' ),
            'utm_campaign'      => $utm( 'utm_campaign' ),
            'consent'           => ! in_array( strtolower( trim( (string) ( is_array( $consent ) ? reset( $consent ) : $consent ) ) ), array( '', '0', 'false', 'no', 'off' ), true ),
        );
    }

    /**
     * Gate A. Returns field => message for each failure (empty when clear).
     */
    public static function check( array $p, $ip ) {
        $errors = array();

        if ( DH_OR_Settings::paused() ) {
            return array( 'problem' => 'Requests are paused right now. Please try again later.' );
        }

        // Text: what is left after links, emails and phone numbers come out.
        $left = trim( preg_replace( '/\[removed\]/', '', $p['problem'] ) );
        if ( mb_strlen( $left ) < (int) DH_OR_Settings::get( 'text_min' ) ) {
            $errors['problem'] = 'Please tell us a little more about what is going on with your dog. Links and phone numbers are removed from this box.';
        }

        if ( '' === $p['first_name'] ) {
            $errors['first_name'] = 'Please enter your first name.';
        }

        // The form requires the consent box; the module does not rely on that alone.
        if ( empty( $p['consent'] ) ) {
            $errors['consent'] = 'Please tick the box so we can send your request.';
        }

        // Email: valid shape, not disposable, a domain that can receive mail.
        if ( '' === $p['email'] ) {
            $errors['email'] = 'Please enter a valid email address.';
        } else {
            $domain = DH_OR_Text::email_domain( $p['email'] );
            if ( self::is_disposable( $domain ) ) {
                $errors['email'] = 'Please use an email address you check often. Temporary addresses do not work here.';
            } elseif ( ! self::domain_can_receive( $domain ) ) {
                $errors['email'] = 'We could not find a mailbox for that email domain. Please check the spelling.';
            }
        }

        if ( '' !== $p['phone_raw'] && '' === $p['phone'] ) {
            $errors['phone'] = 'That phone number does not look right. Leave it blank if you prefer.';
        }

        // ZIP.
        if ( '' === $p['zip'] ) {
            $errors['zip'] = 'Please enter a 5-digit US ZIP code.';
        } elseif ( ! DH_OR_Schema::zip_point( $p['zip'] ) ) {
            $errors['zip'] = 'We could not find that ZIP code. Please check it.';
        }

        // Rate caps (Joe's approved numbers, plus a per-ZIP cap that stays off until he sets one).
        if ( empty( $errors['email'] ) && '' !== $p['email'] ) {
            $day  = (int) DH_OR_Settings::get( 'owner_email_day' );
            $week = (int) DH_OR_Settings::get( 'owner_email_week' );
            if ( $day > 0 && DH_OR_Store::count_since( 'owner_email', $p['email'], DAY_IN_SECONDS ) >= $day ) {
                $errors['email'] = 'We can take one request per email address a day. Please try again tomorrow.';
            } elseif ( $week > 0 && DH_OR_Store::count_since( 'owner_email', $p['email'], 7 * DAY_IN_SECONDS ) >= $week ) {
                $errors['email'] = 'We can take ' . $week . ' requests per email address a week. Please try again later.';
            }
        }
        $ip_cap = (int) DH_OR_Settings::get( 'ip_day' );
        if ( $ip_cap > 0 && $ip && DH_OR_Store::count_since( 'ip_hash', DH_OR_Store::ip_hash( $ip ), DAY_IN_SECONDS ) >= $ip_cap ) {
            $errors['problem'] = 'Too many requests came from this connection today. Please try again tomorrow.';
        }
        $zip_cap = (int) DH_OR_Settings::get( 'zip_day' );
        if ( $zip_cap > 0 && '' !== $p['zip'] && DH_OR_Store::count_since( 'zip', $p['zip'], DAY_IN_SECONDS ) >= $zip_cap ) {
            $errors['zip'] = 'Too many requests came from this ZIP code today. Please try again tomorrow.';
        }

        return $errors;
    }

    /** True when the domain, or a parent of it, is on the disposable list. */
    public static function is_disposable( $domain ) {
        if ( null === self::$disposable ) {
            $file = dirname( __DIR__ ) . '/data/disposable-domains.txt';
            $list = is_readable( $file ) ? file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
            self::$disposable = array_flip( $list ? $list : array() );
        }
        $parts = explode( '.', strtolower( $domain ) );
        while ( count( $parts ) >= 2 ) {
            if ( isset( self::$disposable[ implode( '.', $parts ) ] ) ) {
                return true;
            }
            array_shift( $parts );
        }
        return false;
    }

    /** MX lookup, with the address record as the fallback RFC 5321 allows. Cached for an hour. */
    public static function domain_can_receive( $domain ) {
        $key    = 'dh_or_mx_' . md5( $domain );
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return 'y' === $cached;
        }
        $ok = false;
        if ( function_exists( 'checkdnsrr' ) ) {
            $ok = checkdnsrr( $domain . '.', 'MX' ) || checkdnsrr( $domain . '.', 'A' ) || checkdnsrr( $domain . '.', 'AAAA' );
        } else {
            $ok = true; // No DNS functions: do not block real owners.
        }
        set_transient( $key, $ok ? 'y' : 'n', HOUR_IN_SECONDS );
        return $ok;
    }
}
