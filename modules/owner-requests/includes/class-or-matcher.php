<?php
/**
 * Owner Requests: matching (pure PHP, unit-tested).
 *
 * Deterministic. Takes a list of candidate trainers and one request and returns
 * who the request goes to. No WordPress calls: the candidate loader
 * (DH_OR_Candidates) and the settings supply every input.
 *
 * Rules, from bet.md "The loop, step by step", step 4:
 *  - Candidates already exclude unpublished, closed, restricted and store
 *    listings (the loader); this class also skips suppressed trainers, trainers
 *    whose lane is off, and trainers at the weekly cap.
 *  - Distance from the owner's ZIP: start at the first radius, widen until the
 *    minimum count is reached, stop at the last radius.
 *  - Fit: specialty overlap, dog age, format. Aggression, bite or medical
 *    requests go only to trainers holding a behavior credential.
 *  - Order: public rank score (rating and reviews), then distance. Featured has
 *    no input here and no effect.
 *  - Profile-page requests: the viewed trainer goes first when eligible.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Matcher {

    /** Service type a trainer must list for each format the owner can pick. */
    const FORMAT_SERVICE = array(
        'home'  => array( 'home' ),
        'group' => array( 'group' ),
        'board' => array( 'board', 'day' ),
        'video' => array( 'video' ),
    );

    /** Store-chain listings are never matched. */
    const CHAIN_PATTERN = '/\b(petsmart|petco)\b/i';

    /**
     * Same formula the site's rank engine uses for rating and reviews, without
     * any boost, so Featured has no effect: rating * 0.9 + log10(reviews + 1) / 2 * 5 * 0.1.
     */
    public static function rank_score( $rating, $reviews ) {
        $rating  = (float) $rating;
        $reviews = max( 0, (int) $reviews );
        if ( $rating <= 0 || $reviews <= 0 ) {
            return 0.0;
        }
        return ( $rating * 0.9 ) + ( log10( $reviews + 1 ) / 2 ) * 5 * 0.1;
    }

    /** Miles between two points. */
    public static function miles( $lat1, $lng1, $lat2, $lng2 ) {
        $r  = 3959.0;
        $a1 = deg2rad( (float) $lat1 );
        $a2 = deg2rad( (float) $lat2 );
        $d1 = $a2 - $a1;
        $d2 = deg2rad( (float) $lng2 - (float) $lng1 );
        $h  = sin( $d1 / 2 ) ** 2 + cos( $a1 ) * cos( $a2 ) * sin( $d2 / 2 ) ** 2;
        return 2 * $r * asin( min( 1.0, sqrt( $h ) ) );
    }

    public static function is_chain( $name, $url = '' ) {
        return (bool) preg_match( self::CHAIN_PATTERN, (string) $name . ' ' . (string) $url );
    }

    public static function has_behavior_credential( array $certs, array $behavior_certs ) {
        return (bool) array_intersect( array_map( 'strtolower', $certs ), array_map( 'strtolower', $behavior_certs ) );
    }

    /**
     * @param array $candidates Each: id, lat, lng, rating, reviews, specialties[], ages[], services[], certs[], lane, name.
     * @param array $req        lat, lng, specialties[], dog_age, format, flags[], viewed_id.
     * @param array $opts       lanes[], min, max, radii[], weekly_cap, recent{id=>count}, suppressed[ids], behavior_certs[].
     * @return array routes[], radius_used, thin_market, pool_size, skipped_cap[], skipped_reasons{}
     */
    public static function match( array $candidates, array $req, array $opts ) {
        $lanes      = isset( $opts['lanes'] ) ? (array) $opts['lanes'] : array( 'A' );
        $min        = isset( $opts['min'] ) ? (int) $opts['min'] : 3;
        $max        = isset( $opts['max'] ) ? (int) $opts['max'] : 5;
        $radii      = isset( $opts['radii'] ) ? array_values( (array) $opts['radii'] ) : array( 15, 25, 50 );
        $cap        = isset( $opts['weekly_cap'] ) ? (int) $opts['weekly_cap'] : 3;
        $recent     = isset( $opts['recent'] ) ? (array) $opts['recent'] : array();
        $suppressed = isset( $opts['suppressed'] ) ? array_flip( array_map( 'intval', (array) $opts['suppressed'] ) ) : array();
        $behavior   = isset( $opts['behavior_certs'] ) ? (array) $opts['behavior_certs'] : array();

        $flags       = isset( $req['flags'] ) ? (array) $req['flags'] : array();
        $needs_cred  = (bool) array_intersect( $flags, array( 'aggression', 'medical' ) );
        $format      = isset( $req['format'] ) ? (string) $req['format'] : 'any';
        $want_specs  = isset( $req['specialties'] ) ? (array) $req['specialties'] : array();
        $dog_age     = isset( $req['dog_age'] ) ? (string) $req['dog_age'] : '';
        $viewed_id   = isset( $req['viewed_id'] ) ? (int) $req['viewed_id'] : 0;
        $video_ok    = 'video' === $format || ! empty( $req['video_ok'] );

        $skipped = array();
        $note    = function ( $id, $why ) use ( &$skipped ) {
            $skipped[ $why ][] = (int) $id;
        };

        // Base eligibility: lane on, not suppressed, under the weekly cap, credential when flagged.
        $base = array();
        foreach ( $candidates as $c ) {
            $id = (int) $c['id'];
            if ( ! in_array( $c['lane'], $lanes, true ) ) {
                $note( $id, 'lane_off' );
                continue;
            }
            if ( isset( $suppressed[ $id ] ) ) {
                $note( $id, 'suppressed' );
                continue;
            }
            if ( self::is_chain( isset( $c['name'] ) ? $c['name'] : '', isset( $c['url'] ) ? $c['url'] : '' ) ) {
                $note( $id, 'store_chain' );
                continue;
            }
            if ( $cap > 0 && ( isset( $recent[ $id ] ) ? (int) $recent[ $id ] : 0 ) >= $cap ) {
                $note( $id, 'weekly_cap' );
                continue;
            }
            if ( $needs_cred && ! self::has_behavior_credential( (array) $c['certs'], $behavior ) ) {
                $note( $id, 'no_behavior_credential' );
                continue;
            }
            $c['distance'] = self::miles( $req['lat'], $req['lng'], $c['lat'], $c['lng'] );
            $c['score']    = self::rank_score( $c['rating'], $c['reviews'] );
            $base[ $id ]   = $c;
        }

        // Fit filter for the ranked pool.
        // A trainer fits when they offer any one of the kinds the owner ticked.
        $want_services = array();
        foreach ( explode( ',', $format ) as $f ) {
            if ( isset( self::FORMAT_SERVICE[ $f ] ) ) {
                $want_services = array_merge( $want_services, self::FORMAT_SERVICE[ $f ] );
            }
        }
        $fits = function ( array $c, $check_format = true ) use ( $format, $want_services, $want_specs, $dog_age ) {
            $notes = array();
            if ( $check_format && $want_services ) {
                if ( ! array_intersect( $want_services, (array) $c['services'] ) ) {
                    return null;
                }
                $notes[] = 'format ' . $format;
            }
            if ( $want_specs ) {
                $overlap = array_values( array_intersect( $want_specs, (array) $c['specialties'] ) );
                if ( ! $overlap ) {
                    return null;
                }
                $notes[] = 'specialty ' . implode( '+', $overlap );
            }
            if ( '' !== $dog_age && ! empty( $c['ages'] ) ) {
                if ( ! in_array( $dog_age, (array) $c['ages'], true ) ) {
                    return null;
                }
                $notes[] = 'age ' . $dog_age;
            }
            return $notes;
        };

        $video_anywhere = ( 'video' === $format );

        $pool        = array();
        $radius_used = end( $radii );
        foreach ( $radii as $r ) {
            $pool = array();
            foreach ( $base as $id => $c ) {
                if ( $id === $viewed_id ) {
                    continue;
                }
                $in_range = $c['distance'] <= $r;
                if ( ! $in_range && ! ( $video_anywhere && in_array( 'video', (array) $c['services'], true ) ) ) {
                    continue;
                }
                $notes = $fits( $c );
                if ( null === $notes ) {
                    continue;
                }
                $c['fit_notes'] = $notes;
                $c['by_video']  = ! $in_range;
                $pool[ $id ]    = $c;
            }
            $radius_used = $r;
            if ( count( $pool ) >= $min ) {
                break;
            }
        }

        // An owner who ticked "Open to training by video call": a short local pool is topped up with video trainers at any distance.
        if ( $video_ok && count( $pool ) < $min ) {
            foreach ( $base as $id => $c ) {
                if ( $id === $viewed_id || isset( $pool[ $id ] ) || ! in_array( 'video', (array) $c['services'], true ) ) {
                    continue;
                }
                $notes = $fits( $c, false );
                if ( null === $notes ) {
                    continue;
                }
                $c['fit_notes'] = array_merge( $notes, array( 'video, any distance' ) );
                $c['by_video']  = true;
                $pool[ $id ]    = $c;
            }
        }

        $pool_size = count( $pool );
        uasort( $pool, function ( $a, $b ) {
            if ( $a['score'] !== $b['score'] ) {
                return $a['score'] < $b['score'] ? 1 : -1;
            }
            if ( round( $a['distance'], 3 ) !== round( $b['distance'], 3 ) ) {
                return $a['distance'] < $b['distance'] ? -1 : 1;
            }
            return $a['id'] <=> $b['id'];
        } );

        $routes = array();
        if ( $viewed_id && isset( $base[ $viewed_id ] ) ) {
            $v = $base[ $viewed_id ];
            $routes[] = self::route_row( $v, array( 'viewed profile' ), true );
        }
        foreach ( $pool as $c ) {
            if ( count( $routes ) >= $max ) {
                break;
            }
            $routes[] = self::route_row( $c, $c['fit_notes'], false );
        }
        // Reserved spot: an owner who ticked video always gets the best-fitting Featured video trainer, in the last spot if the list is full.
        if ( $video_ok ) {
            $routed = array_column( $routes, 'profile_id' );
            $best   = null;
            foreach ( $base as $id => $c ) {
                if ( $id === $viewed_id || in_array( $id, $routed, true ) || empty( $c['featured'] ) || ! in_array( 'video', (array) $c['services'], true ) ) {
                    continue;
                }
                $notes = $fits( $c, false );
                if ( null === $notes ) {
                    continue;
                }
                if ( null === $best || $c['score'] > $best['score'] ) {
                    $c['fit_notes'] = array_merge( $notes, array( 'Featured, reserved spot' ) );
                    $c['by_video']  = $c['distance'] > $radius_used;
                    $best           = $c;
                }
            }
            if ( $best ) {
                $has_featured = false;
                foreach ( $routes as $r ) {
                    if ( ! $r['is_viewed'] && ! empty( $base[ $r['profile_id'] ]['featured'] ) ) {
                        $has_featured = true;
                    }
                }
                if ( ! $has_featured ) {
                    if ( count( $routes ) >= $max ) {
                        array_pop( $routes );
                    }
                    $routes[] = self::route_row( $best, $best['fit_notes'], false );
                }
            }
        }

        foreach ( $routes as $i => &$row ) {
            $row['rank'] = $i + 1;
        }
        unset( $row );

        return array(
            'routes'          => $routes,
            'radius_used'     => $radius_used,
            'thin_market'     => count( $routes ) < $min,
            'pool_size'       => $pool_size,
            'skipped_reasons' => array_map( 'array_values', $skipped ),
        );
    }

    private static function route_row( array $c, array $notes, $viewed ) {
        return array(
            'profile_id' => (int) $c['id'],
            'lane'       => $c['lane'],
            'distance'   => round( $c['distance'], 1 ),
            'score'      => round( $c['score'], 3 ),
            'fit_notes'  => implode( '; ', $notes ),
            'is_viewed'  => $viewed ? 1 : 0,
            'by_video'   => ! empty( $c['by_video'] ) ? 1 : 0,
            'unplaced'   => ! empty( $c['unplaced'] ) ? 1 : 0,
            'name'       => isset( $c['name'] ) ? $c['name'] : '',
            'city'       => isset( $c['city'] ) ? $c['city'] : '',
        );
    }
}
