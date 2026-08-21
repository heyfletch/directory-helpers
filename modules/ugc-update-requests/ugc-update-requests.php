<?php
/**
 * UGC Update Requests Module
 *
 * Profile update requests on the Update Your Profile Fluent Form.
 *
 * Two ways in:
 *   ?t=<token>  magic link we emailed - verified, the trainer themselves.
 *   ?pid=<id>   the "update your profile" link in the Owner's Box on a
 *               profile page - unverified, anyone can follow it, so those
 *               entries carry a profile_id with an empty token and must be
 *               checked against the trainer before anything is applied.
 *
 * @package Directory_Helpers
 * @subpackage UGC_Update_Requests
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_UGC_Update_Requests {

    /**
     * Fluent Forms form ID for the Update Your Profile form.
     */
    const FORM_ID = 8;

    /**
     * Token validity window in seconds (12 months).
     */
    const TOKEN_TTL = 31536000;

    /**
     * Days a trainer must wait between submissions.
     */
    const RESUBMIT_DAYS = 30;

    /**
     * Form field name => ACF field name map for prefilling.
     */
    const PREFILL_MAP = array(
        'phone'         => 'phone',
        'website'       => 'url',
        'booking_url'   => 'booking_url',
        'hours'         => 'hours',
        'facebook_url'  => 'facebook_url',
        'instagram_url' => 'instagram_url',
        'service_types' => 'service_types',
    );

    public function __construct() {
        add_filter( 'fluentform/validation_errors', array( $this, 'validate_token' ), 10, 4 );
        add_filter( 'fluentform/rendering_form', array( $this, 'prefill_form' ), 10, 1 );
        add_action( 'fluentform/submission_inserted', array( $this, 'record_submission' ), 10, 3 );
        add_action( 'template_redirect', array( $this, 'nocache_token_page' ) );
        add_shortcode( 'dh_update_target', array( $this, 'shortcode_update_target' ) );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'dh-ugc issue-token', array( $this, 'cli_issue_token' ) );
            WP_CLI::add_command( 'dh-ugc resolve-token', array( $this, 'cli_resolve_token' ) );
        }
    }

    /**
     * Never let LiteSpeed cache an identified update page (prefilled per-trainer data).
     */
    public function nocache_token_page() {
        if ( ( isset( $_GET['t'] ) || isset( $_GET['pid'] ) ) && is_page( 'update-profile' ) ) {
            do_action( 'litespeed_control_set_nocache', 'dh-ugc identified update page' );
        }
    }

    /**
     * The profile this page visit is about, from ?t= (verified) or ?pid= (not).
     */
    public function requested_profile_id() {
        if ( isset( $_GET['t'] ) ) {
            $post_id = $this->resolve_token( sanitize_text_field( wp_unslash( $_GET['t'] ) ) );
            if ( $post_id ) {
                return $post_id;
            }
        }
        if ( isset( $_GET['pid'] ) ) {
            return $this->resolve_profile_id( wp_unslash( $_GET['pid'] ) );
        }
        return 0;
    }

    /**
     * Validate a raw ?pid= value. Only live profiles, so the link cannot be used
     * to fish for drafts. Returns 0 if it is not one.
     */
    public function resolve_profile_id( $raw ) {
        $post_id = (int) $raw;
        if ( $post_id <= 0 ) {
            return 0;
        }
        $post = get_post( $post_id );
        if ( ! $post || 'profile' !== $post->post_type || 'publish' !== $post->post_status ) {
            return 0;
        }
        return $post_id;
    }

    /**
     * [dh_update_target] - tells the trainer which profile the form will change,
     * or how to get here properly when the link did not name one.
     */
    public function shortcode_update_target() {
        $post_id = $this->requested_profile_id();
        if ( ! $post_id ) {
            return sprintf(
                '<p class="dh-update-target dh-update-target--unknown">This link did not tell us which profile to update. Open your profile page and use the <strong>Update Your Info</strong> link in the Listing Tools box, or the link from your Goody Doggy email. Not listed yet? <a href="%s">Get listed</a>.</p>',
                esc_url( home_url( '/get-listed/' ) )
            );
        }
        $city  = function_exists( 'get_field' ) ? (string) get_field( 'city', $post_id ) : '';
        $state = function_exists( 'get_field' ) ? (string) get_field( 'state', $post_id ) : '';
        $where = trim( $city . ( $state ? ', ' . $state : '' ), ', ' );

        return sprintf(
            '<p class="dh-update-target">You are updating <strong>%1$s</strong>%2$s. <a href="%3$s">View this profile</a>. Not your business? <a href="%4$s">Get listed instead</a>.</p>',
            esc_html( get_the_title( $post_id ) ),
            $where ? ' in ' . esc_html( $where ) : '',
            esc_url( get_permalink( $post_id ) ),
            esc_url( home_url( '/get-listed/' ) )
        );
    }

    /**
     * Prefill the update form from the token's profile ACF data.
     */
    public function prefill_form( $form ) {
        if ( (int) $form->id !== self::FORM_ID ) {
            return $form;
        }
        $post_id = $this->requested_profile_id();
        if ( ! $post_id || ! function_exists( 'get_field' ) ) {
            return $form;
        }
        if ( isset( $form->fields['fields'] ) && is_array( $form->fields['fields'] ) ) {
            $city = (string) get_field( 'city', $post_id );
            $this->prefill_fields_walk( $form->fields['fields'], $post_id, $city );
        }
        return $form;
    }

    /**
     * Recursively set field values (containers hold nested fields).
     */
    private function prefill_fields_walk( array &$fields, $post_id, $city ) {
        foreach ( $fields as &$field ) {
            if ( isset( $field['columns'] ) && is_array( $field['columns'] ) ) {
                foreach ( $field['columns'] as &$column ) {
                    if ( isset( $column['fields'] ) && is_array( $column['fields'] ) ) {
                        $this->prefill_fields_walk( $column['fields'], $post_id, $city );
                    }
                }
                unset( $column );
                continue;
            }
            $name = isset( $field['attributes']['name'] ) ? $field['attributes']['name'] : '';
            if ( $city && isset( $field['settings']['label'] ) ) {
                $field['settings']['label'] = str_replace( '[city]', $city, $field['settings']['label'] );
            }
            if ( ! $name || ! isset( self::PREFILL_MAP[ $name ] ) ) {
                continue;
            }
            $value = get_field( self::PREFILL_MAP[ $name ], $post_id );
            if ( 'input_checkbox' === $field['element'] ) {
                // ACF checkbox may return values or {value,label} pairs depending on return format.
                $keys = array();
                foreach ( (array) $value as $item ) {
                    $keys[] = is_array( $item ) ? (string) $item['value'] : (string) $item;
                }
                $field['attributes']['value'] = $keys;
            } elseif ( is_scalar( $value ) && '' !== (string) $value ) {
                $field['attributes']['value'] = (string) $value;
            }
        }
        unset( $field );
    }

    /**
     * Stamp the profile when an update request is submitted (drives the resubmit window).
     */
    public function record_submission( $entry_id, $form_data, $form ) {
        if ( (int) $form->id !== self::FORM_ID ) {
            return;
        }
        $token_id = $this->resolve_token( isset( $form_data['token'] ) ? $form_data['token'] : '' );
        if ( $token_id ) {
            update_post_meta( $token_id, 'update_request_last', current_time( 'mysql' ) );
            return;
        }
        $pid = $this->resolve_profile_id( isset( $form_data['profile_id'] ) ? $form_data['profile_id'] : 0 );
        if ( $pid ) {
            // Separate stamp so unverified submissions can never lock out the owner's magic link.
            update_post_meta( $pid, 'update_request_last_unverified', current_time( 'mysql' ) );
        }
    }

    /**
     * Find the profile post ID matching a raw token. Returns 0 if none/expired.
     */
    public function resolve_token( $raw_token ) {
        $raw_token = trim( (string) $raw_token );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $raw_token ) ) {
            return 0;
        }
        $hash  = hash( 'sha256', $raw_token );
        $posts = get_posts( array(
            'post_type'      => 'profile',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => 'update_token_hash',
                    'value' => $hash,
                ),
            ),
        ) );
        if ( empty( $posts ) ) {
            return 0;
        }
        $post_id = (int) $posts[0];
        $issued  = get_post_meta( $post_id, 'update_token_issued', true );
        if ( $issued && ( time() - strtotime( $issued ) ) > self::TOKEN_TTL ) {
            return 0;
        }
        return $post_id;
    }

    /**
     * Validate the update form: it must name a profile we can find.
     */
    public function validate_token( $errors, $formData, $form, $fields ) {
        if ( (int) $form->id !== self::FORM_ID ) {
            return $errors;
        }
        $token_id = $this->resolve_token( isset( $formData['token'] ) ? $formData['token'] : '' );
        $post_id  = $token_id ? $token_id : $this->resolve_profile_id( isset( $formData['profile_id'] ) ? $formData['profile_id'] : 0 );
        if ( ! $post_id ) {
            $errors['token'] = array( 'We could not tell which profile this is for. Please use the link from your Goody Doggy email or the "update your profile" link on your profile page.' );
            return $errors;
        }
        $last_key = $token_id ? 'update_request_last' : 'update_request_last_unverified';
        $last     = get_post_meta( $post_id, $last_key, true );
        if ( $last && ( time() - strtotime( $last ) ) < self::RESUBMIT_DAYS * DAY_IN_SECONDS ) {
            $errors['token'] = array( 'You recently sent us an update - we can accept one submission every ' . self::RESUBMIT_DAYS . ' days. We are still working through the changes you already sent.' );
        }
        return $errors;
    }

    /**
     * Issue (or re-issue) a token for a profile and print the magic link.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The profile post ID.
     *
     * [--email=<address>]
     * : The address the link is being sent to. Recorded as the profile's
     * contact email when it has none - mailing a magic link there is the
     * strongest proof of contact we get. Never overwrites a stored address;
     * a different one is parked in contact_email_pending.
     *
     * ## EXAMPLES
     *
     *     wp dh-ugc issue-token 119134
     *     wp dh-ugc issue-token 119134 --email=info@example.com
     */
    public function cli_issue_token( $args, $assoc_args = array() ) {
        $post_id = (int) $args[0];
        $post    = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'profile' ) {
            WP_CLI::error( "Post {$post_id} is not a profile." );
        }

        if ( ! empty( $assoc_args['email'] ) && class_exists( 'DH_Contact_Email' ) ) {
            $contact = new DH_Contact_Email();
            $email   = $contact->clean_email( $assoc_args['email'] );
            if ( ! $email ) {
                WP_CLI::error( 'Not a valid email address.' );
            }
            $result = $contact->record( $post_id, $email, 'manual' );
            WP_CLI::log( sprintf( 'Contact email %s: %s', $result, $email ) );
        }

        $raw  = bin2hex( random_bytes( 16 ) );
        $hash = hash( 'sha256', $raw );
        update_post_meta( $post_id, 'update_token_hash', $hash );
        update_post_meta( $post_id, 'update_token_issued', current_time( 'mysql' ) );
        $link = home_url( '/update-profile/?t=' . $raw );
        WP_CLI::log( $link );
        WP_CLI::success( "Token issued for profile {$post_id} ({$post->post_title}). Valid 12 months." );
    }

    /**
     * Resolve a raw token to its profile (for processing update requests).
     *
     * ## OPTIONS
     *
     * <token>
     * : The raw token from a form entry.
     */
    public function cli_resolve_token( $args ) {
        $post_id = $this->resolve_token( $args[0] );
        if ( ! $post_id ) {
            WP_CLI::error( 'Invalid or expired token.' );
        }
        $post = get_post( $post_id );
        WP_CLI::log( wp_json_encode( array(
            'post_id' => $post_id,
            'title'   => $post->post_title,
            'status'  => $post->post_status,
            'url'     => get_permalink( $post_id ),
        ) ) );
    }
}
