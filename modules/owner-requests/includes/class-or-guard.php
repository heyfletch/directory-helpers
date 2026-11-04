<?php
/**
 * Automatic pause. Every hour it reads Amazon SES send statistics for the site's
 * sender and the opt-outs on recent trainer emails, and pauses trainer emails when
 * the bet's health stop trips: 2% hard bounces or 0.1% complaints over the last
 * 200 sends, or opt-outs above 2% of the last 200 trainer emails. Joe gets one
 * email when a pause happens.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Guard {

    const HOOK          = 'dh_or_guard_check';
    const WINDOW        = 200;
    const BOUNCE_MAX    = 0.02;
    const COMPLAINT_MAX = 0.001;
    const OPTOUT_MAX    = 0.02;
    const STATE_OPTION  = 'dh_or_guard_state';
    const ALERT_TO      = 'joe@goodydoggy.com';

    public function __construct() {
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
        add_action( 'init', array( __CLASS__, 'schedule' ) );
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 300, 'hourly', self::HOOK );
        }
    }

    public static function run() {
        self::check( false );
    }

    /**
     * Returns the numbers and the decision. With $dry true it changes nothing.
     */
    public static function check( $dry ) {
        $ses     = apply_filters( 'dh_or_guard_ses_stats', null );
        $ses     = null === $ses ? self::ses_stats() : $ses;
        $optouts = apply_filters( 'dh_or_guard_optouts', null );
        $optouts = null === $optouts ? self::optout_stats() : $optouts;

        $reasons = array();
        if ( is_array( $ses ) && $ses['attempts'] >= self::WINDOW ) {
            if ( $ses['bounces'] / $ses['attempts'] >= self::BOUNCE_MAX ) {
                $reasons[] = sprintf( '%d of the last %d emails bounced', $ses['bounces'], $ses['attempts'] );
            }
            if ( $ses['complaints'] / $ses['attempts'] >= self::COMPLAINT_MAX ) {
                $reasons[] = sprintf( '%d of the last %d emails were marked as spam', $ses['complaints'], $ses['attempts'] );
            }
        }
        if ( $optouts['sent'] >= self::WINDOW && $optouts['optouts'] / $optouts['sent'] > self::OPTOUT_MAX ) {
            $reasons[] = sprintf( '%d of the last %d trainers who got a request clicked Unsubscribe from these dog training requests', $optouts['optouts'], $optouts['sent'] );
        }

        $already = in_array( 'A', array_map( 'strtoupper', (array) DH_OR_Settings::get( 'paused_lanes' ) ), true );
        $result  = array(
            'ses'      => $ses,
            'optouts'  => $optouts,
            'reasons'  => $reasons,
            'paused'   => false,
            'already'  => $already,
            'ses_ok'   => is_array( $ses ),
        );
        if ( $dry || ! $reasons || $already ) {
            return $result;
        }

        DH_OR_Settings::update( array( 'paused_lanes' => array_values( array_unique( array_merge( (array) DH_OR_Settings::get( 'paused_lanes' ), array( 'A' ) ) ) ) ) );
        DH_OR_Store::add_event( 0, 0, 'auto_pause', array( 'reasons' => $reasons ) );
        update_option( self::STATE_OPTION, array( 'paused_at' => DH_OR_Store::now(), 'reasons' => $reasons ), false );
        wp_mail(
            self::ALERT_TO,
            'Goody Doggy paused trainer request emails',
            "Goody Doggy stopped sending dog owner requests to trainers because:\n\n- " . implode( "\n- ", $reasons )
            . "\n\nNothing goes to trainers until you resume. Resume in WordPress, Directory Helpers, Dog Owner Requests, or ask Claude to run wp dh-requests resume --lane=A."
        );
        $result['paused'] = true;
        return $result;
    }

    /**
     * Opt-outs among the most recent trainer emails really sent.
     */
    public static function optout_stats() {
        global $wpdb;
        $t    = DH_OR_Schema::table( 'request_routes' );
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT outcome FROM {$t} WHERE status = 'sent' ORDER BY notified_at DESC LIMIT %d", self::WINDOW ) );
        $out  = 0;
        foreach ( $rows as $o ) {
            if ( 'optout' === $o ) {
                $out++;
            }
        }
        return array( 'sent' => count( $rows ), 'optouts' => $out );
    }

    /**
     * Hard bounces and complaints over the most recent SES send intervals that add up
     * to at least the window, or null when SES cannot be read.
     */
    public static function ses_stats() {
        if ( ! defined( 'FLUENTMAIL_AWS_ACCESS_KEY_ID' ) || ! defined( 'FLUENTMAIL_AWS_SECRET_ACCESS_KEY' ) ) {
            return null;
        }
        $region = defined( 'FLUENTMAIL_AWS_REGION' ) ? FLUENTMAIL_AWS_REGION : 'us-east-1';
        $host   = 'email.' . $region . '.amazonaws.com';
        $body   = 'Action=GetSendStatistics&Version=2010-12-01';
        $amz    = gmdate( 'Ymd\THis\Z' );
        $day    = substr( $amz, 0, 8 );
        $type   = 'application/x-www-form-urlencoded; charset=utf-8';
        $canon  = "POST\n/\n\ncontent-type:{$type}\nhost:{$host}\nx-amz-date:{$amz}\n\ncontent-type;host;x-amz-date\n" . hash( 'sha256', $body );
        $scope  = "{$day}/{$region}/ses/aws4_request";
        $sts    = "AWS4-HMAC-SHA256\n{$amz}\n{$scope}\n" . hash( 'sha256', $canon );
        $k      = hash_hmac( 'sha256', $day, 'AWS4' . FLUENTMAIL_AWS_SECRET_ACCESS_KEY, true );
        $k      = hash_hmac( 'sha256', $region, $k, true );
        $k      = hash_hmac( 'sha256', 'ses', $k, true );
        $k      = hash_hmac( 'sha256', 'aws4_request', $k, true );
        $sig    = hash_hmac( 'sha256', $sts, $k );
        $res    = wp_remote_post( 'https://' . $host . '/', array(
            'timeout' => 20,
            'headers' => array(
                'Content-Type'  => $type,
                'X-Amz-Date'    => $amz,
                'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . FLUENTMAIL_AWS_ACCESS_KEY_ID . '/' . $scope . ', SignedHeaders=content-type;host;x-amz-date, Signature=' . $sig,
            ),
            'body'    => $body,
        ) );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
            return null;
        }
        $xml = simplexml_load_string( wp_remote_retrieve_body( $res ) );
        if ( ! $xml || ! isset( $xml->GetSendStatisticsResult->SendDataPoints ) ) {
            return null;
        }
        $points = array();
        foreach ( $xml->GetSendStatisticsResult->SendDataPoints->member as $m ) {
            $points[] = array( 't' => (string) $m->Timestamp, 'a' => (int) $m->DeliveryAttempts, 'b' => (int) $m->Bounces, 'c' => (int) $m->Complaints );
        }
        usort( $points, function ( $x, $y ) {
            return strcmp( $y['t'], $x['t'] );
        } );
        $sum = array( 'attempts' => 0, 'bounces' => 0, 'complaints' => 0 );
        foreach ( $points as $p ) {
            $sum['attempts']   += $p['a'];
            $sum['bounces']    += $p['b'];
            $sum['complaints'] += $p['c'];
            if ( $sum['attempts'] >= self::WINDOW ) {
                break;
            }
        }
        return $sum;
    }
}
