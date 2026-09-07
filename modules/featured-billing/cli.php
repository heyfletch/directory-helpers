<?php
/**
 * WP-CLI: wp directory-helpers featured-billing <replay|cancel|drift-check|status>
 *
 * Replays the fulfilment for a Fluent Forms entry (the same code path the Stripe hooks run),
 * reverses it, runs the daily drift check on demand, or lists what is Featured under billing.
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

class DH_Featured_Billing_CLI extends WP_CLI_Command {

	/**
	 * Re-run fulfilment for a checkout entry: match the profile, set featured, attach cities, recalc, purge, email.
	 *
	 * ## OPTIONS
	 *
	 * --entry=<id>
	 * : Fluent Forms submission id (form 9).
	 *
	 * [--profile=<post_id>]
	 * : Tie the entry to this published profile instead of matching (for a held order).
	 *
	 * [--dry-run]
	 * : Decide everything and print it, write nothing, send nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp directory-helpers featured-billing replay --entry=171 --dry-run
	 *     wp directory-helpers featured-billing replay --entry=171 --profile=142204
	 */
	public function replay( $args, $assoc_args ) {
		$module     = new DH_Featured_Billing();
		$entry_id   = (int) ( $assoc_args['entry'] ?? 0 );
		$dry        = isset( $assoc_args['dry-run'] );
		$submission = $module->submission( $entry_id );
		if ( ! $submission ) {
			WP_CLI::error( "No Fluent Forms entry {$entry_id}." );
		}
		if ( (int) $submission->form_id !== DH_Featured_Billing::FORM_ID ) {
			WP_CLI::error( "Entry {$entry_id} belongs to form {$submission->form_id}, not the checkout form " . DH_Featured_Billing::FORM_ID . '.' );
		}
		$subscription = $module->subscription_for_entry( $entry_id );
		if ( ! $subscription ) {
			WP_CLI::warning( 'No subscription row for this entry; the tier comes from the entry alone.' );
		}
		if ( ! empty( $assoc_args['profile'] ) ) {
			$pid = (int) $assoc_args['profile'];
			if ( ! $module->is_published_profile( $pid ) ) {
				WP_CLI::error( "Post {$pid} is not a published profile." );
			}
			$data                 = $module->response( $submission );
			$data['profile_id']   = $pid;
			$submission->response = $data;
		}
		WP_CLI::line( '=== Featured billing replay ' . ( $dry ? '(dry run) ' : '' ) . "entry {$entry_id} ===" );
		WP_CLI::line( 'Payment status: ' . $submission->payment_status . ' | subscription: ' . ( $subscription ? "{$subscription->id} {$subscription->status} {$subscription->vendor_subscription_id} " . ( $subscription->recurring_amount / 100 ) . '/' . $subscription->billing_interval : 'none' ) );
		$result = $module->activate( $submission, $subscription, 'cli:replay', $dry );
		$this->print_result( $module, $result );
	}

	/**
	 * Reverse fulfilment for an entry: featured 0, cities trimmed to the primary, recalc, purge, emails.
	 *
	 * ## OPTIONS
	 *
	 * --entry=<id>
	 * : Fluent Forms submission id (form 9).
	 *
	 * [--dry-run]
	 * : Print what would change, write nothing.
	 */
	public function cancel( $args, $assoc_args ) {
		$module     = new DH_Featured_Billing();
		$entry_id   = (int) ( $assoc_args['entry'] ?? 0 );
		$dry        = isset( $assoc_args['dry-run'] );
		$submission = $module->submission( $entry_id );
		if ( ! $submission ) {
			WP_CLI::error( "No Fluent Forms entry {$entry_id}." );
		}
		WP_CLI::line( '=== Featured billing cancel ' . ( $dry ? '(dry run) ' : '' ) . "entry {$entry_id} ===" );
		$result = $module->cancel( $submission, $module->subscription_for_entry( $entry_id ), 'cli:cancel', $dry );
		$this->print_result( $module, $result );
	}

	/**
	 * Run the daily drift check now (live Stripe status, active subscriptions without a Featured profile, held orders).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report only.
	 *
	 * @subcommand drift-check
	 */
	public function drift_check( $args, $assoc_args ) {
		$module  = new DH_Featured_Billing();
		$summary = $module->drift_check( isset( $assoc_args['dry-run'] ) );
		WP_CLI::line( wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		WP_CLI::success( sprintf( '%d Featured profiles checked, %d active subscriptions, %d fixed, %d flagged.', $summary['checked'], $summary['active_subscriptions'], count( $summary['fixed'] ), count( $summary['flags'] ) ) );
	}

	/**
	 * List profiles Featured through the checkout, with their billing meta.
	 */
	public function status( $args, $assoc_args ) {
		$ids  = get_posts( array( 'post_type' => 'profile', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => 'ff_submission_id' ) );
		$rows = array();
		foreach ( $ids as $pid ) {
			$rows[] = array(
				'profile'  => $pid,
				'title'    => get_the_title( $pid ),
				'status'   => get_post_status( $pid ),
				'featured' => get_post_meta( $pid, 'featured', true ),
				'billing'  => get_post_meta( $pid, 'featured_billing_status', true ),
				'tier'     => get_post_meta( $pid, 'featured_tier', true ),
				'cities'   => count( (array) wp_get_object_terms( $pid, 'area', array( 'fields' => 'ids' ) ) ),
				'entry'    => get_post_meta( $pid, 'ff_submission_id', true ),
				'stripe'   => get_post_meta( $pid, 'stripe_subscription_id', true ),
				'since'    => get_post_meta( $pid, 'featured_since', true ),
			);
		}
		$pending = get_option( DH_Featured_Billing::OPTION_PENDING, array() );
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'profile', 'title', 'status', 'featured', 'billing', 'tier', 'cities', 'entry', 'stripe', 'since' ) );
		} else {
			WP_CLI::line( 'No profiles Featured through the checkout yet.' );
		}
		WP_CLI::line( 'Held orders: ' . ( $pending ? wp_json_encode( $pending ) : 'none' ) );
	}

	private function print_result( $module, array $result ) {
		foreach ( $module->trace as $line ) {
			WP_CLI::line( '  ' . $line );
		}
		$show = $result;
		unset( $show['dry'], $show['source'] );
		WP_CLI::line( wp_json_encode( $show, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( in_array( $result['status'], array( 'featured', 'unfeatured', 'would-feature', 'would-unfeature', 'already' ), true ) ) {
			WP_CLI::success( 'Status: ' . $result['status'] );
		} else {
			WP_CLI::warning( 'Status: ' . $result['status'] );
		}
	}
}
