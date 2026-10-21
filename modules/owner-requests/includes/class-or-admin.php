<?php
/**
 * Owner Requests: admin screen (Directory Helpers > Owner Requests).
 *
 * Switches, the request list, and for each request its routes, events and the
 * dry-run messages (what would have been sent). Admins only.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ), 20 );
    }

    public function menu() {
        add_submenu_page(
            'directory-helpers',
            'Owner Requests',
            'Owner Requests',
            'manage_options',
            'dh-owner-requests',
            array( $this, 'render' )
        );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        global $wpdb;

        $notice = '';
        if ( isset( $_POST['dh_or_save'] ) && check_admin_referer( 'dh_or_settings' ) ) {
            $p = wp_unslash( $_POST );
            DH_OR_Settings::update( array(
                'preview_only'       => empty( $p['preview_only'] ) ? 0 : 1,
                'dry_run'            => empty( $p['dry_run'] ) ? 0 : 1,
                'kill_switch'        => empty( $p['kill_switch'] ) ? 0 : 1,
                'auto_inject'        => empty( $p['auto_inject'] ) ? 0 : 1,
                'postal_address'     => sanitize_textarea_field( isset( $p['postal_address'] ) ? $p['postal_address'] : '' ),
                'trainer_weekly_cap' => max( 0, (int) $p['trainer_weekly_cap'] ),
                'max_trainers'       => max( 1, (int) $p['max_trainers'] ),
                'owner_email_day'    => max( 0, (int) $p['owner_email_day'] ),
                'owner_email_week'   => max( 0, (int) $p['owner_email_week'] ),
                'ip_day'             => max( 0, (int) $p['ip_day'] ),
                'zip_day'            => max( 0, (int) $p['zip_day'] ),
            ) );
            $notice = '<div class="notice notice-success"><p>Saved.</p></div>';
        }
        $s = DH_OR_Settings::all();

        echo '<div class="wrap"><h1>Owner Requests</h1>' . $notice; // phpcs:ignore WordPress.Security.EscapeOutput
        $req_id = isset( $_GET['request'] ) ? (int) $_GET['request'] : 0;
        if ( $req_id ) {
            $this->render_request( $req_id );
            echo '</div>';
            return;
        }

        // Settings.
        echo '<form method="post"><h2>Switches</h2>';
        wp_nonce_field( 'dh_or_settings' );
        echo '<table class="form-table"><tbody>';
        $this->check_row( 'preview_only', 'Preview only', 'Box, form and token pages work only for admins.', $s );
        $this->check_row( 'dry_run', 'Dry run', 'Write every email to the log below instead of sending it.', $s );
        $this->check_row( 'kill_switch', 'Kill switch', 'Stop all requests and sends.', $s );
        $this->check_row( 'auto_inject', 'Auto-place box', 'Add the box to city and profile pages without a Bricks template change (admins only while preview is on).', $s );
        foreach ( array(
            'max_trainers'       => 'Trainers per request (max)',
            'trainer_weekly_cap' => 'Requests per trainer in 7 days (cap)',
            'owner_email_day'    => 'Requests per owner email per day',
            'owner_email_week'   => 'Requests per owner email per 7 days',
            'ip_day'             => 'Requests per IP per day',
            'zip_day'            => 'Requests per ZIP per day (0 = no cap)',
        ) as $k => $label ) {
            echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="number" min="0" name="' . esc_attr( $k ) . '" value="' . (int) $s[ $k ] . '" class="small-text"></td></tr>';
        }
        echo '<tr><th>Postal address (required before any real send)</th><td><textarea name="postal_address" rows="3" cols="50">' . esc_textarea( $s['postal_address'] ) . '</textarea></td></tr>';
        echo '</tbody></table><p><button class="button button-primary" name="dh_or_save" value="1">Save</button></p></form>';

        // Counts.
        $rt = DH_OR_Schema::table( 'requests' );
        $counts = $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$rt} GROUP BY status" );
        echo '<h2>Requests by status</h2><p>';
        foreach ( $counts as $c ) {
            echo esc_html( $c->status ) . ': ' . (int) $c->n . ' &nbsp; ';
        }
        echo $counts ? '' : 'None yet.';
        echo '</p>';

        // List.
        $rows = $wpdb->get_results( "SELECT id, created_at, status, tier, source_type, zip, matched_count, notified_count, thin_market, other_lane_count, is_test FROM {$rt} ORDER BY id DESC LIMIT 50" );
        echo '<h2>Latest requests</h2><table class="widefat striped"><thead><tr><th>ID</th><th>When</th><th>Status</th><th>Tier</th><th>Source</th><th>ZIP</th><th>Matched</th><th>Notified</th><th>Thin</th><th>Other lanes would add</th><th>Test</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $link = esc_url( add_query_arg( array( 'page' => 'dh-owner-requests', 'request' => (int) $r->id ), admin_url( 'admin.php' ) ) );
            echo '<tr><td><a href="' . $link . '">#' . (int) $r->id . '</a></td><td>' . esc_html( $r->created_at ) . '</td><td>' . esc_html( $r->status ) . '</td><td>' . esc_html( $r->tier ) . '</td><td>' . esc_html( $r->source_type ) . '</td><td>' . esc_html( $r->zip ) . '</td><td>' . (int) $r->matched_count . '</td><td>' . (int) $r->notified_count . '</td><td>' . ( $r->thin_market ? 'yes' : '' ) . '</td><td>' . (int) $r->other_lane_count . '</td><td>' . ( $r->is_test ? 'yes' : '' ) . '</td></tr>';
        }
        if ( ! $rows ) {
            echo '<tr><td colspan="11">No requests yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function check_row( $key, $label, $help, $s ) {
        echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $help ) . '</label></td></tr>';
    }

    private function render_request( $id ) {
        $r = DH_OR_Store::get_request( $id );
        echo '<p><a href="' . esc_url( add_query_arg( 'page', 'dh-owner-requests', admin_url( 'admin.php' ) ) ) . '">&larr; All requests</a></p>';
        if ( ! $r ) {
            echo '<p>No such request.</p>';
            return;
        }
        echo '<h2>Request #' . (int) $r->id . '</h2><table class="widefat striped"><tbody>';
        foreach ( (array) $r as $k => $v ) {
            if ( in_array( $k, array( 'owner_token_hash', 'ip_hash' ), true ) ) {
                continue;
            }
            echo '<tr><th style="width:200px">' . esc_html( $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
        }
        echo '</tbody></table><h3>Routes</h3><table class="widefat striped"><thead><tr><th>#</th><th>Trainer</th><th>Lane</th><th>Miles</th><th>Score</th><th>Fit</th><th>Status</th><th>Outcome</th><th>Ledger</th></tr></thead><tbody>';
        foreach ( DH_OR_Store::routes_for( $id ) as $rt ) {
            echo '<tr><td>' . (int) $rt->rank_pos . '</td><td><a href="' . esc_url( get_permalink( $rt->profile_id ) ) . '">' . esc_html( get_the_title( $rt->profile_id ) ) . '</a> (' . (int) $rt->profile_id . ')</td><td>' . esc_html( $rt->lane ) . '</td><td>' . esc_html( $rt->distance ) . '</td><td>' . esc_html( $rt->score ) . '</td><td>' . esc_html( $rt->fit_notes ) . '</td><td>' . esc_html( $rt->status ) . '</td><td>' . esc_html( $rt->outcome ) . '</td><td>' . esc_html( $rt->ledger_state . ' ' . $rt->ledger_note ) . '</td></tr>';
        }
        echo '</tbody></table><h3>Events and dry-run messages</h3>';
        foreach ( DH_OR_Store::events_for( $id ) as $e ) {
            $d = json_decode( (string) $e->detail, true );
            echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:10px 14px;margin:8px 0;"><strong>' . esc_html( $e->event ) . '</strong> &middot; ' . esc_html( $e->at ) . ( $e->route_id ? ' &middot; route ' . (int) $e->route_id : '' );
            if ( 'message_dry_run' === $e->event && is_array( $d ) ) {
                echo '<br>To: ' . esc_html( $d['to'] ) . ' (' . esc_html( $d['role'] ) . ', ' . esc_html( $d['kind'] ) . ')<br>Subject: ' . esc_html( $d['subject'] ) . '<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;">' . esc_html( $d['text'] ) . '</pre>';
            } elseif ( $d ) {
                echo '<pre style="white-space:pre-wrap;margin:6px 0 0;">' . esc_html( wp_json_encode( $d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
            }
            echo '</div>';
        }
    }
}
