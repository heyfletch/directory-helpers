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
 * (LANDING_ID) and the puppy guides carry their own copy of the form; the script tracks those as
 * "landing" and "guide".
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
        // Puppy guides already carry the course form inside their text; those get tracking only.
        if ( false !== strpos( $html, 'fluent_form_' . self::form_id() ) || false !== strpos( $html, 'data-form_id="' . self::form_id() . '"' ) ) {
            return $html;
        }
        return $html . $this->render( 'guide', 'full' );
    }

    public function shortcode( $atts = array() ) {
        $atts = shortcode_atts( array( 'placement' => 'other', 'size' => 'full' ), $atts, 'dh_course_box' );
        $size = in_array( $atts['size'], array( 'slim', 'feature' ), true ) ? $atts['size'] : 'full';
        return $this->render( sanitize_key( $atts['placement'] ), $size );
    }

    /** The first four lessons each dog type gets, as their real email subject and preview lines (n8n lessons table, pick_lesson.py order). */
    private static function lesson_preview() {
        $come  = array( 'Be the best thing your dog can run to', 'Make coming to you the best part of your dog\'s day.' );
        $leash = array( 'Stop leash pulling: when the leash goes tight, you stop', 'Your dog pulls because pulling works. Here\'s how to change that.' );
        return array(
            'young_puppy' => array( 'label' => 'Young puppy', 'weeks' => array(
                array( 'Your puppy\'s first week, made simple', 'A daily plan for meals, potty breaks, play, and naps.' ),
                array( 'Turn the crate into your puppy\'s favorite spot', 'A cozy crate makes rest and travel easier for both of you.' ),
                array( 'Potty training, one trip outside at a time', 'Lots of trips outside and quick treats teach your puppy where to go.' ),
                array( 'Those sharp little puppy teeth', 'Mouthing is normal. Here\'s how to point those teeth at a toy instead.' ),
            ) ),
            'teenage_dog' => array( 'label' => 'Teenage dog', 'weeks' => array(
                array( 'Did your dog just become a teenager?', 'Training slipping a little? Keep the basics going.' ),
                $come,
                array( 'Why your good dog suddenly ignores you', 'A slump is common. A few small habits bring training back.' ),
                $leash,
            ) ),
            'adult_dog'   => array( 'label' => 'Adult dog', 'weeks' => array(
                $come,
                array( 'A better hello than jumping', 'Save the big welcome for four paws on the floor.' ),
                array( 'Ready for your next adventure together?', 'Canine Good Citizen is a fun next step after the basics.' ),
                $leash,
            ) ),
            'rescue'      => array( 'label' => 'Rescue', 'weeks' => array(
                array( 'Your rescue dog\'s first days home', 'A new home is a lot to take in. Go slow and let trust grow.' ),
                array( 'A cozy spot and a steady routine', 'Days your dog can predict help a nervous dog relax.' ),
                array( 'Potty training a grown-up rescue', 'Some adult dogs were never taught. A simple routine helps.' ),
                array( 'When your dog gets nervous about people or dogs', 'Let your dog set the pace. Pushing makes it worse.' ),
            ) ),
        );
    }

    /** Feature layout only: dog type first, plainer labels, and a clearer button, without changing the saved form. */
    public static function feature_form( $form ) {
        if ( (int) $form->id !== self::form_id() || empty( $form->fields['fields'] ) ) {
            return $form;
        }
        $first = array();
        $rest  = array();
        foreach ( $form->fields['fields'] as $field ) {
            $name = isset( $field['attributes']['name'] ) ? $field['attributes']['name'] : '';
            if ( 'dog_type' === $name ) {
                $field['settings']['label'] = 'Pick your dog';
                $first[] = $field;
                continue;
            }
            if ( 'zip' === $name ) {
                $field['settings']['help_message'] = 'See trainers near you who teach each week\'s topic.';
            }
            $rest[] = $field;
        }
        $form->fields['fields'] = array_merge( $first, $rest );
        $form->fields['submitButton']['settings']['button_ui']['text'] = 'Send me the lessons';
        return $form;
    }

    private function render_feature( $placement, $form_id ) {
        $lessons = self::lesson_preview();
        $start   = $lessons['young_puppy'];
        $rows    = '';
        foreach ( $start['weeks'] as $i => $week ) {
            $rows .= '<li class="dh-cbf__mail"><span class="dh-cbf__from" aria-hidden="true"></span><span class="dh-cbf__text"><span class="dh-cbf__top"><b>Goody Doggy</b><em>Week ' . ( $i + 1 ) . '</em></span>'
                . '<span class="dh-cbf__subj">' . esc_html( $week[0] ) . '</span><span class="dh-cbf__prev">' . esc_html( $week[1] ) . '</span></span></li>';
        }

        add_filter( 'fluentform/rendering_form', array( __CLASS__, 'feature_form' ), 20 );
        $form = do_shortcode( '[fluentform id="' . $form_id . '"]' );
        remove_filter( 'fluentform/rendering_form', array( __CLASS__, 'feature_form' ), 20 );

        $out  = '<section class="dh-rb dh-cb dh-cb--feature" id="free-course" data-dh-cb data-placement="' . esc_attr( $placement ) . '" data-lessons="' . esc_attr( wp_json_encode( $lessons ) ) . '">';
        $out .= '<div class="dh-cbf">';
        $out .= '<div class="dh-cbf__pitch">';
        $out .= '<p class="dh-cbf__eyebrow">Free email course</p>';
        $out .= '<h2 class="dh-cbf__title">Your dog&rsquo;s next good habit starts Tuesday</h2>';
        $out .= '<p class="dh-cbf__lead">Pick your dog&rsquo;s stage, from a brand-new puppy to a rescue still settling in. Each Tuesday you get one short lesson and one thing to try that week.</p>';
        $out .= '<div class="dh-cbf__inbox"><p class="dh-cbf__inbox-head"><span>Your inbox</span><span class="dh-cbf__for">' . esc_html( $start['label'] ) . '</span></p>';
        $out .= '<ol class="dh-cbf__mails" aria-live="polite">' . $rows . '</ol></div>';
        $out .= '<ul class="dh-cbf__trust"><li>Free</li><li>One email each Tuesday</li><li>Unsubscribe anytime</li></ul>';
        $out .= '</div>';
        $out .= '<div class="dh-cbf__form">' . $form;
        $out .= '<p class="dh-cbf__after">Confirm your email, and your first lesson comes Tuesday.</p>';
        $out .= '<p class="dh-cbf__source">Based on advice from the American Kennel Club and veterinary behavior experts. <a href="' . esc_url( get_permalink( self::LANDING_ID ) ) . '">See the full course</a></p>';
        $out .= '</div></div></section>';
        return $out;
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

        if ( 'feature' === $size ) {
            return $this->render_feature( $placement, $form_id );
        }

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
        if ( is_page( self::LANDING_ID ) || ( is_page() && self::is_guide( get_queried_object_id() ) ) ) {
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
        wp_add_inline_script( 'dh-course-box', 'window.dhCourseBox=' . wp_json_encode( array(
            'formId'    => self::form_id(),
            'placement' => is_page( self::LANDING_ID ) ? 'landing' : ( is_page() && self::is_guide( get_queried_object_id() ) ? 'guide' : 'other' ),
        ) ) . ';', 'before' );
    }
}
