<?php
/**
 * Owner Requests: the request box.
 *
 * [dh_request_box] renders the box on any page (the slot the other bets ask
 * for). While `preview_only` is on, it renders only for logged-in users with
 * manage_options. Until the Bricks slot element is placed in the city-listing
 * and profile templates, `auto_inject` adds the box on those two page types at
 * the Bricks `bricks_before_footer` hook, with no template change.
 *
 * Tracking: the script emits browser events (see assets/box.js) and wires none
 * of them to GA4 or AnalyticsWP; the proposal for that goes to Joe first.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_OR_Box {

    private static $rendered = false;

    public function __construct() {
        add_shortcode( 'dh_request_box', array( $this, 'shortcode' ) );
        add_action( 'bricks_before_footer', array( $this, 'auto_inject' ), 5 );
    }

    public function auto_inject() {
        if ( self::$rendered || ! DH_OR_Settings::get( 'auto_inject' ) ) {
            return;
        }
        if ( ! is_singular( array( 'city-listing', 'profile' ) ) ) {
            return;
        }
        echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    public function shortcode( $atts = array() ) {
        return $this->render();
    }

    /** Page type the box is on: city, profile or other. */
    private function context() {
        $post = get_post();
        $type = 'other';
        $id   = 0;
        if ( $post && is_singular() ) {
            $id = (int) $post->ID;
            if ( 'city-listing' === $post->post_type ) {
                $type = 'city';
            } elseif ( 'profile' === $post->post_type ) {
                $type = 'profile';
            }
        }
        return array( 'type' => $type, 'post_id' => $id );
    }

    public function render() {
        if ( self::$rendered ) {
            return '';
        }
        if ( ! DH_OR_Settings::visitor_allowed() ) {
            return '';
        }
        $form_id = (int) DH_OR_Settings::get( 'form_id' );
        if ( ! $form_id || DH_OR_Settings::paused() ) {
            return '';
        }
        $ctx = $this->context();

        // A closed or restricted trainer's own page does not carry the box.
        if ( 'profile' === $ctx['type'] && class_exists( 'DH_Profile_Status_Notice' ) && in_array( $ctx['post_id'], DH_Profile_Status_Notice::excluded_profile_ids(), true ) ) {
            return '';
        }

        self::$rendered = true;
        if ( ! DH_OR_Settings::public_open() ) {
            // Preview: this response is for an admin and must never be cached for visitors.
            do_action( 'litespeed_control_set_nocache', 'owner-requests box preview' );
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }
        }
        $this->enqueue();

        $title = 'Ask trainers near you for help';
        $lead  = 'Tell us what is going on with your dog. We send your note to up to five trainers near you, and they reply to you.';
        $note  = 'We pick trainers who work on your dog\'s problem. Trainers see your email only after they open your request.';

        $out  = '<section class="dh-rb" id="ask-trainers" data-dh-rb data-source-type="' . esc_attr( $ctx['type'] ) . '" data-source-post="' . (int) $ctx['post_id'] . '"'
            . ' data-viewed-profile="' . ( 'profile' === $ctx['type'] ? (int) $ctx['post_id'] : 0 ) . '">';
        $out .= '<div class="dh-rb__inner"><div class="dh-rb__card">';
        $out .= '<div class="dh-rb__intro"><h2 class="dh-rb__title">' . esc_html( $title ) . '</h2>';
        $out .= '<p class="dh-rb__lead">' . esc_html( $lead ) . '</p><p class="dh-rb__note">' . esc_html( $note ) . '</p></div>';
        $out .= '<div class="dh-rb__form">' . do_shortcode( '[fluentform id="' . $form_id . '"]' ) . '</div>';
        $out .= '</div></div></section>';
        return $out;
    }

    private function enqueue() {
        $dir = dirname( __DIR__ ) . '/assets/';
        $url = DIRECTORY_HELPERS_URL . 'modules/owner-requests/assets/';
        wp_enqueue_style( 'dh-or-box', $url . 'box.css', array(), (string) filemtime( $dir . 'box.css' ) );
        wp_enqueue_script( 'dh-or-box', $url . 'box.js', array(), (string) filemtime( $dir . 'box.js' ), true );
    }
}
