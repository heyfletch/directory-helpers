<?php
/**
 * Owner Requests: loads candidate trainers for the matcher.
 *
 * Candidates are published `profile` posts in the dog-trainer niche that are
 * not closed, restricted or store-chain listings, each tagged with the lane a
 * message to them would use:
 *   A  the trainer gave their own email (ACF contact_email)
 *   B  an email in the profile's contact_info data that nobody opted in
 *   C  a contact page only
 * Lanes A and B can be sent to in this build; C is loaded so the request log
 * can say how many more trainers it would add. A profile is lane B only when
 * one of its contact_info emails looks like the trainer's own and its mail
 * server answers.
 *
 * Emails are never put in the candidate array; send_address() reads the
 * address for a route's lane at send time.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Candidates {

    const NICHE = 'dog-trainer';

    /** Free mailbox domains: no mail server lookup needed. */
    const FREE_MAIL = array( 'gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com', 'msn.com', 'live.com', 'comcast.net', 'att.net', 'sbcglobal.net', 'verizon.net', 'ymail.com', 'rocketmail.com', 'protonmail.com', 'proton.me', 'bellsouth.net', 'cox.net', 'charter.net', 'earthlink.net', 'gmx.com', 'mail.com', 'aim.com' );

    /** Words in a business name too common to tie an address to one trainer. */
    const NAME_STOP = array( 'dogs', 'training', 'trainer', 'trainers', 'train', 'canine', 'canines', 'puppy', 'puppies', 'pups', 'academy', 'school', 'center', 'centre', 'services', 'service', 'behavior', 'behaviour', 'obedience', 'pets', 'club', 'company', 'professional', 'family', 'home', 'house', 'kennel', 'kennels', 'with', 'your', 'llc' );

    /** Placeholder and platform domains that never belong to a trainer. */
    const JUNK_DOMAINS = array( 'example.com', 'example.org', 'example.net', 'mysite.com', 'domain.com', 'yourdomain.com', 'sentry.io', 'wixpress.com', 'zendesk.com', 'squarespace.com', 'godaddy.com', 'wix.com', 'gamil.com', 'gmial.com', 'gmai.com', 'gnail.com', 'gmal.com', 'yaho.com', 'yahooo.com', 'hotmial.com', 'hotmai.com', 'outlok.com', 'linktr.ee', 'rover.com', 'facebook.com', 'instagram.com', 'yelp.com', 'thumbtack.com', 'google.com' );

    /** Certifications that count as a behavior credential for aggression and medical requests. */
    const BEHAVIOR_CERTS = array( 'CBCC-KA', 'IAABC-CDBC' );

    public static function behavior_certs() {
        return apply_filters( 'dh_or_behavior_certs', self::BEHAVIOR_CERTS );
    }

    /**
     * Trainers within $max_miles of a point, plus the viewed profile wherever it is.
     *
     * @return array[] candidate rows for DH_OR_Matcher::match()
     */
    public static function load( $lat, $lng, $max_miles, $viewed_id = 0, $video_anywhere = false ) {
        global $wpdb;

        $lat_delta = $max_miles / 69.0;
        $lng_delta = $max_miles / max( 1.0, 69.172 * cos( deg2rad( $lat ) ) );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} la ON la.post_id = p.ID AND la.meta_key = 'latitude'
             INNER JOIN {$wpdb->postmeta} lo ON lo.post_id = p.ID AND lo.meta_key = 'longitude'
             WHERE p.post_type = 'profile' AND p.post_status = 'publish'
               AND la.meta_value <> '' AND lo.meta_value <> ''
               AND CAST(la.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
               AND CAST(lo.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f",
            $lat - $lat_delta,
            $lat + $lat_delta,
            $lng - $lng_delta,
            $lng + $lng_delta
        ) );
        $ids = array_map( 'intval', $ids );

        if ( $video_anywhere ) {
            // Video trainers at any distance: only those a lane-A message could reach (their own email, or a Featured billing email).
            $far = $wpdb->get_col(
                "SELECT DISTINCT s.post_id FROM {$wpdb->postmeta} s
                 INNER JOIN {$wpdb->postmeta} e ON e.post_id = s.post_id AND e.meta_key IN ('contact_email', 'featured_billing_email') AND e.meta_value <> ''
                 INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id AND p.post_type = 'profile' AND p.post_status = 'publish'
                 WHERE s.meta_key = 'service_types' AND s.meta_value LIKE '%\"video\"%'"
            );
            $ids = array_merge( $ids, array_map( 'intval', $far ) );
        }
        if ( $viewed_id ) {
            $ids[] = (int) $viewed_id;
        }
        $ids = array_values( array_unique( $ids ) );
        if ( ! $ids ) {
            return array();
        }

        $excluded = class_exists( 'DH_Profile_Status_Notice' ) ? array_flip( DH_Profile_Status_Notice::excluded_profile_ids() ) : array();
        update_meta_cache( 'post', $ids );

        $b_ids = self::lane_b_ids( $ids );

        $out = array();
        foreach ( $ids as $id ) {
            if ( isset( $excluded[ $id ] ) ) {
                continue;
            }
            $post = get_post( $id );
            if ( ! $post || 'profile' !== $post->post_type || 'publish' !== $post->post_status || ! has_term( self::NICHE, 'niche', $id ) ) {
                continue;
            }
            $plat = get_post_meta( $id, 'latitude', true );
            $plng = get_post_meta( $id, 'longitude', true );
            $unplaced = false;
            if ( ! is_numeric( $plat ) || ! is_numeric( $plng ) ) {
                // No map location. Fine for the trainer whose page the owner is on (the owner chose them); nobody else can be placed.
                if ( (int) $viewed_id !== (int) $id ) {
                    continue;
                }
                $plat     = $lat;
                $plng     = $lng;
                $unplaced = true;
            }
            $lane     = '';
            $featured = (int) get_post_meta( $id, 'featured', true ) > 0;
            if ( '' !== self::trainer_email( $id ) ) {
                $lane = 'A';
            } elseif ( isset( $b_ids[ $id ] ) && '' !== self::lane_b_email( $id ) ) {
                $lane = 'B';
            } elseif ( '' !== trim( (string) get_post_meta( $id, 'contact_url', true ) ) ) {
                $lane = 'C';
            }
            if ( '' === $lane ) {
                continue;
            }
            $out[] = array(
                'id'          => $id,
                'name'        => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ),
                'url'         => (string) get_post_meta( $id, 'url', true ),
                'city'        => trim( (string) get_post_meta( $id, 'city', true ) . ', ' . (string) get_post_meta( $id, 'state', true ), ', ' ),
                'lat'         => (float) $plat,
                'lng'         => (float) $plng,
                'rating'      => (float) get_post_meta( $id, 'rating_value', true ),
                'reviews'     => (int) get_post_meta( $id, 'rating_votes_count', true ),
                'specialties' => self::meta_list( $id, 'program_specialties' ),
                'ages'        => self::meta_list( $id, 'dog_age' ),
                'services'    => self::meta_list( $id, 'service_types' ),
                'certs'       => self::meta_list( $id, 'certifications' ),
                'lane'        => $lane,
                'featured'    => $featured,
                'unplaced'    => $unplaced,
            );
        }
        return $out;
    }

    /** @return string[] */
    private static function meta_list( $id, $key ) {
        $v = get_post_meta( $id, $key, true );
        if ( is_string( $v ) && '' !== $v ) {
            $v = maybe_unserialize( $v );
        }
        return is_array( $v ) ? array_values( array_map( 'strval', $v ) ) : array();
    }

    /**
     * IDs (as keys) whose contact_info repeater holds an email.
     *
     * @param int[] $ids
     * @return array<int,true>
     */
    private static function lane_b_ids( array $ids ) {
        global $wpdb;
        if ( ! $ids ) {
            return array();
        }
        $in   = implode( ',', array_map( 'intval', $ids ) );
        $rows = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key REGEXP '^contact_info_[0-9]+_value$' AND meta_value LIKE '%@%'" );
        return array_fill_keys( array_map( 'intval', $rows ), true );
    }

    /**
     * Name of the nearest city page's area within 25 miles of a point, e.g. "Milwaukee, WI".
     * Used only to say where an owner is in a trainer message; '' when nothing is close.
     */
    public static function nearest_city_name( $lat, $lng ) {
        global $wpdb;
        $box_lat = 25 / 69.0;
        $box_lng = 25 / max( 1.0, 69.172 * cos( deg2rad( $lat ) ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.name, la.meta_value AS lat, lo.meta_value AS lng
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'area'
             INNER JOIN {$wpdb->termmeta} la ON la.term_id = t.term_id AND la.meta_key = 'latitude'
             INNER JOIN {$wpdb->termmeta} lo ON lo.term_id = t.term_id AND lo.meta_key = 'longitude'
             WHERE CAST(la.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
               AND CAST(lo.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f",
            $lat - $box_lat,
            $lat + $box_lat,
            $lng - $box_lng,
            $lng + $box_lng
        ) );
        $best = '';
        $best_d = null;
        foreach ( $rows as $r ) {
            $d = DH_OR_Matcher::miles( $lat, $lng, (float) $r->lat, (float) $r->lng );
            if ( $d <= 25 && ( null === $best_d || $d < $best_d ) ) {
                $best_d = $d;
                $best   = html_entity_decode( $r->name, ENT_QUOTES, 'UTF-8' );
            }
        }
        // The site names duplicate towns "Freeport - IL"; write it the way people do.
        return preg_replace( '/\s+-\s+([A-Z]{2})$/', ', $1', $best );
    }

    /**
     * The address a lane A message goes to. Empty unless the profile is lane A.
     */
    public static function trainer_email( $profile_id ) {
        $ce = trim( (string) get_post_meta( (int) $profile_id, 'contact_email', true ) );
        if ( '' === $ce && (int) get_post_meta( (int) $profile_id, 'featured', true ) > 0 ) {
            // A paying Featured trainer is reachable at the email they pay with.
            $ce = trim( (string) get_post_meta( (int) $profile_id, 'featured_billing_email', true ) );
        }
        return ( '' !== $ce && is_email( $ce ) ) ? strtolower( $ce ) : '';
    }

    /** The address a message on this lane goes to, or ''. */
    public static function send_address( $profile_id, $lane ) {
        if ( 'A' === $lane ) {
            return self::trainer_email( $profile_id );
        }
        if ( 'B' === $lane ) {
            return self::lane_b_email( $profile_id );
        }
        return '';
    }

    /**
     * The address a lane B message goes to: the best contact_info email that looks
     * like the trainer's own, or '' when none does or its mail server is not found.
     */
    public static function lane_b_email( $profile_id ) {
        $id    = (int) $profile_id;
        $items = array();
        $n     = (int) get_post_meta( $id, 'contact_info', true );
        for ( $i = 0; $i < $n; $i++ ) {
            $items[] = array(
                'value'  => (string) get_post_meta( $id, 'contact_info_' . $i . '_value', true ),
                'source' => (string) get_post_meta( $id, 'contact_info_' . $i . '_source', true ),
            );
        }
        $host  = (string) wp_parse_url( (string) get_post_meta( $id, 'url', true ), PHP_URL_HOST );
        $email = self::pick_lane_b_email( $items, $host, self::trainer_name( $id ) );
        if ( '' === $email ) {
            return '';
        }
        $domain = substr( strrchr( $email, '@' ), 1 );
        if ( ! in_array( $domain, self::FREE_MAIL, true ) && ! DH_OR_Gates::domain_can_receive( $domain ) ) {
            return '';
        }
        return $email;
    }

    /**
     * Picks one email from contact_info rows. Each row's value and source can be
     * comma-separated lists in step. Best first: an address on the trainer's own
     * website domain, then one listed on the trainer's Google Business profile, then
     * one whose name part carries a distinctive word of the business name or website.
     * Anything else is skipped.
     *
     * @param array  $items     Each: value, source.
     * @param string $site_host Host of the trainer's website, or ''.
     * @param string $title     Business name.
     */
    public static function pick_lane_b_email( array $items, $site_host, $title ) {
        $site  = preg_replace( '/^www\./', '', strtolower( trim( (string) $site_host ) ) );
        $words = array();
        $parts = preg_split( '/[^a-z0-9]+/', strtolower( (string) $title ) );
        if ( '' !== $site ) {
            $parts[] = preg_replace( '/[^a-z0-9]/', '', strtok( $site, '.' ) );
        }
        foreach ( $parts as $w ) {
            if ( strlen( $w ) >= 4 && ! in_array( $w, self::NAME_STOP, true ) ) {
                $words[] = $w;
            }
        }
        $best = '';
        $rank = 4;
        foreach ( $items as $it ) {
            $vals = array_map( 'trim', explode( ',', (string) ( isset( $it['value'] ) ? $it['value'] : '' ) ) );
            $srcs = array_map( 'trim', explode( ',', (string) ( isset( $it['source'] ) ? $it['source'] : '' ) ) );
            foreach ( $vals as $k => $v ) {
                $e = strtolower( $v );
                if ( ! filter_var( $e, FILTER_VALIDATE_EMAIL ) ) {
                    continue;
                }
                list( $local, $domain ) = explode( '@', $e, 2 );
                if ( self::junk_email( $local, $domain ) ) {
                    continue;
                }
                $src = isset( $srcs[ $k ] ) ? $srcs[ $k ] : ( 1 === count( $srcs ) ? $srcs[0] : '' );
                if ( '' !== $site && ( $domain === $site || self::ends_with( $site, '.' . $domain ) || self::ends_with( $domain, '.' . $site ) ) ) {
                    $r = 1;
                } elseif ( 'google_business' === $src ) {
                    $r = 2;
                } elseif ( self::names_match( $local . ( in_array( $domain, self::FREE_MAIL, true ) ? '' : $domain ), $words ) ) {
                    $r = 3;
                } else {
                    continue;
                }
                if ( $r < $rank ) {
                    $rank = $r;
                    $best = $e;
                }
            }
        }
        return $best;
    }

    private static function junk_email( $local, $domain ) {
        if ( preg_match( '/^(no-?reply|no_reply|do-?not-?reply|mailer-daemon|postmaster|abuse)$/', $local ) || preg_match( '/^u00[0-9a-f]{2}/', $local ) ) {
            return true;
        }
        if ( preg_match( '/\.(png|jpe?g|gif|svg|webp)$/', $domain ) ) {
            return true;
        }
        foreach ( self::JUNK_DOMAINS as $bad ) {
            if ( $domain === $bad || self::ends_with( $domain, '.' . $bad ) ) {
                return true;
            }
        }
        return false;
    }

    private static function names_match( $haystack, array $words ) {
        $h = preg_replace( '/[^a-z0-9]/', '', $haystack );
        foreach ( $words as $w ) {
            if ( false !== strpos( $h, $w ) ) {
                return true;
            }
        }
        return false;
    }

    private static function ends_with( $s, $tail ) {
        return '' !== $tail && strlen( $s ) >= strlen( $tail ) && substr( $s, -strlen( $tail ) ) === $tail;
    }

    /** Who a message to this trainer is addressed to. */
    public static function trainer_name( $profile_id ) {
        return html_entity_decode( wp_strip_all_tags( get_the_title( (int) $profile_id ) ), ENT_QUOTES, 'UTF-8' );
    }
}
