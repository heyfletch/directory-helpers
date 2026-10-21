<?php
/**
 * Owner Requests: the loop.
 *
 * submission -> request row -> Gate B screen -> (green) match -> routes ->
 * trainer notices -> owner receipt. Amber requests wait for the owner's
 * confirm click; red requests are dropped and kept for audit.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Flow {

    /** Confirmation HTML the form shows after submit (set during the same request). */
    public static $result_html = '';

    /**
     * Fluent Forms `fluentform/submission_inserted` handler body. Never throws.
     */
    public static function from_submission( $entry_id, array $data ) {
        self::$result_html = '';
        try {
            $p   = DH_OR_Gates::parse( $data );
            $ip  = DH_OR_Gates::client_ip();
            $pt  = DH_OR_Schema::zip_point( $p['zip'] );
            if ( ! $pt || '' === $p['email'] ) {
                self::$result_html = '<p>We could not read your request. Please try again.</p>';
                return;
            }

            $text   = $p['problem'];
            $screen = self::screen( $text, $p['chips'], $p['dog_age'] );

            $owner_token = DH_OR_Store::token();
            $request_id  = DH_OR_Store::insert_request( array(
                'status'            => 'received',
                'tier'              => $screen['tier'],
                'is_test'           => DH_OR_Settings::public_open() ? 0 : 1,
                'source_post_id'    => $p['source_post_id'],
                'source_type'       => $p['source_type'],
                'source_url'        => $p['source_post_id'] ? mb_substr( (string) get_permalink( $p['source_post_id'] ), 0, 255 ) : '',
                'utm_source'        => $p['utm_source'],
                'utm_medium'        => $p['utm_medium'],
                'utm_campaign'      => $p['utm_campaign'],
                'viewed_profile_id' => $p['viewed_profile_id'],
                'owner_first_name'  => $p['first_name'],
                'owner_email'       => $p['email'],
                'owner_phone'       => $p['phone'],
                'zip'               => $p['zip'],
                'lat'               => $pt['lat'],
                'lng'               => $pt['lng'],
                'problem_text'      => $text,
                'chips'             => implode( ',', $p['chips'] ),
                'dog_age'           => $screen['dog_age'],
                'format'            => $p['format'],
                'specialty_primary' => $screen['primary'],
                'specialties'       => implode( ',', $screen['specialties'] ),
                'flags'             => implode( ',', $screen['flags'] ),
                'screen_version'    => $screen['version'],
                'screen_reasons'    => implode( ',', $screen['reasons'] ),
                'ip_hash'           => DH_OR_Store::ip_hash( $ip ),
                'consent_at'        => DH_OR_Store::now(),
                'consent_text'      => DH_OR_Gates::CONSENT_TEXT,
                'owner_token_hash'  => DH_OR_Store::hash_token( $owner_token ),
            ) );
            if ( ! $request_id ) {
                self::$result_html = '<p>Something went wrong on our side. Please try again in a few minutes.</p>';
                return;
            }
            DH_OR_Store::add_event( $request_id, 0, 'submitted', array(
                'source_type' => $p['source_type'],
                'tier'        => $screen['tier'],
                'flags'       => $screen['flags'],
                'reasons'     => $screen['reasons'],
                'entry_id'    => (int) $entry_id,
            ) );

            if ( 'red' === $screen['tier'] ) {
                DH_OR_Store::update_request( $request_id, array( 'status' => 'dropped', 'drop_reason' => implode( ',', $screen['reasons'] ) ) );
                DH_OR_Store::add_event( $request_id, 0, 'dropped', array( 'reasons' => $screen['reasons'] ) );
                self::$result_html = '<p>We could not send this request. If you think that is a mistake, try again with more about your dog.</p>';
                return;
            }

            if ( 'amber' === $screen['tier'] ) {
                DH_OR_Store::update_request( $request_id, array( 'status' => 'held' ) );
                DH_OR_Store::add_event( $request_id, 0, 'held', array( 'reasons' => $screen['reasons'] ) );
                $request = DH_OR_Store::get_request( $request_id );
                self::send_owner_confirm( $request, $owner_token );
                self::$result_html = '<p><strong>Check your email.</strong> We sent you a link to confirm your request. We have not sent it to any trainer yet.</p>';
                return;
            }

            $request = DH_OR_Store::get_request( $request_id );
            self::route_request( $request, $owner_token );
        } catch ( \Throwable $e ) {
            error_log( '[owner-requests] ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
            self::$result_html = '<p>Something went wrong on our side. Please try again in a few minutes.</p>';
        }
    }

    /** Gate B. The Jev workflow replaces this through the filter; any failure there is amber. */
    private static function screen( $text, array $chips, $dog_age ) {
        $local = DH_OR_Screen::screen( $text, $chips, $dog_age );
        try {
            $out = apply_filters( 'dh_or_screen', $local, $text, $chips, $dog_age );
            if ( ! is_array( $out ) || ! isset( $out['tier'] ) || ! in_array( $out['tier'], array( 'green', 'amber', 'red' ), true ) ) {
                throw new \RuntimeException( 'bad screen result' );
            }
            return $out;
        } catch ( \Throwable $e ) {
            $local['tier']      = 'amber';
            $local['reasons'][] = 'screen_failed';
            return $local;
        }
    }

    /* ---------- routing ---------- */

    /**
     * Match, create routes, send the trainer notices and the owner receipt.
     * $owner_token is the raw owner token when the caller has it (first run); a
     * confirm click passes the token from the URL.
     */
    public static function route_request( $request, $owner_token ) {
        $flags      = $request->flags ? explode( ',', $request->flags ) : array();
        $specs      = $request->specialties ? explode( ',', $request->specialties ) : array();
        $lat        = (float) $request->lat;
        $lng        = (float) $request->lng;
        $radii      = array_map( 'intval', (array) DH_OR_Settings::get( 'radii' ) );
        $want_video = in_array( $request->format, array( 'video', 'any' ), true );

        $cands = DH_OR_Candidates::load( $lat, $lng, max( $radii ), (int) $request->viewed_profile_id, $want_video );
        $ids   = wp_list_pluck( $cands, 'id' );

        $req = array(
            'lat'         => $lat,
            'lng'         => $lng,
            'specialties' => $specs,
            'dog_age'     => $request->dog_age,
            'format'      => $request->format ? $request->format : 'any',
            'flags'       => $flags,
            'viewed_id'   => (int) $request->viewed_profile_id,
        );
        $opts = array(
            'min'            => (int) DH_OR_Settings::get( 'min_trainers' ),
            'max'            => (int) DH_OR_Settings::get( 'max_trainers' ),
            'radii'          => $radii,
            'weekly_cap'     => (int) DH_OR_Settings::get( 'trainer_weekly_cap' ),
            'recent'         => DH_OR_Store::recent_route_counts( $ids ),
            'suppressed'     => DH_OR_Store::suppressed_ids( $ids ),
            'behavior_certs' => DH_OR_Candidates::behavior_certs(),
        );

        $active = DH_OR_Settings::active_lanes();
        $result = DH_OR_Matcher::match( $cands, $req, array_merge( $opts, array( 'lanes' => $active ) ) );

        // What the closed lanes would add. Counts and ids only; no sending code exists for them.
        $all   = DH_OR_Matcher::match( $cands, $req, array_merge( $opts, array( 'lanes' => array( 'A', 'B', 'C' ) ) ) );
        $other = array();
        foreach ( $all['routes'] as $r ) {
            if ( ! in_array( $r['lane'], $active, true ) ) {
                $other[] = array( 'profile_id' => $r['profile_id'], 'lane' => $r['lane'], 'distance' => $r['distance'] );
            }
        }
        DH_OR_Store::add_event( $request->id, 0, 'matched', array(
            'radius_used'     => $result['radius_used'],
            'pool_size'       => $result['pool_size'],
            'routes'          => wp_list_pluck( $result['routes'], 'profile_id' ),
            'skipped_reasons' => array_map( 'count', $result['skipped_reasons'] ),
            'other_lanes'     => $other,
            'lanes_active'    => $active,
        ) );

        // Routes.
        $route_rows = array();
        foreach ( $result['routes'] as $r ) {
            $raw      = DH_OR_Store::token();
            $route_id = DH_OR_Store::insert_route( array(
                'request_id' => (int) $request->id,
                'profile_id' => (int) $r['profile_id'],
                'lane'       => $r['lane'],
                'rank_pos'   => (int) $r['rank'],
                'distance'   => $r['distance'],
                'score'      => $r['score'],
                'fit_notes'  => mb_substr( $r['fit_notes'], 0, 255 ),
                'is_viewed'  => (int) $r['is_viewed'],
                'status'     => 'queued',
                'token_hash' => DH_OR_Store::hash_token( $raw ),
            ) );
            $route_rows[] = array( 'id' => $route_id, 'token' => $raw, 'route' => $r );
        }

        // Trainer notices.
        $city      = DH_OR_Candidates::nearest_city_name( $lat, $lng );
        $text      = (string) $request->problem_text;
        $postal    = self::postal();
        $notified  = array();
        $reachable = 0;
        foreach ( $route_rows as $rr ) {
            if ( DH_OR_Candidates::trainer_email( $rr['route']['profile_id'] ) ) {
                $reachable++;
            }
        }
        foreach ( $route_rows as $rr ) {
            $r  = $rr['route'];
            $to = DH_OR_Candidates::trainer_email( $r['profile_id'] );
            if ( '' === $to ) {
                DH_OR_Store::update_route( $rr['id'], array( 'status' => 'skipped', 'ledger_state' => 'none', 'ledger_note' => 'no lane A email' ) );
                continue;
            }
            $msg = DH_OR_Messages::trainer_notice( array(
                'trainer_name'   => DH_OR_Candidates::trainer_name( $r['profile_id'] ),
                'city'           => $city,
                'zip'            => $request->zip,
                'distance'       => $r['distance'],
                'by_video'       => ! empty( $r['by_video'] ),
                'unplaced'       => ! empty( $r['unplaced'] ),
                'text'           => $text,
                'specialties'    => $specs,
                'dog_age'        => $request->dog_age,
                'format'         => $request->format,
                'flags'          => $flags,
                'notified_count' => $reachable,
                'open_url'       => DH_OR_Pages::url( 'trainer', $rr['token'] ),
                'optout_url'     => DH_OR_Pages::url( 'optout', $rr['token'] ),
                'postal'         => $postal,
            ) );
            $status = DH_OR_Mailer::send( 'trainer', $to, $msg, array(
                'request_id'      => (int) $request->id,
                'route_id'        => $rr['id'],
                'profile_id'      => (int) $r['profile_id'],
                'kind'            => 'trainer_notice',
                'unsubscribe_url' => DH_OR_Pages::url( 'optout', $rr['token'] ),
            ) );
            if ( in_array( $status, array( 'dry_run', 'sent' ), true ) ) {
                DH_OR_Store::update_route( $rr['id'], array( 'status' => $status, 'notified_at' => DH_OR_Store::now(), 'ledger_state' => 'pending' ) );
                $notified[] = array(
                    'name' => DH_OR_Candidates::trainer_name( $r['profile_id'] ),
                    'city' => $r['city'],
                    'url'  => get_permalink( $r['profile_id'] ),
                    'by_video' => ! empty( $r['by_video'] ),
                    'far'      => ! empty( $r['is_viewed'] ) && ( ! empty( $r['unplaced'] ) || $r['distance'] > max( $radii ) ),
                );
            } else {
                DH_OR_Store::update_route( $rr['id'], array( 'status' => 'failed', 'ledger_state' => 'none', 'ledger_note' => $status ) );
            }
        }

        // Owner receipt.
        $fallback = $request->source_url ? $request->source_url : home_url( '/' );
        $receipt  = DH_OR_Messages::owner_receipt( array(
            'first_name'   => $request->owner_first_name,
            'zip'          => $request->zip,
            'text'         => $text,
            'trainers'     => $notified,
            'flags'        => $flags,
            'delete_url'   => DH_OR_Pages::url( 'delete', $owner_token ),
            'fallback_url' => $fallback,
            'postal'       => $postal,
        ) );
        DH_OR_Mailer::send( 'owner', $request->owner_email, $receipt, array(
            'request_id' => (int) $request->id,
            'kind'       => $notified ? 'owner_receipt' : 'owner_receipt_thin',
        ) );

        $thin = count( $notified ) < (int) DH_OR_Settings::get( 'min_trainers' );
        DH_OR_Store::update_request( $request->id, array(
            'status'           => $notified ? 'routed' : 'thin',
            'radius_used'      => (int) $result['radius_used'],
            'matched_count'    => count( $result['routes'] ),
            'notified_count'   => count( $notified ),
            'thin_market'      => $thin ? 1 : 0,
            'other_lane_count' => count( $other ),
        ) );
        if ( $thin ) {
            DH_OR_Store::add_event( $request->id, 0, 'thin_market', array( 'notified' => count( $notified ), 'other_lane_count' => count( $other ) ) );
        }

        self::$result_html = self::result_html( $notified, $flags, $fallback );
    }

    /** What the form shows the owner right after a green request. */
    private static function result_html( array $notified, array $flags, $fallback ) {
        $vet = ( in_array( 'medical', $flags, true ) || in_array( 'aggression', $flags, true ) )
            ? '<p><strong>' . esc_html( DH_OR_Messages::VET_LINE ) . '</strong></p>' : '';
        if ( $notified ) {
            $out  = '<p><strong>Your request is on its way.</strong> We sent it to ' . esc_html( DH_OR_Messages::list_intro( $notified, 'you' ) ) . '</p><ul>';
            foreach ( $notified as $t ) {
                $out .= '<li><a href="' . esc_url( $t['url'] ) . '">' . esc_html( $t['name'] ) . '</a>' . esc_html( DH_OR_Messages::item_tag( $t ) ) . '</li>';
            }
            $out .= '</ul><p>We emailed you a copy. A trainer who opens your request sees your first name, your email, and your phone if you gave one, and replies to you directly.</p>';
            return $out . $vet;
        }
        return '<p><strong>We could not send your request yet.</strong> No trainer near your ZIP code takes requests through Goody Doggy yet. You can still contact any trainer listed on this site.</p>'
            . '<p><a href="' . esc_url( $fallback ) . '">See trainers near you</a></p>' . $vet;
    }

    private static function postal() {
        $p = trim( (string) DH_OR_Settings::get( 'postal_address' ) );
        return '' !== $p ? $p : '[postal address not set]';
    }

    /* ---------- owner confirm (amber) ---------- */

    private static function send_owner_confirm( $request, $owner_token ) {
        $msg = DH_OR_Messages::owner_confirm( array(
            'first_name'  => $request->owner_first_name,
            'zip'         => $request->zip,
            'confirm_url' => DH_OR_Pages::url( 'confirm', $owner_token ),
            'delete_url'  => DH_OR_Pages::url( 'delete', $owner_token ),
            'postal'      => self::postal(),
        ) );
        DH_OR_Mailer::send( 'owner', $request->owner_email, $msg, array( 'request_id' => (int) $request->id, 'kind' => 'owner_confirm' ) );
    }

    /**
     * The owner pressed Confirm on a held request. Returns the request or null.
     */
    public static function confirm( $raw_token ) {
        $request = DH_OR_Store::request_by_owner_token( $raw_token );
        if ( ! $request || 'held' !== $request->status ) {
            return null;
        }
        DH_OR_Store::update_request( $request->id, array( 'status' => 'received', 'tier' => 'green' ) );
        DH_OR_Store::add_event( $request->id, 0, 'confirmed', array() );
        $request = DH_OR_Store::get_request( $request->id );
        self::route_request( $request, $raw_token );
        return DH_OR_Store::get_request( $request->id );
    }

    /* ---------- owner delete ---------- */

    public static function delete_by_owner( $raw_token ) {
        $request = DH_OR_Store::request_by_owner_token( $raw_token );
        if ( ! $request ) {
            return false;
        }
        DH_OR_Store::scrub_request( $request->id, 'deleted' );
        return true;
    }

    /* ---------- trainer side ---------- */

    /** Trainer opened the request page. */
    public static function route_viewed( $route ) {
        if ( empty( $route->viewed_at ) ) {
            DH_OR_Store::update_route( $route->id, array( 'viewed_at' => DH_OR_Store::now() ) );
            DH_OR_Store::add_event( $route->request_id, $route->id, 'viewed', array() );
        }
    }

    /** Trainer pressed I replied, Not a fit or Already booked. */
    public static function route_outcome( $route, $outcome ) {
        if ( ! in_array( $outcome, array( 'replied', 'not_fit', 'booked' ), true ) ) {
            return false;
        }
        $now    = DH_OR_Store::now();
        $detail = array( 'outcome' => $outcome );
        if ( 'replied' === $outcome && $route->notified_at ) {
            $detail['reply_minutes'] = (int) round( ( strtotime( $now ) - strtotime( $route->notified_at ) ) / 60 );
        }
        DH_OR_Store::update_route( $route->id, array( 'outcome' => $outcome, 'outcome_at' => $now ) );
        DH_OR_Store::add_event( $route->request_id, $route->id, 'outcome_' . $outcome, $detail );
        return true;
    }

    /** Trainer chose "stop requests like this": suppress them on the site; the sync passes it to the ledger. */
    public static function route_optout( $route ) {
        DH_OR_Store::suppress( $route->profile_id, 'opt-out from request email', 'optout' );
        DH_OR_Store::update_route( $route->id, array( 'outcome' => 'optout', 'outcome_at' => DH_OR_Store::now() ) );
        DH_OR_Store::add_event( $route->request_id, $route->id, 'optout', array( 'profile_id' => (int) $route->profile_id ) );
    }

    /* ---------- retract ---------- */

    /**
     * Withdraw a request: tell the trainers who got it and the owner, then close it.
     */
    public static function retract( $request_id ) {
        $request = DH_OR_Store::get_request( $request_id );
        if ( ! $request || in_array( $request->status, array( 'withdrawn', 'deleted', 'expired', 'dropped' ), true ) ) {
            return false;
        }
        $postal = self::postal();
        foreach ( DH_OR_Store::routes_for( $request->id ) as $route ) {
            if ( ! in_array( $route->status, array( 'sent', 'dry_run' ), true ) ) {
                continue;
            }
            $to = DH_OR_Candidates::trainer_email( $route->profile_id );
            if ( $to ) {
                $msg = DH_OR_Messages::withdrawn_trainer( array( 'trainer_name' => DH_OR_Candidates::trainer_name( $route->profile_id ), 'postal' => $postal ) );
                DH_OR_Mailer::send( 'trainer', $to, $msg, array( 'request_id' => (int) $request->id, 'route_id' => (int) $route->id, 'profile_id' => (int) $route->profile_id, 'kind' => 'withdrawn_trainer' ) );
            }
            DH_OR_Store::update_route( $route->id, array( 'outcome' => 'withdrawn', 'outcome_at' => DH_OR_Store::now() ) );
        }
        if ( $request->owner_email ) {
            $msg = DH_OR_Messages::withdrawn_owner( array( 'first_name' => $request->owner_first_name, 'postal' => $postal ) );
            DH_OR_Mailer::send( 'owner', $request->owner_email, $msg, array( 'request_id' => (int) $request->id, 'kind' => 'withdrawn_owner' ) );
        }
        DH_OR_Store::update_request( $request->id, array( 'status' => 'withdrawn' ) );
        DH_OR_Store::add_event( $request->id, 0, 'withdrawn', array() );
        return true;
    }
}
