<?php
/**
 * Owner Requests Module
 *
 * A dog owner describes a problem and a ZIP code on a city or profile page.
 * The request is checked (Gate A, Gate B), matched to up to five listed
 * trainers, and the trainers who gave their own email (lane A) get a notice.
 * The owner gets a receipt. Every outcome is counted per trainer.
 *
 * Wave one: preview only (box, form and token pages work for admins only) and
 * dry run (every email is written to the log instead of sent). Lanes B and C
 * have no sending code. See marketing-factory/owner-requests/bet.md.
 *
 * @package Directory_Helpers
 * @subpackage Owner_Requests
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_Owner_Requests {

    public function __construct() {
        $dir = __DIR__ . '/includes/';
        foreach ( array( 'text', 'screen', 'matcher', 'messages', 'settings', 'schema', 'store', 'candidates', 'gates', 'mailer', 'pages', 'flow', 'box', 'admin', 'cli' ) as $part ) {
            require_once $dir . 'class-or-' . $part . '.php';
        }

        add_action( 'init', array( 'DH_OR_Schema', 'maybe_install' ), 1 );

        add_filter( 'fluentform/validation_errors', array( $this, 'validate' ), 20, 4 );
        add_action( 'fluentform/submission_inserted', array( $this, 'handle_submission' ), 10, 3 );
        add_filter( 'fluentform/submission_confirmation', array( $this, 'confirmation' ), 20, 5 );

        new DH_OR_Pages();
        new DH_OR_Box();
        new DH_OR_Admin();

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $cli = new DH_OR_CLI();
            foreach ( array(
                'install-db' => 'install_db', 'load-data' => 'load_data', 'install-form' => 'install_form', 'stats' => 'stats',
                'pause' => 'pause', 'resume' => 'resume', 'retract' => 'retract', 'suppress' => 'suppress', 'unsuppress' => 'unsuppress',
                'purge-old' => 'purge_old', 'delete-test' => 'delete_test', 'match' => 'match', 'setting' => 'setting',
                'ledger-pending' => 'ledger_pending', 'ledger-ack' => 'ledger_ack', 'suppress-sync' => 'suppress_sync',
            ) as $name => $method ) {
                WP_CLI::add_command( 'dh-requests ' . $name, array( $cli, $method ) );
            }
        }
    }

    private function is_our_form( $form ) {
        $id = (int) DH_OR_Settings::get( 'form_id' );
        return $id && is_object( $form ) && (int) $form->id === $id;
    }

    /** Gate A, inside Fluent Forms' own validation (after Turnstile and the honeypot). */
    public function validate( $errors, $form_data, $form, $fields ) {
        if ( ! $this->is_our_form( $form ) ) {
            return $errors;
        }
        if ( ! DH_OR_Settings::visitor_allowed() ) {
            $errors['problem'] = array( 'This form is not open yet.' );
            return $errors;
        }
        if ( ! empty( $errors ) ) {
            return $errors; // Fix the basics first; skip the DNS lookups.
        }
        try {
            $parsed = DH_OR_Gates::parse( (array) $form_data );
            foreach ( DH_OR_Gates::check( $parsed, DH_OR_Gates::client_ip() ) as $field => $message ) {
                $errors[ $field ] = array( $message );
            }
        } catch ( \Throwable $e ) {
            error_log( '[owner-requests] gate A: ' . $e->getMessage() );
            $errors['problem'] = array( 'Something went wrong on our side. Please try again in a few minutes.' );
        }
        return $errors;
    }

    public function handle_submission( $entry_id, $form_data, $form ) {
        if ( ! $this->is_our_form( $form ) ) {
            return;
        }
        DH_OR_Flow::from_submission( $entry_id, (array) $form_data );
    }

    /** Show the owner the match list (or the thin-market note) where the form was. */
    public function confirmation( $return_data, $form, $confirmation, $insert_id, $form_data ) {
        if ( $this->is_our_form( $form ) && '' !== DH_OR_Flow::$result_html && is_array( $return_data ) ) {
            $return_data['message'] = DH_OR_Flow::$result_html;
        }
        return $return_data;
    }
}
