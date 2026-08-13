<?php
/**
 * UGC Update Requests Module
 *
 * Magic-link profile update requests: token issuance (WP-CLI) and
 * token validation on the Update Your Profile Fluent Form.
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

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'dh-ugc issue-token', array( $this, 'cli_issue_token' ) );
            WP_CLI::add_command( 'dh-ugc resolve-token', array( $this, 'cli_resolve_token' ) );
        }
    }

    /**
     * Never let LiteSpeed cache a tokened update page (prefilled per-trainer data).
     */
    public function nocache_token_page() {
        if ( isset( $_GET['t'] ) && is_page( 'update-profile' ) ) {
            do_action( 'litespeed_control_set_nocache', 'dh-ugc tokened update page' );
        }
    }

    /**
     * Prefill the update form from the token's profile ACF data.
     */
    public function prefill_form( $form ) {
        if ( (int) $form->id !== self::FORM_ID || ! isset( $_GET['t'] ) ) {
            return $form;
        }
        $post_id = $this->resolve_token( sanitize_text_field( wp_unslash( $_GET['t'] ) ) );
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
        $token   = isset( $form_data['token'] ) ? $form_data['token'] : '';
        $post_id = $this->resolve_token( $token );
        if ( $post_id ) {
            update_post_meta( $post_id, 'update_request_last', current_time( 'mysql' ) );
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
     * Validate the token field on the update form.
     */
    public function validate_token( $errors, $formData, $form, $fields ) {
        if ( (int) $form->id !== self::FORM_ID ) {
            return $errors;
        }
        $token   = isset( $formData['token'] ) ? $formData['token'] : '';
        $post_id = $this->resolve_token( $token );
        if ( ! $post_id ) {
            $errors['token'] = array( 'This update link is invalid or has expired. Please use the link from your Goody Doggy email, or contact us for a new one.' );
            return $errors;
        }
        $last = get_post_meta( $post_id, 'update_request_last', true );
        if ( $last && ( time() - strtotime( $last ) ) < self::RESUBMIT_DAYS * DAY_IN_SECONDS ) {
            $errors['token'] = array( 'You recently sent us an update - we can accept one submission every ' . self::RESUBMIT_DAYS . ' days. If something urgent needs fixing, just reply to your Goody Doggy email.' );
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
     * ## EXAMPLES
     *
     *     wp dh-ugc issue-token 119134
     */
    public function cli_issue_token( $args ) {
        $post_id = (int) $args[0];
        $post    = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'profile' ) {
            WP_CLI::error( "Post {$post_id} is not a profile." );
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
