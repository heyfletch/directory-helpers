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
 * Removal requests (Privacy Request form, "Delete my data") reuse the same
 * one-time token idea: `wp dh-ugc issue-removal-token` prints a
 * /confirm-removal/?r=<token> link that goes only to an address the profile
 * owns. The link page is two-step (GET shows a button, only the POST acts), so
 * mail scanners opening the link change nothing.
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
        add_filter( 'robots_txt', array( __CLASS__, 'robots_disallow_identified' ), 99, 2 );
        add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'nofollow_pid_links' ), 30 );
        add_shortcode( 'dh_update_target', array( $this, 'shortcode_update_target' ) );
        add_action( 'template_redirect', array( $this, 'maybe_render_removal_page' ), 1 );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'dh-ugc issue-token', array( $this, 'cli_issue_token' ) );
            WP_CLI::add_command( 'dh-ugc resolve-token', array( $this, 'cli_resolve_token' ) );
            WP_CLI::add_command( 'dh-ugc resolve-removal', array( $this, 'cli_resolve_removal' ) );
            WP_CLI::add_command( 'dh-ugc issue-removal-token', array( $this, 'cli_issue_removal_token' ) );
            WP_CLI::add_command( 'dh-ugc finish-removal', array( $this, 'cli_finish_removal' ) );
            WP_CLI::add_command( 'dh-ugc privacy-pending', array( $this, 'cli_privacy_pending' ) );
            WP_CLI::add_command( 'dh-ugc owner-run', array( $this, 'cli_owner_run' ) );
        }
    }

    /**
     * Keep crawlers off the per-trainer form URLs (/update-profile/?pid= and ?t=).
     * Every profile links its own copy, so each crawl of one is a crawl a real page
     * did not get. The bare /update-profile/ page stays crawlable.
     */
    public static function robots_disallow_identified( $output, $public ) {
        $rule = 'Disallow: /update-profile/?';
        if ( ! $public || false !== strpos( $output, $rule ) ) {
            return $output;
        }
        $count  = 0;
        $output = preg_replace( '/^(User-agent:[ \t]*\*[ \t]*\r?\n)/mi', '$1' . $rule . "\n", $output, 1, $count );
        if ( ! $count ) {
            $output = rtrim( $output ) . "\n\nUser-agent: *\n" . $rule . "\n";
        }
        return $output;
    }

    /**
     * Mark the Owner's Box "Send us your changes" link nofollow.
     */
    public static function nofollow_pid_links( $content ) {
        if ( ! is_string( $content ) || false === strpos( $content, 'href="/update-profile/?pid=' ) ) {
            return $content;
        }
        return str_replace( '<a href="/update-profile/?pid=', '<a rel="nofollow" href="/update-profile/?pid=', $content );
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

    /* ---------------------------------------------------------------------
     * Privacy requests (Privacy Request form 12)
     *
     * Trainer "Delete my data": the profile removal flow (profile token, post meta).
     * Dog owner delete / anyone's "see my data": the owner flow (owner token, option).
     * Both end on the same two-step /confirm-removal/ page.
     * ------------------------------------------------------------------ */

    const REMOVAL_PATH   = 'confirm-removal';
    const TRAINER_FORMS  = array( 3, 5, 7, 8, 9 );
    const PRIVACY_FORM   = 12;
    const MAILCHIMP_LIST = 'b358110084';

    /**
     * Find the profile a removal request is about and the address that may confirm it.
     *
     * Profile: a goodydoggy.com/profile/<slug> link in the details, else the one profile
     * whose website domain is the requester's (non-free) email domain, else the one
     * profile whose name appears in the details.
     * Owned address: the profile's stored contact email, else the requester's email when
     * its domain is the profile's website domain. Anything else is not proof.
     *
     * @return array post_id, title, status, send_to, basis, reason.
     */
    public function resolve_removal( $email, $details, $name = '' ) {
        global $wpdb;
        $contact = class_exists( 'DH_Contact_Email' ) ? new DH_Contact_Email() : null;
        $email   = $contact ? $contact->clean_email( $email ) : strtolower( trim( (string) $email ) );
        $text    = (string) $details . ' ' . (string) $name;
        $ids     = array();

        if ( preg_match_all( '#goodydoggy\.com/profile/([a-z0-9-]+)#i', $text, $m ) ) {
            foreach ( array_unique( $m[1] ) as $slug ) {
                $p = get_page_by_path( strtolower( $slug ), OBJECT, 'profile' );
                if ( $p ) {
                    $ids[] = (int) $p->ID;
                }
            }
        }
        $ids = array_values( array_unique( $ids ) );

        if ( ! $ids && $contact && $email ) {
            // A profile that already has this exact contact email.
            $ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                DH_Contact_Email::META, $email
            ) ) );
        }

        if ( ! $ids && $contact && $email && ! $contact->is_free_mailbox( $email ) ) {
            $domain = substr( strrchr( $email, '@' ), 1 );
            $rows   = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.ID, pm.meta_value AS url FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'url'
                 WHERE p.post_type = 'profile' AND p.post_status NOT IN ('trash','auto-draft') AND pm.meta_value LIKE %s",
                '%' . $wpdb->esc_like( $domain ) . '%'
            ) );
            foreach ( $rows as $row ) {
                if ( $contact->domain( $row->url ) === $domain ) {
                    $ids[] = (int) $row->ID;
                }
            }
        }

        if ( ! $ids && $contact ) {
            $hay = $contact->name_key( $text );
            foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'profile' AND post_status IN ('publish','private','draft')" ) as $row ) {
                $key = $contact->name_key( $row->post_title );
                if ( strlen( $key ) >= 8 && false !== strpos( $hay, $key ) ) {
                    $ids[] = (int) $row->ID;
                }
            }
        }

        $out = array( 'post_id' => 0, 'title' => '', 'status' => '', 'send_to' => '', 'basis' => '', 'reason' => '' );
        if ( count( $ids ) !== 1 ) {
            $out['reason'] = $ids ? 'more than one profile matches' : 'no profile found';
            return $out;
        }

        $pid            = $ids[0];
        $out['post_id'] = $pid;
        $out['title']   = get_the_title( $pid );
        $out['status']  = get_post_status( $pid );

        $stored = $contact ? $contact->clean_email( get_post_meta( $pid, DH_Contact_Email::META, true ) ) : '';
        if ( $stored ) {
            $out['send_to'] = $stored;
            $out['basis']   = 'contact_email';
            return $out;
        }
        $site = $contact ? $contact->domain( get_post_meta( $pid, 'url', true ) ) : '';
        if ( $contact && $email && $site && substr( strrchr( $email, '@' ), 1 ) === $site ) {
            $out['send_to'] = $email;
            $out['basis']   = 'domain';
            return $out;
        }
        $out['reason'] = $site
            ? 'no stored contact email, and the requester email is not on ' . $site
            : 'no stored contact email and the profile has no website';
        return $out;
    }

    /**
     * Decide whether a privacy request comes from a trainer/business or a dog owner.
     *
     * @return array kind (trainer|owner|unclear), why, profile (resolve_removal result), owner_records.
     */
    public function classify_privacy( $email, $details, $name = '' ) {
        global $wpdb;
        $contact = new DH_Contact_Email();
        $email   = $contact->clean_email( $email );
        $profile = $this->resolve_removal( $email, $details, $name );
        $records = $email ? $this->owner_record_counts( $email ) : array();
        $owned   = array_sum( $records );

        $in_trainer_forms = 0;
        if ( $email ) {
            $in_trainer_forms = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions WHERE form_id IN (" . implode( ',', self::TRAINER_FORMS ) . ") AND status <> 'trashed' AND response LIKE %s",
                '%' . $wpdb->esc_like( $email ) . '%'
            ) );
        }
        $business_words = (bool) preg_match( '/\b(my|our|your)\s+(listing|business|company|profile|website|location|locations|studio|school|training)\b|\bon behalf of\b|\b(llc|inc|ltd)\b|remove this listing|this listing/i', $details );
        $free           = $email && $contact->is_free_mailbox( $email );

        $out = array( 'profile' => $profile, 'owner_records' => $records );
        if ( $profile['post_id'] ) {
            $out['kind'] = 'trainer';
            $out['why']  = 'a profile matches (' . $profile['title'] . ')';
        } elseif ( $in_trainer_forms ) {
            $out['kind'] = 'trainer';
            $out['why']  = 'the email appears in trainer forms';
        } elseif ( $business_words && ! $owned ) {
            $out['kind'] = 'trainer';
            $out['why']  = 'the request talks about a listing or business';
        } elseif ( $owned ) {
            $out['kind'] = 'owner';
            $out['why']  = 'the email is on ' . $owned . ' dog owner record(s)';
        } elseif ( $free ) {
            $out['kind'] = 'owner';
            $out['why']  = 'personal mailbox, no profile, no business wording';
        } else {
            $out['kind'] = 'unclear';
            $out['why']  = 'business-looking email domain but no profile, record, or listing wording matches';
        }
        return $out;
    }

    /* ---- owner data stores ------------------------------------------------ */

    /**
     * Form submission ids holding this email, per form, excluding trainer forms unless $all.
     */
    private function owner_submission_ids( $email, $all = false ) {
        global $wpdb;
        $t   = $wpdb->prefix . 'fluentform_submissions';
        $sql = "SELECT id, form_id FROM {$t} WHERE response LIKE %s";
        if ( ! $all ) {
            $sql .= ' AND form_id NOT IN (' . implode( ',', self::TRAINER_FORMS ) . ')';
        }
        return $wpdb->get_results( $wpdb->prepare( $sql, '%' . $wpdb->esc_like( $email ) . '%' ) );
    }

    private function owner_record_counts( $email ) {
        global $wpdb;
        $counts = array();
        $subs   = $this->owner_submission_ids( $email );
        $subs   = array_filter( $subs, function ( $r ) { return (int) $r->form_id !== self::PRIVACY_FORM; } );
        if ( $subs ) {
            $counts['form_entries'] = count( $subs );
        }
        $n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dh_requests WHERE owner_email = %s", $email ) );
        if ( $n ) {
            $counts['owner_requests'] = $n;
        }
        return $counts;
    }

    private function mailchimp_call( $method, $email, $suffix = '' ) {
        $d = get_option( '_fluentform_mailchimp_details' );
        if ( ! is_array( $d ) || empty( $d['apiKey'] ) || false === strpos( $d['apiKey'], '-' ) ) {
            return array( 'ok' => false, 'code' => 0, 'body' => null );
        }
        $dc  = substr( strrchr( $d['apiKey'], '-' ), 1 );
        $url = 'https://' . $dc . '.api.mailchimp.com/3.0/lists/' . self::MAILCHIMP_LIST . '/members/' . md5( strtolower( $email ) ) . $suffix;
        $res = wp_remote_request( $url, array(
            'method'  => $method,
            'timeout' => 20,
            'headers' => array( 'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $d['apiKey'] ) ),
        ) );
        if ( is_wp_error( $res ) ) {
            return array( 'ok' => false, 'code' => 0, 'body' => null );
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        return array( 'ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => json_decode( wp_remote_retrieve_body( $res ), true ) );
    }

    /**
     * Everything the WordPress side holds about a dog owner's email, readable.
     */
    public function export_owner_data( $email ) {
        global $wpdb;
        $out  = array();
        $forms = $wpdb->get_results( "SELECT id, title FROM {$wpdb->prefix}fluentform_forms", OBJECT_K );
        foreach ( $this->owner_submission_ids( $email, true ) as $r ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT response, created_at FROM {$wpdb->prefix}fluentform_submissions WHERE id = %d", $r->id ) );
            $d   = json_decode( $row->response, true );
            $fields = array();
            foreach ( (array) $d as $k => $v ) {
                if ( 0 === strpos( $k, '_' ) || in_array( $k, array( 'cf-turnstile-response', 'terms-n-condition', 'token' ), true ) ) {
                    continue;
                }
                $fields[ $k ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
            }
            $out['forms'][] = array( 'form' => isset( $forms[ $r->form_id ] ) ? $forms[ $r->form_id ]->title : 'Form ' . $r->form_id, 'date' => $row->created_at, 'fields' => $fields );
        }
        $out['owner_requests'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT created_at, status, source_url, owner_first_name, owner_email, owner_phone, zip, problem_text, chips, dog_age, format, specialties, consent_at FROM {$wpdb->prefix}dh_requests WHERE owner_email = %s", $email
        ), ARRAY_A );
        $out['emails_sent'] = $wpdb->get_results( $wpdb->prepare(
            "SELECT created_at, subject FROM {$wpdb->prefix}fsmpt_email_logs WHERE `to` LIKE %s", '%' . $wpdb->esc_like( $email ) . '%'
        ), ARRAY_A );
        $mc = $this->mailchimp_call( 'GET', $email );
        $out['newsletter'] = $mc['ok'] ? array( 'status' => isset( $mc['body']['status'] ) ? $mc['body']['status'] : '', 'since' => isset( $mc['body']['timestamp_signup'] ) ? $mc['body']['timestamp_signup'] : '' ) : null;
        return $out;
    }

    /**
     * Erase a dog owner's records from this site and the newsletter list.
     * Trainer-form entries (billing, listings) are not touched. The Privacy Request entries
     * themselves are wiped down to a stub saying they were erased.
     */
    public function erase_owner_data( $email ) {
        global $wpdb;
        $p       = $wpdb->prefix;
        $deleted = array( 'form_entries' => 0, 'owner_requests' => 0, 'email_logs' => 0, 'newsletter' => 'none' );

        $ids = array();
        foreach ( $this->owner_submission_ids( $email ) as $r ) {
            if ( (int) $r->form_id === self::PRIVACY_FORM ) {
                $wpdb->update( $p . 'fluentform_submissions', array( 'response' => wp_json_encode( array( 'erased' => current_time( 'mysql' ) ) ), 'ip' => '' ), array( 'id' => (int) $r->id ) );
                $wpdb->delete( $p . 'fluentform_entry_details', array( 'submission_id' => (int) $r->id ) );
            } else {
                $ids[] = (int) $r->id;
            }
        }
        if ( $ids ) {
            $in = implode( ',', $ids );
            $wpdb->query( "DELETE FROM {$p}fluentform_entry_details WHERE submission_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$p}fluentform_submission_meta WHERE response_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$p}fluentform_logs WHERE source_type = 'submission_item' AND source_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$p}ff_scheduled_actions WHERE origin_id IN ({$in})" );
            $deleted['form_entries'] = (int) $wpdb->query( "DELETE FROM {$p}fluentform_submissions WHERE id IN ({$in})" );
        }

        $req = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}dh_requests WHERE owner_email = %s", $email ) );
        if ( $req ) {
            $in = implode( ',', array_map( 'intval', $req ) );
            $wpdb->query( "DELETE FROM {$p}dh_request_events WHERE request_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$p}dh_request_routes WHERE request_id IN ({$in})" );
            $deleted['owner_requests'] = (int) $wpdb->query( "DELETE FROM {$p}dh_requests WHERE id IN ({$in})" );
        }

        $deleted['email_logs'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}fsmpt_email_logs WHERE `to` LIKE %s", '%' . $wpdb->esc_like( $email ) . '%' ) );

        $mc = $this->mailchimp_call( 'POST', $email, '/actions/delete-permanent' );
        if ( $mc['ok'] ) {
            $deleted['newsletter'] = 'deleted';
        } elseif ( 404 === $mc['code'] ) {
            $deleted['newsletter'] = 'not subscribed';
        } else {
            $deleted['newsletter'] = 'FAILED (' . $mc['code'] . ')';
        }
        return $deleted;
    }

    /* ---- owner tokens ----------------------------------------------------- */

    private function owner_option_name( $raw ) {
        return 'dh_priv_' . substr( hash( 'sha256', $raw ), 0, 40 );
    }

    private function owner_token_data( $raw ) {
        $raw = trim( (string) $raw );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $raw ) ) {
            return null;
        }
        $d = get_option( $this->owner_option_name( $raw ) );
        if ( ! is_array( $d ) || empty( $d['issued'] ) || ( time() - strtotime( $d['issued'] ) ) > self::TOKEN_TTL ) {
            return null;
        }
        return $d;
    }

    /* ---- CLI: resolve / classify / issue ---------------------------------- */

    /**
     * Find the profile a removal request is about and the address that may confirm it.
     *
     * ## OPTIONS
     *
     * --email=<address>
     * [--details=<text>]
     * [--name=<name>]
     */
    public function cli_resolve_removal( $args, $assoc_args ) {
        WP_CLI::log( wp_json_encode( $this->classify_privacy(
            isset( $assoc_args['email'] ) ? $assoc_args['email'] : '',
            isset( $assoc_args['details'] ) ? $assoc_args['details'] : '',
            isset( $assoc_args['name'] ) ? $assoc_args['name'] : ''
        ) ) );
    }

    /**
     * Issue a one-time confirmation link and print it.
     *
     * ## OPTIONS
     *
     * [<post_id>]
     * : A profile post ID (profile removal). Omit for a dog owner request.
     *
     * --email=<address>
     * : The owned address the link is being sent to.
     *
     * [--action=<action>]
     * : For owner requests: delete or access.
     *
     * [--entry=<id>]
     * : The Privacy Request form entry this belongs to.
     */
    public function cli_issue_removal_token( $args, $assoc_args ) {
        $email = isset( $assoc_args['email'] ) ? sanitize_email( $assoc_args['email'] ) : '';
        $entry = isset( $assoc_args['entry'] ) ? (int) $assoc_args['entry'] : 0;
        $raw   = bin2hex( random_bytes( 16 ) );

        if ( ! empty( $args[0] ) ) {
            $post_id = (int) $args[0];
            $post    = get_post( $post_id );
            if ( ! $post || 'profile' !== $post->post_type ) {
                WP_CLI::error( "Post {$post_id} is not a profile." );
            }
            if ( 'publish' !== $post->post_status ) {
                WP_CLI::error( "Profile {$post_id} is not published." );
            }
            update_post_meta( $post_id, 'removal_token_hash', hash( 'sha256', $raw ) );
            update_post_meta( $post_id, 'removal_token_issued', current_time( 'mysql' ) );
            update_post_meta( $post_id, 'removal_token_email', $email );
            update_post_meta( $post_id, 'removal_entry_id', $entry );
        } else {
            $action = isset( $assoc_args['action'] ) ? $assoc_args['action'] : '';
            if ( ! $email || ! in_array( $action, array( 'delete', 'access' ), true ) ) {
                WP_CLI::error( 'An owner link needs --email and --action=delete|access.' );
            }
            update_option( $this->owner_option_name( $raw ), array(
                'kind' => 'owner', 'action' => $action, 'email' => $email, 'entry' => $entry,
                'issued' => current_time( 'mysql' ), 'state' => 'issued',
            ), false );
        }
        WP_CLI::log( home_url( '/' . self::REMOVAL_PATH . '/?r=' . $raw ) );
    }

    /**
     * The published profile a raw removal token belongs to, or 0.
     */
    public function resolve_removal_token( $raw_token ) {
        $raw_token = trim( (string) $raw_token );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', $raw_token ) ) {
            return 0;
        }
        $posts = get_posts( array(
            'post_type'      => 'profile',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array( array( 'key' => 'removal_token_hash', 'value' => hash( 'sha256', $raw_token ) ) ),
        ) );
        if ( empty( $posts ) ) {
            return 0;
        }
        $post_id = (int) $posts[0];
        $issued  = get_post_meta( $post_id, 'removal_token_issued', true );
        if ( $issued && ( time() - strtotime( $issued ) ) > self::TOKEN_TTL ) {
            return 0;
        }
        return $post_id;
    }

    /**
     * Serve /confirm-removal/?r=<token>. GET shows the confirm button and changes nothing;
     * only the POST acts and spends the token. Profile tokens unpublish at once; owner
     * tokens are marked confirmed and the watcher carries them out.
     */
    public function maybe_render_removal_page() {
        $path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' );
        if ( self::REMOVAL_PATH !== $path ) {
            return;
        }
        do_action( 'litespeed_control_set_nocache', 'dh-ugc removal page' );
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow' );
        status_header( 200 );

        $raw     = isset( $_REQUEST['r'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['r'] ) ) : '';
        $is_post = 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['confirm_removal'] );
        $action  = esc_url( home_url( '/' . self::REMOVAL_PATH . '/' ) );
        $form    = function ( $intro, $button ) use ( $raw, $action ) {
            return $intro
                . '<form method="post" action="' . $action . '"><input type="hidden" name="r" value="' . esc_attr( $raw ) . '">'
                . '<button type="submit" name="confirm_removal" value="1">' . esc_html( $button ) . '</button></form>'
                . '<p class="note">If you did not make this request, close this page. Nothing changes.</p>';
        };
        $invalid = '<p>Nothing changes. If you still need this, send a new request on our <a href="' . esc_url( home_url( '/privacy-request/' ) ) . '">privacy request page</a>.</p>';

        $owner = $this->owner_token_data( $raw );
        if ( $owner ) {
            $delete = 'delete' === $owner['action'];
            if ( 'issued' !== $owner['state'] ) {
                $this->removal_page( 'Already confirmed', '<p>We have your confirmation and are working on it.</p>' );
            }
            if ( $is_post ) {
                $owner['state']     = 'confirmed';
                $owner['confirmed'] = current_time( 'mysql' );
                update_option( $this->owner_option_name( $raw ), $owner, false );
                $this->removal_page( 'Confirmed', $delete
                    ? '<p>We are deleting your data. You will get an email when it is done.</p>'
                    : '<p>We are putting your data together. You will get it by email shortly.</p>' );
            }
            $this->removal_page( $delete ? 'Delete your data?' : 'Send you your data?', $form(
                '<p>' . ( $delete
                    ? 'Confirm that this is your email address and that you approve deleting the data Goody Doggy holds about you.'
                    : 'Confirm that this is your email address and that you want a copy of the data Goody Doggy holds about you.' ) . '</p>',
                $delete ? 'Yes, delete my data' : 'Yes, send me my data'
            ) );
        }

        $post_id = $this->resolve_removal_token( $raw );
        $post    = $post_id ? get_post( $post_id ) : null;
        if ( ! $post ) {
            $this->removal_page( 'This link is no longer valid', $invalid );
        }

        $title = esc_html( get_the_title( $post_id ) );
        $city  = function_exists( 'get_field' ) ? (string) get_field( 'city', $post_id ) : '';
        $state = function_exists( 'get_field' ) ? (string) get_field( 'state', $post_id ) : '';
        $where = trim( $city . ( $state ? ', ' . $state : '' ), ', ' );
        $who   = '<strong>' . $title . '</strong>' . ( $where ? ' in ' . esc_html( $where ) : '' );

        if ( $is_post ) {
            if ( 'publish' === $post->post_status ) {
                $res = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
                if ( is_wp_error( $res ) ) {
                    $this->removal_page( 'Something went wrong', '<p>We could not remove the profile. Nothing changed. Please try the link again in a few minutes.</p>' );
                }
                update_post_meta( $post_id, 'dh_removal_state', 'confirmed' );
                update_post_meta( $post_id, 'dh_removal_confirmed', current_time( 'mysql' ) );
            }
            delete_post_meta( $post_id, 'removal_token_hash' );
            $this->removal_page( 'Profile removed', '<p>' . $who . ' is no longer listed on Goody Doggy.</p>' );
        }
        if ( 'publish' !== $post->post_status ) {
            $this->removal_page( 'Already removed', '<p>' . $who . ' is not listed on Goody Doggy.</p>' );
        }
        $this->removal_page( 'Remove this profile?', $form(
            '<p>Confirm that you are the rightful owner and that you approve removing ' . $who . ' from Goody Doggy.</p>',
            'Yes, remove this profile'
        ) );
    }

    /**
     * Print a minimal standalone page and stop.
     */
    private function removal_page( $heading, $body_html ) {
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . esc_html( $heading ) . ' | Goody Doggy</title><style>'
            . 'body{margin:0;background:#f7f4ee;color:#1d2b2a;font:18px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'
            . 'main{max-width:520px;margin:12vh auto;padding:32px 24px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08)}'
            . 'h1{font-size:26px;margin:0 0 16px}a{color:#0b6b5d}'
            . 'button{background:#0b6b5d;color:#fff;border:0;border-radius:8px;padding:14px 24px;font-size:18px;font-weight:600;cursor:pointer}'
            . 'button:hover{background:#08524a}.note{font-size:15px;color:#5b6664;margin-top:24px}'
            . '</style></head><body><main><h1>' . esc_html( $heading ) . '</h1>' . $body_html . '</main></body></html>';
        exit;
    }

    /* ---- finishing -------------------------------------------------------- */

    /**
     * The rankings, counts, freshness and purge work for a profile whose removal was
     * confirmed. Needs the CLI classes, so it runs from WP-CLI. Safe to repeat.
     */
    public static function finish_removal( $post_id ) {
        $post_id = (int) $post_id;
        foreach ( array( 'DH_Profile_Rankings', 'DH_Apply_Ratings_Command', 'DH_Listing_Counts', 'DH_IndexNow_Helper', 'DH_Refresh_Closed_Command' ) as $cls ) {
            if ( ! class_exists( $cls ) ) {
                return new WP_Error( 'dh_removal', "{$cls} is not loaded." );
            }
        }
        if ( 'profile' !== get_post_type( $post_id ) || 'publish' === get_post_status( $post_id ) ) {
            return new WP_Error( 'dh_removal', "Profile {$post_id} is missing or still published." );
        }

        $is_term     = function ( $t ) { return $t instanceof WP_Term; };
        $area_terms  = array_filter( (array) get_the_terms( $post_id, 'area' ), $is_term );
        $state_terms = array_filter( (array) get_the_terms( $post_id, 'state' ), $is_term );

        foreach ( $area_terms as $t ) {
            $pool = DH_Apply_Ratings_Command::city_pool( $t->term_id );
            if ( $pool ) { DH_Profile_Rankings::recalc_pool( $pool, 'city_rank' ); }
        }
        foreach ( $state_terms as $t ) {
            $pool = DH_Apply_Ratings_Command::state_pool( $t->term_id );
            if ( $pool ) { DH_Profile_Rankings::recalc_pool( $pool, 'state_rank' ); }
        }

        $counts = DH_Listing_Counts::instance();
        $own    = array();
        foreach ( $area_terms as $t ) {
            $counts->update_city_profile_count( $t->term_id );
            $l = DH_Apply_Ratings_Command::listing_for_term( 'city-listing', 'area', $t->term_id );
            if ( $l ) { $own[] = (int) $l; }
        }
        foreach ( $state_terms as $t ) {
            $l = DH_Apply_Ratings_Command::listing_for_term( 'state-listing', 'state', $t->term_id );
            if ( $l ) { $own[] = (int) $l; }
        }
        $own = array_values( array_unique( $own ) );

        DH_IndexNow_Helper::refresh_and_submit( $own );

        $near = array_values( array_diff( DH_Refresh_Closed_Command::proximity_listing_ids( array( $post_id ) ), $own ) );
        foreach ( array_merge( array( $post_id ), $own, $near ) as $p ) {
            do_action( 'litespeed_purge_post', $p );
            usleep( 150000 );
        }

        update_post_meta( $post_id, 'dh_removal_state', 'finished' );
        return array( 'listing_pages' => count( $own ), 'neighbours' => count( $near ) );
    }

    /**
     * Finish a confirmed profile removal: recalc rankings, bump listing freshness, purge only affected pages.
     *
     * ## OPTIONS
     *
     * <post_id>
     */
    public function cli_finish_removal( $args ) {
        $post_id = (int) $args[0];
        if ( ! in_array( get_post_meta( $post_id, 'dh_removal_state', true ), array( 'confirmed', 'finished' ), true ) ) {
            WP_CLI::error( "Profile {$post_id} has no confirmed removal." );
        }
        $res = self::finish_removal( $post_id );
        if ( is_wp_error( $res ) ) {
            WP_CLI::error( $res->get_error_message() );
        }
        WP_CLI::success( sprintf( 'Removal finished for %d: %d own listing pages, %d proximity neighbours purged.', $post_id, $res['listing_pages'], $res['neighbours'] ) );
    }

    /**
     * List confirmed privacy actions not yet finished, one JSON object per line:
     * profile removals ({"kind":"profile",...}) and dog owner requests ({"kind":"owner",...}).
     */
    public function cli_privacy_pending() {
        global $wpdb;
        $ids = get_posts( array(
            'post_type' => 'profile', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_query' => array( array( 'key' => 'dh_removal_state', 'value' => 'confirmed' ) ),
        ) );
        foreach ( $ids as $id ) {
            WP_CLI::log( wp_json_encode( array(
                'kind' => 'profile', 'post_id' => (int) $id, 'title' => get_the_title( $id ),
                'entry' => (int) get_post_meta( $id, 'removal_entry_id', true ),
            ) ) );
        }
        $rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'dh\\_priv\\_%'" );
        foreach ( $rows as $row ) {
            $d = maybe_unserialize( $row->option_value );
            if ( is_array( $d ) && isset( $d['state'] ) && 'confirmed' === $d['state'] ) {
                WP_CLI::log( wp_json_encode( array(
                    'kind' => 'owner', 'key' => $row->option_name, 'action' => $d['action'], 'email' => $d['email'], 'entry' => (int) $d['entry'],
                ) ) );
            }
        }
    }

    /**
     * Carry out a confirmed owner request. Delete erases and prints counts; access prints
     * the data as JSON (the watcher emails it). Then the request is marked finished.
     *
     * ## OPTIONS
     *
     * <key>
     * : The option key from privacy-pending.
     */
    public function cli_owner_run( $args ) {
        $d = get_option( $args[0] );
        if ( ! is_array( $d ) || 'confirmed' !== $d['state'] ) {
            WP_CLI::error( 'No confirmed owner request under that key.' );
        }
        if ( 'delete' === $d['action'] ) {
            $result = $this->erase_owner_data( $d['email'] );
            if ( 0 === strpos( (string) $result['newsletter'], 'FAILED' ) ) {
                WP_CLI::error( 'Erased the site records but the newsletter delete failed: ' . $result['newsletter'] );
            }
        } else {
            $result = $this->export_owner_data( $d['email'] );
        }
        $d['state'] = 'finished';
        update_option( $args[0], $d, false );
        WP_CLI::log( wp_json_encode( $result ) );
    }
}
