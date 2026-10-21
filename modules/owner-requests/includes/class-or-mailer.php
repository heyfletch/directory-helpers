<?php
/**
 * Owner Requests: the one place a message leaves the module.
 *
 * Dry run (on by default): the message is written to the events table as
 * `message_dry_run` with the recipient, subject, headers, text and HTML, and
 * nothing is sent. Real sends additionally need a postal address in settings
 * (CAN-SPAM) and pass a last suppression check for trainers.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Mailer {

    /**
     * @param string $role  'trainer' or 'owner'.
     * @param string $to    Recipient address.
     * @param array  $msg   subject, text, html (from DH_OR_Messages).
     * @param array  $ctx   request_id, route_id, profile_id (trainer), kind (label for the log), unsubscribe_url (trainer).
     * @return string       dry_run | sent | failed | blocked | suppressed
     */
    public static function send( $role, $to, array $msg, array $ctx ) {
        $request_id = isset( $ctx['request_id'] ) ? (int) $ctx['request_id'] : 0;
        $route_id   = isset( $ctx['route_id'] ) ? (int) $ctx['route_id'] : 0;
        $kind       = isset( $ctx['kind'] ) ? $ctx['kind'] : $role;

        $from_name  = (string) DH_OR_Settings::get( 'from_name' );
        $from_email = (string) DH_OR_Settings::get( 'from_email' );
        $headers    = array(
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Content-Type: text/html; charset=UTF-8',
        );
        if ( 'trainer' === $role && ! empty( $ctx['unsubscribe_url'] ) ) {
            $headers[] = 'List-Unsubscribe: <' . $ctx['unsubscribe_url'] . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }

        if ( DH_OR_Settings::get( 'kill_switch' ) ) {
            DH_OR_Store::add_event( $request_id, $route_id, 'message_blocked', array( 'kind' => $kind, 'reason' => 'kill_switch' ) );
            return 'blocked';
        }

        if ( DH_OR_Settings::dry_run() ) {
            DH_OR_Store::add_event( $request_id, $route_id, 'message_dry_run', array(
                'kind'    => $kind,
                'role'    => $role,
                'to'      => $to,
                'subject' => $msg['subject'],
                'headers' => $headers,
                'text'    => $msg['text'],
                'html'    => $msg['html'],
            ) );
            return 'dry_run';
        }

        // Real send from here on.
        if ( '' === trim( (string) DH_OR_Settings::get( 'postal_address' ) ) ) {
            DH_OR_Store::add_event( $request_id, $route_id, 'message_blocked', array( 'kind' => $kind, 'reason' => 'no_postal_address' ) );
            return 'blocked';
        }
        if ( 'trainer' === $role && ! empty( $ctx['profile_id'] ) && DH_OR_Store::suppressed_ids( array( (int) $ctx['profile_id'] ) ) ) {
            DH_OR_Store::add_event( $request_id, $route_id, 'message_blocked', array( 'kind' => $kind, 'reason' => 'suppressed' ) );
            return 'suppressed';
        }

        $alt = function ( $phpmailer ) use ( $msg ) {
            $phpmailer->AltBody = $msg['text'];
        };
        add_action( 'phpmailer_init', $alt );
        $ok = wp_mail( $to, $msg['subject'], $msg['html'], $headers );
        remove_action( 'phpmailer_init', $alt );

        DH_OR_Store::add_event( $request_id, $route_id, $ok ? 'message_sent' : 'message_failed', array(
            'kind'    => $kind,
            'role'    => $role,
            'to'      => $to,
            'subject' => $msg['subject'],
        ) );
        return $ok ? 'sent' : 'failed';
    }
}
