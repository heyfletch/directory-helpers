<?php
/**
 * Course Box Module
 *
 * The free dog training course signup box (Fluent Form in option dh_course_box_form, default 13).
 * Copy and placements come from the course plan (marketing-factory/puppy-course/build/signup-form.md):
 * - Guide pages (children of the guide hubs in GUIDE_HUBS, and the hubs): full box after the page content.
 * - City, state and profile pages: the slim box right after the Owner Requests box, in the same slot.
 * - [dh_course_box placement="..." size="full|slim"] anywhere else.
 *
 * Tracking: puppy_box_view (once, when the box scrolls into view) and puppy_signup (successful submit,
 * before the confirm click) go to GA4 and AnalyticsWP with placement and page_url. The landing page
 * (LANDING_ID) carries its own form; the script tracks it with placement "landing".
 *
 * Kill switch: option dh_course_box_off = 1 hides every injected box.
 *
 * @package Directory_Helpers
 * @subpackage Course_Box
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_Course_Box {

    const LANDING_ID = 144023;

    /** Guide hubs: /guides/, the dog trainer questions hub, the pet insurance hub. */
    const GUIDE_HUBS = array( 144070, 144178, 143612 );

    /** Bricks element id the Owner Requests box renders before, per page type (DH_OR_Box::SLOTS). */
    const SLOTS = array(
        'city-listing'  => 'kyoamc',
        'state-listing' => 'revsah',
        'profile'       => 'lwwtbo',
    );

    private static $rendered = false;

    public function __construct() {
        add_shortcode( 'dh_course_box', array( $this, 'shortcode' ) );
        // After the Owner Requests box (priority 10) so the course box lands between it and the slot section.
        add_filter( 'bricks/frontend/render_element', array( $this, 'slot' ), 11, 2 );
        add_filter( 'bricks/frontend/render_element', array( $this, 'guide' ), 11, 2 );
        add_action( 'wp_enqueue_scripts', array( $this, 'landing_assets' ) );
    }

    private static function off() {
        return (bool) get_option( 'dh_course_box_off', 0 );
    }

    private static function form_id() {
        return (int) get_option( 'dh_course_box_form', 13 );
    }

    public static function is_guide( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $post_id || 'page' !== get_post_type( $post_id ) ) {
            return false;
        }
        if ( in_array( $post_id, self::GUIDE_HUBS, true ) ) {
            return true;
        }
        return in_array( (int) wp_get_post_parent_id( $post_id ), self::GUIDE_HUBS, true );
    }

    public function slot( $html, $element ) {
        if ( self::$rendered || self::off() || ! is_singular( array_keys( self::SLOTS ) ) ) {
            return $html;
        }
        $type = get_post_type( get_queried_object_id() );
        if ( ! isset( self::SLOTS[ $type ] ) || ! isset( $element->id ) || self::SLOTS[ $type ] !== $element->id ) {
            return $html;
        }
        // A closed or restricted trainer's own page does not carry the box.
        if ( 'profile' === $type && class_exists( 'DH_Profile_Status_Notice' ) && in_array( (int) get_queried_object_id(), DH_Profile_Status_Notice::excluded_profile_ids(), true ) ) {
            return $html;
        }
        $placement = array( 'city-listing' => 'city-page', 'state-listing' => 'state-page', 'profile' => 'profile' );
        $box       = $this->render( $placement[ $type ], 'slim' );
        // The Owner Requests box was prepended to this section's html; the course box goes between the two.
        $at = strpos( $html, '<section class="dh-rb" id="ask-trainers"' );
        if ( 0 === $at ) {
            $end = strpos( $html, '</section>' );
            // The request box holds no nested section, so its first closing tag is its own.
            if ( false !== $end ) {
                $end += strlen( '</section>' );
                return substr( $html, 0, $end ) . $box . substr( $html, $end );
            }
        }
        return $box . $html;
    }

    public function guide( $html, $element ) {
        if ( self::$rendered || self::off() || ! isset( $element->name ) || 'post-content' !== $element->name ) {
            return $html;
        }
        if ( ! is_page() || ! self::is_guide( get_queried_object_id() ) ) {
            return $html;
        }
        return $html . $this->render( 'guide', 'full' );
    }

    public function shortcode( $atts = array() ) {
        $atts = shortcode_atts( array( 'placement' => 'other', 'size' => 'full' ), $atts, 'dh_course_box' );
        return $this->render( sanitize_key( $atts['placement'] ), 'slim' === $atts['size'] ? 'slim' : 'full' );
    }

    public function render( $placement, $size = 'full' ) {
        if ( self::$rendered ) {
            return '';
        }
        $form_id = self::form_id();
        if ( ! $form_id ) {
            return '';
        }
        self::$rendered = true;
        $this->enqueue();

        $title = 'Free dog training course by email';
        $lead  = 'slim' === $size
            ? 'One short lesson a week.'
            : 'One short lesson a week for your young puppy, teenage dog, adult dog, or rescue. Add your ZIP to see trainers near you who list each week\'s topic.';

        $out  = '<section class="dh-rb dh-cb dh-cb--' . esc_attr( $size ) . '" id="free-course" data-dh-cb data-placement="' . esc_attr( $placement ) . '">';
        $out .= '<div class="dh-rb__inner"><div class="dh-rb__card dh-cb__card">';
        $out .= '<div class="dh-rb__intro dh-cb__intro"><h2 class="dh-rb__title dh-cb__title">' . esc_html( $title ) . '</h2>';
        $out .= '<p class="dh-rb__lead dh-cb__lead">' . esc_html( $lead ) . '</p>';
        $out .= '<p class="dh-rb__note dh-cb__note">Free. One-click unsubscribe. <a href="' . esc_url( get_permalink( self::LANDING_ID ) ) . '">See the lessons</a></p></div>';
        $out .= '<div class="dh-rb__form dh-cb__form">' . do_shortcode( '[fluentform id="' . $form_id . '"]' ) . '</div>';
        $out .= '</div></div></section>';
        return $out;
    }

    public function landing_assets() {
        if ( is_page( self::LANDING_ID ) ) {
            $this->enqueue_script();
        }
    }

    private function enqueue() {
        $or_dir = DIRECTORY_HELPERS_PATH . 'modules/owner-requests/assets/';
        $or_url = DIRECTORY_HELPERS_URL . 'modules/owner-requests/assets/';
        wp_enqueue_style( 'dh-or-box', $or_url . 'box.css', array(), (string) filemtime( $or_dir . 'box.css' ) );
        $dir = __DIR__ . '/assets/';
        $url = DIRECTORY_HELPERS_URL . 'modules/course-box/assets/';
        wp_enqueue_style( 'dh-course-box', $url . 'course-box.css', array( 'dh-or-box' ), (string) filemtime( $dir . 'course-box.css' ) );
        $this->enqueue_script();
    }

    private function enqueue_script() {
        $dir = __DIR__ . '/assets/';
        $url = DIRECTORY_HELPERS_URL . 'modules/course-box/assets/';
        wp_enqueue_script( 'dh-course-box', $url . 'course-box.js', array(), (string) filemtime( $dir . 'course-box.js' ), true );
        wp_add_inline_script( 'dh-course-box', 'window.dhCourseBox=' . wp_json_encode( array( 'formId' => self::form_id() ) ) . ';', 'before' );
    }
}
