<?php
/**
 * Contact Email Module
 *
 * Stores a trainer's business contact email on their profile (never displayed
 * on the front end) and only accepts an address we can tie to that profile.
 *
 * Sources:
 *  - Update Your Profile (form 8): the magic-link token names the profile.
 *  - Get Listed / Featured Placement (forms 3, 5, 7): matched to a profile by
 *    website domain, phone and business name.
 *
 * An address is only written when the profile has none. A different address
 * arriving for a profile that already has one is parked in
 * `contact_email_pending` for a human to confirm - it never overwrites.
 *
 * @package Directory_Helpers
 * @subpackage Contact_Email
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_Contact_Email {

    const META         = 'contact_email';
    const META_SOURCE  = 'contact_email_source';
    const META_PENDING = 'contact_email_pending';

    /**
     * Fluent Forms that carry a trainer's own email, and the source label to record.
     */
    const INTAKE_FORMS = array(
        3 => 'featured-request',
        5 => 'get-listed',
        7 => 'get-listed',
    );

    /**
     * The tokened Update Your Profile form.
     */
    const UPDATE_FORM = 8;

    /**
     * Mailbox providers that tell us nothing about who owns the business.
     */
    const FREE_MAILBOX = array(
        'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'icloud.com', 'me.com',
        'mac.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'aol.com',
        'comcast.net', 'verizon.net', 'earthlink.net', 'sbcglobal.net', 'att.net',
        'protonmail.com', 'proton.me', 'gmx.com', 'mail.com',
    );

    /**
     * Cached profile match index.
     *
     * @var array|null
     */
    private static $index = null;

    public function __construct() {
        add_action( 'fluentform/submission_inserted', array( $this, 'capture_submission' ), 20, 3 );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'dh-contact-email backfill', array( $this, 'cli_backfill' ) );
            WP_CLI::add_command( 'dh-contact-email review', array( $this, 'cli_review' ) );
            WP_CLI::add_command( 'dh-contact-email set', array( $this, 'cli_set' ) );
        }
    }

    /* ---------------------------------------------------------------------
     * Capture
     * ------------------------------------------------------------------ */

    /**
     * Record the email from a Fluent Forms submission against a profile.
     */
    public function capture_submission( $entry_id, $form_data, $form ) {
        $form_id = (int) $form->id;
        $email   = $this->clean_email( isset( $form_data['email'] ) ? $form_data['email'] : '' );
        if ( ! $email ) {
            return;
        }

        if ( self::UPDATE_FORM === $form_id ) {
            $post_id = $this->resolve_update_token( isset( $form_data['token'] ) ? $form_data['token'] : '' );
            if ( $post_id ) {
                $this->record( $post_id, $email, 'update-form' );
            }
            return;
        }

        if ( ! isset( self::INTAKE_FORMS[ $form_id ] ) ) {
            return;
        }

        $match = $this->match_profile( $this->submission_identity( $form_data ), $email );
        if ( $match['confident'] ) {
            $this->record( $match['post_id'], $email, self::INTAKE_FORMS[ $form_id ] );
        }
    }

    /**
     * Ask the UGC module which profile a magic-link token belongs to.
     */
    private function resolve_update_token( $token ) {
        if ( ! class_exists( 'DH_UGC_Update_Requests' ) ) {
            return 0;
        }
        $ugc = new DH_UGC_Update_Requests();
        return (int) $ugc->resolve_token( $token );
    }

    /**
     * Write the email, or park it as pending when it conflicts with a stored one.
     *
     * @return string One of: stored, unchanged, pending, skipped.
     */
    public function record( $post_id, $email, $source ) {
        $post_id = (int) $post_id;
        $email   = $this->clean_email( $email );
        if ( ! $post_id || ! $email || 'profile' !== get_post_type( $post_id ) ) {
            return 'skipped';
        }

        $current = strtolower( (string) get_post_meta( $post_id, self::META, true ) );
        if ( $current === $email ) {
            return 'unchanged';
        }
        if ( '' !== $current ) {
            update_post_meta( $post_id, self::META_PENDING, $email . ' (' . $source . ', ' . current_time( 'mysql' ) . ')' );
            return 'pending';
        }

        update_post_meta( $post_id, self::META, $email );
        update_post_meta( $post_id, self::META_SOURCE, $source );
        return 'stored';
    }

    /* ---------------------------------------------------------------------
     * Matching
     * ------------------------------------------------------------------ */

    /**
     * Pull the identifying fields out of a submission, whichever form it came from.
     */
    public function submission_identity( $form_data ) {
        $pick = function ( $keys ) use ( $form_data ) {
            foreach ( $keys as $key ) {
                if ( ! empty( $form_data[ $key ] ) && is_scalar( $form_data[ $key ] ) ) {
                    return (string) $form_data[ $key ];
                }
            }
            return '';
        };
        return array(
            'name'    => $pick( array( 'organization', 'input_text' ) ),
            'website' => $pick( array( 'website', 'input_text_2', 'url' ) ),
            'phone'   => $pick( array( 'phone' ) ),
        );
    }

    /**
     * Match a submission to a profile.
     *
     * Confident means two independent identity signals agree, or the website
     * domain matches a single profile and the email is on that same domain.
     *
     * @return array post_id, signals, confident, reason.
     */
    public function match_profile( array $identity, $email = '' ) {
        $index  = $this->index();
        $domain = $this->domain( $identity['website'] );
        $phone  = $this->phone_key( $identity['phone'] );
        $name   = $this->name_key( $identity['name'] );

        $signals   = array();
        $ambiguous = array();
        foreach ( array( 'domain' => $domain, 'phone' => $phone, 'name' => $name ) as $type => $key ) {
            if ( '' === $key || empty( $index[ $type ][ $key ] ) ) {
                continue;
            }
            if ( 1 === count( $index[ $type ][ $key ] ) ) {
                $signals[ $type ] = (int) $index[ $type ][ $key ][0];
            } else {
                $ambiguous[] = $type;
            }
        }

        if ( ! $signals ) {
            return array(
                'post_id'   => 0,
                'signals'   => array(),
                'confident' => false,
                'reason'    => $ambiguous ? 'ambiguous: ' . implode( ',', $ambiguous ) : 'no match',
            );
        }

        $ids = array_unique( array_values( $signals ) );
        if ( count( $ids ) > 1 ) {
            return array(
                'post_id'   => 0,
                'signals'   => $signals,
                'confident' => false,
                'reason'    => 'signals disagree',
            );
        }

        $post_id    = (int) $ids[0];
        $email      = $this->clean_email( $email );
        $self_hosted = $email && $domain && substr( strrchr( $email, '@' ), 1 ) === $domain;

        if ( count( $signals ) >= 2 ) {
            return array( 'post_id' => $post_id, 'signals' => $signals, 'confident' => true, 'reason' => 'two signals' );
        }
        if ( isset( $signals['domain'] ) && $self_hosted ) {
            return array( 'post_id' => $post_id, 'signals' => $signals, 'confident' => true, 'reason' => 'domain + email on same domain' );
        }
        return array( 'post_id' => $post_id, 'signals' => $signals, 'confident' => false, 'reason' => 'single signal' );
    }

    /**
     * Build the domain / phone / name lookup over all profiles.
     */
    private function index() {
        if ( null !== self::$index ) {
            return self::$index;
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title,
                    MAX(CASE WHEN pm.meta_key = 'url'   THEN pm.meta_value END) AS url,
                    MAX(CASE WHEN pm.meta_key = 'phone' THEN pm.meta_value END) AS phone
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('url','phone')
             WHERE p.post_type = 'profile' AND p.post_status NOT IN ('trash','auto-draft')
             GROUP BY p.ID"
        );

        $index = array( 'domain' => array(), 'phone' => array(), 'name' => array() );
        foreach ( $rows as $row ) {
            $keys = array(
                'domain' => $this->domain( $row->url ),
                'phone'  => $this->phone_key( $row->phone ),
                'name'   => $this->name_key( $row->post_title ),
            );
            foreach ( $keys as $type => $key ) {
                if ( '' !== $key ) {
                    $index[ $type ][ $key ][] = (int) $row->ID;
                }
            }
        }
        self::$index = $index;
        return self::$index;
    }

    /* ---------------------------------------------------------------------
     * Normalisers
     * ------------------------------------------------------------------ */

    public function clean_email( $email ) {
        $email = strtolower( trim( (string) $email ) );
        return is_email( $email ) ? $email : '';
    }

    /**
     * Bare registrable host, or '' when the value is not a usable URL.
     */
    public function domain( $url ) {
        $url = strtolower( trim( str_replace( '\\', '', (string) $url ) ) );
        $url = preg_replace( '#^[a-z]+://#', '', $url );
        $url = preg_replace( '#^www\.#', '', $url );
        $url = trim( explode( '?', explode( '/', $url )[0] )[0] );
        if ( '' === $url || false === strpos( $url, '.' ) || preg_match( '/[\s@]/', $url ) ) {
            return '';
        }
        return $url;
    }

    /**
     * Last ten digits of a phone number.
     */
    public function phone_key( $phone ) {
        $digits = preg_replace( '/\D/', '', (string) $phone );
        return strlen( $digits ) >= 10 ? substr( $digits, -10 ) : '';
    }

    public function name_key( $name ) {
        return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $name ) );
    }

    public function is_free_mailbox( $email ) {
        return in_array( substr( strrchr( (string) $email, '@' ), 1 ), self::FREE_MAILBOX, true );
    }

    /* ---------------------------------------------------------------------
     * WP-CLI
     * ------------------------------------------------------------------ */

    /**
     * Match historic Fluent Forms entries to profiles and fill in contact emails.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Write the confident matches. Without it, nothing is changed.
     *
     * [--forms=<ids>]
     * : Comma-separated form IDs. Default: 3,5,7,8.
     *
     * ## EXAMPLES
     *
     *     wp dh-contact-email backfill
     *     wp dh-contact-email backfill --apply
     */
    public function cli_backfill( $args, $assoc_args ) {
        global $wpdb;
        $apply = isset( $assoc_args['apply'] );
        $forms = isset( $assoc_args['forms'] )
            ? array_map( 'intval', explode( ',', $assoc_args['forms'] ) )
            : array_merge( array_keys( self::INTAKE_FORMS ), array( self::UPDATE_FORM ) );

        $in   = implode( ',', array_map( 'intval', $forms ) );
        $rows = $wpdb->get_results( "SELECT id, form_id, response FROM {$wpdb->prefix}fluentform_submissions WHERE form_id IN ({$in}) ORDER BY id" );

        $table   = array();
        $counts  = array( 'stored' => 0, 'unchanged' => 0, 'pending' => 0, 'skipped' => 0, 'no match' => 0 );
        foreach ( $rows as $row ) {
            $data  = json_decode( $row->response, true );
            $email = $this->clean_email( isset( $data['email'] ) ? $data['email'] : '' );
            if ( ! $email ) {
                continue;
            }

            $form_id = (int) $row->form_id;
            if ( self::UPDATE_FORM === $form_id ) {
                $post_id = $this->resolve_update_token( isset( $data['token'] ) ? $data['token'] : '' );
                $reason  = $post_id ? 'token' : 'bad token';
                $source  = 'update-form';
            } else {
                $match   = $this->match_profile( $this->submission_identity( $data ), $email );
                $post_id = $match['confident'] ? $match['post_id'] : 0;
                $reason  = $match['reason'];
                $source  = isset( self::INTAKE_FORMS[ $form_id ] ) ? self::INTAKE_FORMS[ $form_id ] : 'backfill';
            }

            if ( ! $post_id ) {
                $counts['no match']++;
                $table[] = array( 'entry' => $row->id, 'form' => $form_id, 'email' => $email, 'profile' => '-', 'title' => '', 'reason' => $reason, 'result' => 'no match' );
                continue;
            }

            $result = $apply
                ? $this->record( $post_id, $email, $source )
                : $this->preview( $post_id, $email );
            $counts[ $result ] = isset( $counts[ $result ] ) ? $counts[ $result ] + 1 : 1;

            $table[] = array(
                'entry'   => $row->id,
                'form'    => $form_id,
                'email'   => $email,
                'profile' => $post_id,
                'title'   => get_the_title( $post_id ),
                'reason'  => $reason . ( $this->is_free_mailbox( $email ) ? ' [free mailbox]' : '' ),
                'result'  => $result,
            );
        }

        WP_CLI\Utils\format_items( 'table', $table, array( 'entry', 'form', 'email', 'profile', 'title', 'reason', 'result' ) );
        foreach ( $counts as $key => $count ) {
            WP_CLI::log( sprintf( '%-10s %d', $key, $count ) );
        }
        WP_CLI::success( $apply ? 'Backfill applied.' : 'Dry run - re-run with --apply to write.' );
    }

    /**
     * What record() would do, without writing.
     */
    private function preview( $post_id, $email ) {
        $current = strtolower( (string) get_post_meta( $post_id, self::META, true ) );
        if ( $current === $email ) {
            return 'unchanged';
        }
        return '' === $current ? 'stored' : 'pending';
    }

    /**
     * List profiles holding a pending email that needs a human decision.
     */
    public function cli_review() {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value AS pending,
                    (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = pm.post_id AND meta_key = %s LIMIT 1) AS current
             FROM {$wpdb->postmeta} pm
             WHERE pm.meta_key = %s AND pm.meta_value != ''",
            self::META,
            self::META_PENDING
        ) );
        if ( ! $rows ) {
            WP_CLI::success( 'Nothing pending.' );
            return;
        }
        $table = array();
        foreach ( $rows as $row ) {
            $table[] = array(
                'profile' => $row->post_id,
                'title'   => get_the_title( $row->post_id ),
                'stored'  => $row->current,
                'pending' => $row->pending,
            );
        }
        WP_CLI\Utils\format_items( 'table', $table, array( 'profile', 'title', 'stored', 'pending' ) );
    }

    /**
     * Set a profile's contact email by hand, clearing any pending value.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The profile post ID.
     *
     * <email>
     * : The address to store. Pass an empty string to clear.
     *
     * [--source=<source>]
     * : Where it came from. Default: manual.
     */
    public function cli_set( $args, $assoc_args ) {
        $post_id = (int) $args[0];
        $email   = $this->clean_email( $args[1] );
        $source  = isset( $assoc_args['source'] ) ? $assoc_args['source'] : 'manual';

        if ( 'profile' !== get_post_type( $post_id ) ) {
            WP_CLI::error( "Post {$post_id} is not a profile." );
        }
        if ( '' !== trim( (string) $args[1] ) && ! $email ) {
            WP_CLI::error( 'Not a valid email address.' );
        }

        if ( $email ) {
            update_post_meta( $post_id, self::META, $email );
            update_post_meta( $post_id, self::META_SOURCE, $source );
        } else {
            delete_post_meta( $post_id, self::META );
            delete_post_meta( $post_id, self::META_SOURCE );
        }
        delete_post_meta( $post_id, self::META_PENDING );
        WP_CLI::success( sprintf( 'Profile %d contact email set to "%s" (%s).', $post_id, $email, $source ) );
    }
}
