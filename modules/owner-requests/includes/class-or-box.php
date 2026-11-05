<?php
/**
 * Owner Requests: the request box.
 *
 * [dh_request_box] renders the box on any page (the slot the other bets ask
 * for). While `preview_only` is on, it renders only for logged-in users with
 * manage_options. With `auto_inject` on, the box renders right before the Bricks
 * section named in SLOTS (the spots Joe chose on Oct 23: under the trainer list and
 * before the Recommended Resource banner on city and state pages, above Listing Tools
 * for Trainers on profiles), with no template change. If that section does not
 * render, the box falls back to the `bricks_before_footer` hook.
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

    /** Bricks element id of the section the box renders before, per page type. */
    const SLOTS = array(
        'city-listing'  => 'kyoamc',
        'state-listing' => 'revsah',
        'profile'       => 'lwwtbo',
    );

    public function __construct() {
        add_shortcode( 'dh_request_box', array( $this, 'shortcode' ) );
        add_filter( 'bricks/frontend/render_element', array( $this, 'slot' ), 10, 2 );
        add_action( 'bricks_before_footer', array( $this, 'auto_inject' ), 5 );
    }

    public function slot( $html, $element ) {
        if ( self::$rendered || ! DH_OR_Settings::get( 'auto_inject' ) || ! is_singular( array_keys( self::SLOTS ) ) ) {
            return $html;
        }
        $type = get_post_type( get_queried_object_id() );
        if ( ! isset( self::SLOTS[ $type ] ) || ! isset( $element->id ) || self::SLOTS[ $type ] !== $element->id ) {
            return $html;
        }
        return $this->render() . $html;
    }

    public function auto_inject() {
        if ( self::$rendered || ! DH_OR_Settings::get( 'auto_inject' ) ) {
            return;
        }
        if ( ! is_singular( array( 'city-listing', 'state-listing', 'profile' ) ) ) {
            return;
        }
        echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    /** [dh_request_box heading="h1"] makes the box title the page heading. */
    public function shortcode( $atts = array() ) {
        $atts = shortcode_atts( array( 'heading' => 'h2' ), $atts, 'dh_request_box' );
        return $this->render( 'h1' === $atts['heading'] ? 'h1' : 'h2' );
    }

    /** Page type the box is on: city, state, profile or other. */
    private function context() {
        $post = get_post();
        $type = 'other';
        $id   = 0;
        if ( $post && is_singular() ) {
            $id = (int) $post->ID;
            if ( 'city-listing' === $post->post_type ) {
                $type = 'city';
            } elseif ( 'state-listing' === $post->post_type ) {
                $type = 'state';
            } elseif ( 'profile' === $post->post_type ) {
                $type = 'profile';
            }
        }
        return array( 'type' => $type, 'post_id' => $id );
    }

    public function render( $heading = 'h2' ) {
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
        $lead  = 'Tell us what is going on with your dog. We send your note to up to three trainers near you, and it\'s up to them to reply to you.';
        $note  = 'We pick trainers who work on your dog\'s problem. Trainers see your email only after they open your request.';

        $out  = '<section class="dh-rb" id="ask-trainers" data-dh-rb data-source-type="' . esc_attr( $ctx['type'] ) . '" data-source-post="' . (int) $ctx['post_id'] . '"'
            . ' data-viewed-profile="' . ( 'profile' === $ctx['type'] ? (int) $ctx['post_id'] : 0 ) . '">';
        $out .= '<div class="dh-rb__inner"><div class="dh-rb__card">';
        $out .= '<div class="dh-rb__intro"><' . $heading . ' class="dh-rb__title">' . esc_html( $title ) . '</' . $heading . '>';
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
