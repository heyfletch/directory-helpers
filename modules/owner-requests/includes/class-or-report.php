<?php
/**
 * Weekly count of dog owner requests by city, emailed to Joe every Monday at
 * 8:00 AM Eastern, with the full table on the Dog Owner Requests admin page.
 *
 * A counted request is a real (not test) dog owner request that went to at
 * least one trainer. The lead gen plan's day 45 check (box live Nov 4, 2026)
 * starts Lead Gen Pack at 21 or more dog owner requests a month.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Report {

    const HOOK          = 'dh_or_weekly_report';
    const DAY45         = '2026-12-19';
    const DAY45_MONTHLY = 21;

    public function __construct() {
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
        add_action( 'init', array( __CLASS__, 'schedule' ) );
    }

    /** Mondays at 8:00 AM Eastern. */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            $next = new DateTime( 'monday 08:00', new DateTimeZone( 'America/New_York' ) );
            if ( $next->getTimestamp() <= time() ) {
                $next->modify( '+1 week' );
            }
            wp_schedule_event( $next->getTimestamp(), 'weekly', self::HOOK );
        }
    }

    public static function run() {
        self::send( false );
    }

    /** Counted requests as plain items: city, created timestamp, hire answer. */
    public static function items() {
        global $wpdb;
        $rq    = DH_OR_Schema::table( 'requests' );
        $rows  = $wpdb->get_results( "SELECT created_at, lat, lng, zip, hire_answer FROM {$rq} WHERE is_test = 0 AND notified_count > 0" );
        $cache = array();
        $out   = array();
        foreach ( $rows as $r ) {
            $key = $r->lat . ',' . $r->lng;
            if ( ! isset( $cache[ $key ] ) ) {
                $name          = ( null !== $r->lat && null !== $r->lng ) ? DH_OR_Candidates::nearest_city_name( (float) $r->lat, (float) $r->lng ) : '';
                $cache[ $key ] = '' !== $name ? $name : ( $r->zip ? 'ZIP ' . $r->zip : 'Unknown' );
            }
            $out[] = array( 'city' => $cache[ $key ], 'ts' => strtotime( $r->created_at ), 'hire' => (string) $r->hire_answer );
        }
        return $out;
    }

    /**
     * Counts by city for the last 7 days, the last 30 days, and since the start,
     * plus the dog owners' "Did you hire?" answers. Plain data in and out.
     */
    public static function tally( array $items, $now ) {
        $t = array( 'week' => 0, 'month' => 0, 'all' => 0, 'answered' => 0, 'hired' => 0, 'cities' => array() );
        foreach ( $items as $i ) {
            $c = $i['city'];
            if ( ! isset( $t['cities'][ $c ] ) ) {
                $t['cities'][ $c ] = array( 'week' => 0, 'month' => 0, 'all' => 0 );
            }
            $age = $now - $i['ts'];
            foreach ( array( 'week' => 7, 'month' => 30 ) as $k => $days ) {
                if ( $age < $days * 86400 ) {
                    $t[ $k ]++;
                    $t['cities'][ $c ][ $k ]++;
                }
            }
            $t['all']++;
            $t['cities'][ $c ]['all']++;
            if ( '' !== $i['hire'] ) {
                $t['answered']++;
                if ( 'trainer' === $i['hire'] ) {
                    $t['hired']++;
                }
            }
        }
        uksort( $t['cities'], function ( $a, $b ) use ( $t ) {
            $x = $t['cities'][ $a ];
            $y = $t['cities'][ $b ];
            return array( $y['month'], $y['all'], $a ) <=> array( $x['month'], $x['all'], $b );
        } );
        return $t;
    }

    /** The email to Joe: a title, up to three short lines, and the link to the full table. */
    public static function message( array $t, $today, $admin_url ) {
        $plural = function ( $n ) {
            return $n . ' dog owner request' . ( 1 === $n ? '' : 's' );
        };
        $lines   = array();
        $first   = 'This week: ' . $plural( $t['week'] ) . '. Last 30 days: ' . $t['month'] . '.';
        if ( $today < self::DAY45 ) {
            $first .= ' Lead Gen Pack starts at the December 19 check if this reaches ' . self::DAY45_MONTHLY . ' a month.';
        }
        $lines[] = $first;
        $top     = array();
        foreach ( $t['cities'] as $city => $c ) {
            if ( $c['month'] > 0 && count( $top ) < 3 ) {
                $top[] = $city . ' ' . $c['month'];
            }
        }
        if ( $top ) {
            $lines[] = 'Top cities, last 30 days: ' . implode( ', ', $top ) . '.';
        }
        if ( $t['answered'] > 0 ) {
            $lines[] = 'Hired a Goody Doggy trainer: ' . $t['hired'] . ' of ' . $t['answered'] . ' dog owners who answered.';
        }
        $subject = 'Goody Doggy dog owner requests this week';
        $html    = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;color:#1b2b3a;"><ul style="padding-left:20px;margin:0 0 16px 0;">';
        $text    = '';
        foreach ( $lines as $l ) {
            $html .= '<li>' . htmlspecialchars( $l, ENT_QUOTES, 'UTF-8' ) . '</li>';
            $text .= '- ' . $l . "\n";
        }
        $html .= '</ul><p style="margin:0;"><a href="' . htmlspecialchars( $admin_url, ENT_QUOTES, 'UTF-8' ) . '" style="color:#0572bd;font-weight:bold;">See every city</a></p></div>';
        $text .= "\nSee every city: " . $admin_url . "\n";
        return array( 'subject' => $subject, 'text' => $text, 'html' => $html );
    }

    public static function admin_url() {
        return admin_url( 'admin.php?page=dh-owner-requests#by-city' );
    }

    /** Emails Joe the weekly count. With $dry true it returns the message without sending. */
    public static function send( $dry ) {
        $msg = self::message( self::tally( self::items(), strtotime( DH_OR_Store::now() ) ), wp_date( 'Y-m-d' ), self::admin_url() );
        if ( $dry ) {
            return $msg + array( 'result' => 'dry run' );
        }
        $alt = function ( $phpmailer ) use ( $msg ) {
            $phpmailer->AltBody = $msg['text'];
        };
        add_action( 'phpmailer_init', $alt );
        $ok = wp_mail( DH_OR_Messages::JOE_EMAIL, $msg['subject'], $msg['html'], array(
            'From: ' . DH_OR_Settings::get( 'from_name' ) . ' <' . DH_OR_Settings::get( 'from_email' ) . '>',
            'Content-Type: text/html; charset=UTF-8',
        ) );
        remove_action( 'phpmailer_init', $alt );
        DH_OR_Store::add_event( 0, 0, $ok ? 'weekly_report_sent' : 'weekly_report_failed', array() );
        return $msg + array( 'result' => $ok ? 'sent' : 'failed' );
    }
}
