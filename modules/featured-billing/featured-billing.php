<?php
/**
 * Featured Billing module
 *
 * Fulfils the self-serve Featured Placement checkout (Fluent Forms Pro form 9, Stripe
 * subscription field, hosted Checkout). Fluent Forms owns the money and fires WordPress
 * hooks; this module turns those hooks into profile state:
 *
 *   activation  fluentform/subscription_payment_active_stripe   -> feature the profile
 *   renewal     fluentform/subscription_received_payment_stripe -> re-assert, log
 *   cancel      fluentform/subscription_payment_canceled_stripe and the admin-cancel hook
 *               fluentform/payment_subscription_status_to_cancelled -> unfeature
 *
 * Featuring = ACF `featured` 500, the requested `area` terms up to the tier's allowance
 * (Plus 1, Pro 5, Premium 10; nearest cities fill the blanks), Stripe ids on the profile,
 * then exactly what `wp directory-helpers update-rankings-for-profile` does: recalc the
 * city pools and the primary-state pool through DH_Profile_Rankings::recalc_pool() and purge
 * only the profile plus its city and state listing pages. Never a site-wide purge.
 *
 * A daily WP-Cron drift check (system cron runs `wp cron event run --due-now` every 5 min on
 * this host) reads each Featured profile's live Stripe subscription through Fluent Forms' own
 * Stripe client, re-runs held orders and emails Joe only when something changed.
 *
 * WP-CLI: wp directory-helpers featured-billing replay|cancel|drift-check|status (cli.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DH_Featured_Billing {

	const FORM_ID          = 9;
	const CHECKOUT_PAGE_ID = 143391;
	const WELCOME_PAGE_ID  = 143392;
	const FEATURED_VALUE   = 500;
	const ACF_FEATURED_KEY = 'field_688002c69a531';
	const CRON_HOOK        = 'dh_featured_billing_drift';
	const OPTION_PENDING   = 'dh_featured_billing_pending';
	const ADMIN_EMAIL      = 'joe@goodydoggy.com';
	const NEAREST_MAX_MI   = 75;
	const SUBMISSION_META  = 'dh_featured_profile_id';

	const TIER_CITIES = array( 'plus' => 1, 'pro' => 5, 'premium' => 10 );
	const TIER_LABEL  = array( 'plus' => 'Plus', 'pro' => 'Pro', 'premium' => 'Premium' );
	// recurring_amount (cents) on the Fluent Forms subscription row -> tier.
	const AMOUNT_TIER = array( 1900 => 'plus', 17100 => 'plus', 3900 => 'pro', 35100 => 'pro', 5900 => 'premium', 53100 => 'premium' );
	// Option index on the form's subscription radio -> tier, interval (build/build-checkout-form.mjs).
	const PLAN_INDEX = array(
		0 => array( 'plus', 'month' ), 1 => array( 'plus', 'year' ),
		2 => array( 'pro', 'month' ), 3 => array( 'pro', 'year' ),
		4 => array( 'premium', 'month' ), 5 => array( 'premium', 'year' ),
	);

	/** Last run's step log, for the CLI. */
	public $trace = array();

	public function __construct() {
		add_action( 'fluentform/subscription_payment_active_stripe', array( $this, 'on_active' ), 10, 3 );
		add_action( 'fluentform/subscription_received_payment_stripe', array( $this, 'on_renewal' ), 10, 2 );
		add_action( 'fluentform/subscription_payment_canceled_stripe', array( $this, 'on_cancelled' ), 10, 3 );
		add_action( 'fluentform/subscription_payment_cancelled_stripe', array( $this, 'on_cancelled' ), 10, 3 );
		add_action( 'fluentform/payment_subscription_status_to_cancelled', array( $this, 'on_cancelled' ), 10, 3 );

		add_filter( 'fluentform/rendering_form', array( $this, 'prefill_form' ), 10, 1 );
		add_filter( 'fluentform/rendering_field_data_subscription_payment_component', array( $this, 'preselect_plan' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'nocache_checkout_pages' ) );

		add_action( 'init', array( $this, 'schedule_drift_check' ) );
		add_action( self::CRON_HOOK, array( $this, 'drift_check' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/cli.php';
			WP_CLI::add_command( 'directory-helpers featured-billing', 'DH_Featured_Billing_CLI' );
		}
	}

	/* ------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------- */

	public function on_active( $subscription, $submission, $vendor = false ) {
		if ( ! $this->is_ours( $submission ) ) {
			return;
		}
		$this->activate( $submission, $subscription, 'hook:active' );
	}

	public function on_renewal( $subscription, $submission ) {
		if ( ! $this->is_ours( $submission ) ) {
			return;
		}
		$pid = $this->profile_for_entry( $submission->id );
		if ( $pid && (float) get_post_meta( $pid, 'featured', true ) > 0 && 'active' === get_post_meta( $pid, 'featured_billing_status', true ) ) {
			update_post_meta( $pid, 'featured_last_payment', current_time( 'mysql' ) );
			$this->log( $submission->id, 'Renewal recorded', "Profile {$pid} stays Featured." );
			return;
		}
		$this->activate( $submission, $subscription, 'hook:renewal' );
	}

	public function on_cancelled( $subscription, $submission, $extra = false ) {
		if ( ! $this->is_ours( $submission ) ) {
			return;
		}
		$this->cancel( $submission, $subscription, 'hook:cancelled' );
	}

	private function is_ours( $submission ) {
		return is_object( $submission ) && (int) $submission->form_id === self::FORM_ID;
	}

	/* ------------------------------------------------------------------
	 * Activation
	 * ---------------------------------------------------------------- */

	/**
	 * Feature the profile an entry is for. Idempotent: a second run for the same entry and
	 * subscription re-asserts the same state and sends nothing.
	 *
	 * @return array status, and everything decided along the way (the CLI prints it).
	 */
	public function activate( $submission, $subscription, $source = 'manual', $dry = false ) {
		$this->trace = array();
		$entry_id    = (int) $submission->id;
		$data        = $this->response( $submission );
		$tier        = $this->tier_for( $subscription, $data );
		$allowed     = self::TIER_CITIES[ $tier['tier'] ];
		$requested   = $this->requested_cities( $data );
		$match       = $this->resolve_profile( $data );
		$vendor_sub  = is_object( $subscription ) ? (string) $subscription->vendor_subscription_id : '';
		$result      = compact( 'entry_id', 'source', 'dry', 'tier', 'allowed', 'requested', 'match', 'vendor_sub' );

		$this->trace[] = sprintf( 'entry %d: tier %s/%s (allowance %d), requested [%s], match %s (%s)', $entry_id, $tier['tier'], $tier['interval'], $allowed, implode( '; ', $requested ), $match['post_id'] ?: 'none', $match['reason'] );

		if ( ! $match['post_id'] ) {
			$result['status'] = 'held';
			if ( ! $dry ) {
				$this->hold_order( $entry_id, $match['reason'], $data, $tier );
			}
			return $result;
		}
		$pid = (int) $match['post_id'];

		$already_sub = (string) get_post_meta( $pid, 'stripe_subscription_id', true );
		$is_featured = (float) get_post_meta( $pid, 'featured', true ) > 0;
		$status      = (string) get_post_meta( $pid, 'featured_billing_status', true );
		$same_sub    = $vendor_sub && $already_sub === $vendor_sub;
		if ( $is_featured && 'active' === $status && $already_sub && $vendor_sub && ! $same_sub ) {
			// Risk 17: a second live subscription for a profile that is already Featured. Keep the first.
			$result['status'] = 'duplicate';
			$this->trace[]    = "profile {$pid} already Featured under {$already_sub}; this subscription {$vendor_sub} is a duplicate";
			if ( ! $dry ) {
				$this->log( $entry_id, 'Duplicate purchase held', "Profile {$pid} is already Featured under subscription {$already_sub}.", 'error' );
				$this->mail( self::ADMIN_EMAIL, 'Featured: duplicate purchase for ' . get_the_title( $pid ), $this->p( "Profile {$pid} " . get_the_title( $pid ) . " is already Featured under Stripe subscription {$already_sub}. Entry {$entry_id} paid again under {$vendor_sub}. Refund the second one in Stripe." ) . $this->p( $this->entry_link( $entry_id ) ) );
			}
			return $result;
		}

		$areas           = $this->plan_areas( $pid, $requested, $allowed );
		$result['areas'] = $this->describe_areas( $areas );
		$this->trace[]   = 'areas: ' . wp_json_encode( $result['areas'] );

		if ( $dry ) {
			$result['status']      = $same_sub && $is_featured ? 'already' : 'would-feature';
			$result['would_purge'] = $this->purge_targets( $pid, $areas['final'] );
			return $result;
		}

		if ( $same_sub && $is_featured && 'active' === $status && ! array_diff( $areas['final'], $areas['current'] ) ) {
			$result['status'] = 'already';
			$this->log( $entry_id, 'Already Featured', "Profile {$pid} is already Featured under this subscription; nothing changed." );
			return $result;
		}
		$first_time = ! ( $same_sub && $is_featured );

		$this->set_featured( $pid, self::FEATURED_VALUE );
		$meta = array(
			'stripe_subscription_id'  => $vendor_sub,
			'stripe_customer_id'      => is_object( $subscription ) ? (string) $subscription->vendor_customer_id : '',
			'ff_subscription_id'      => is_object( $subscription ) ? (int) $subscription->id : 0,
			'ff_submission_id'        => $entry_id,
			'featured_tier'           => $tier['tier'],
			'featured_plan'           => $tier['plan_name'],
			'featured_cities_allowed' => $allowed,
			'featured_billing_status' => 'active',
			'featured_billing_email'  => $this->email_of( $data ),
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $pid, $k, $v );
		}
		if ( $first_time || ! get_post_meta( $pid, 'featured_since', true ) ) {
			update_post_meta( $pid, 'featured_since', current_time( 'mysql' ) );
		}
		delete_post_meta( $pid, 'featured_ended' );

		if ( array_diff( $areas['final'], $areas['current'] ) || array_diff( $areas['current'], $areas['final'] ) ) {
			wp_set_object_terms( $pid, array_map( 'intval', $areas['final'] ), 'area', false );
			clean_object_term_cache( $pid, 'profile' );
		}

		$result['purged'] = $this->recalc_and_purge( $pid );
		$this->set_submission_meta( $entry_id, self::SUBMISSION_META, $pid );
		$this->unhold( $entry_id );

		$token = $first_time ? $this->maybe_issue_token( $pid, $data, $match ) : array( 'mode' => 'none' );
		if ( $first_time ) {
			$this->send_welcome( $pid, $data, $tier, $areas, $token );
			$this->mail(
				self::ADMIN_EMAIL,
				sprintf( 'Featured: %s (%s, %d %s)', get_the_title( $pid ), self::TIER_LABEL[ $tier['tier'] ], count( $areas['final'] ), count( $areas['final'] ) === 1 ? 'city' : 'cities' ),
				$this->p( sprintf( '%s is Featured. %s, %s. Cities: %s.%s Subscription %s. %s', get_the_title( $pid ), self::TIER_LABEL[ $tier['tier'] ], $tier['plan_name'] ?: $tier['interval'], implode( ', ', $result['areas']['final'] ), $areas['unresolved'] ? ' Not found: ' . implode( ', ', $areas['unresolved'] ) . '.' : '', $vendor_sub ?: '(none)', 'Update link: ' . $token['mode'] . '.' ) )
				. $this->p( '<a href="' . esc_url( get_permalink( $pid ) ) . '">' . esc_html( get_permalink( $pid ) ) . '</a>' )
				. $this->p( $this->entry_link( $entry_id ) )
			);
		}
		$this->log( $entry_id, $first_time ? 'Profile Featured' : 'Featured re-asserted', sprintf( 'Profile %d (%s), %s, cities: %s. Purged: profile + %d city + %d state pages.', $pid, get_the_title( $pid ), self::TIER_LABEL[ $tier['tier'] ], implode( ', ', $result['areas']['final'] ), count( $result['purged']['city'] ), count( $result['purged']['state'] ) ) );
		$result['status'] = 'featured';
		$result['token']  = $token['mode'];
		return $result;
	}

	/* ------------------------------------------------------------------
	 * Cancellation
	 * ---------------------------------------------------------------- */

	public function cancel( $submission, $subscription, $source = 'manual', $dry = false ) {
		$this->trace = array();
		$entry_id    = (int) $submission->id;
		$pid         = $this->profile_for_entry( $entry_id );
		$result      = compact( 'entry_id', 'source', 'dry' );
		$result['profile'] = $pid;
		if ( ! $pid ) {
			$result['status'] = 'no-profile';
			if ( ! $dry ) {
				$this->log( $entry_id, 'Cancellation: no profile', 'No profile is tied to this entry; nothing to unfeature.', 'error' );
			}
			return $result;
		}
		$is_featured = (float) get_post_meta( $pid, 'featured', true ) > 0;
		$status      = (string) get_post_meta( $pid, 'featured_billing_status', true );
		if ( ! $is_featured && 'cancelled' === $status ) {
			$result['status'] = 'already';
			return $result;
		}
		$before  = $this->area_ids( $pid );
		$primary = DH_Taxonomy_Helpers::get_primary_area_term( $pid );
		$keep    = $primary ? array( (int) $primary->term_id ) : array();
		$result['areas'] = array( 'before' => $this->term_names( $before ), 'after' => $this->term_names( $keep ) );
		$this->trace[]   = sprintf( 'entry %d -> profile %d: featured %s, areas %s -> %s', $entry_id, $pid, $is_featured ? 'yes' : 'no', implode( ', ', $result['areas']['before'] ), implode( ', ', $result['areas']['after'] ) );
		if ( $dry ) {
			$result['status']      = 'would-unfeature';
			$result['would_purge'] = $this->purge_targets( $pid, $before );
			return $result;
		}

		$this->set_featured( $pid, 0 );
		update_post_meta( $pid, 'featured_billing_status', 'cancelled' );
		update_post_meta( $pid, 'featured_ended', current_time( 'mysql' ) );
		if ( $keep && array_diff( $before, $keep ) ) {
			wp_set_object_terms( $pid, $keep, 'area', false );
			clean_object_term_cache( $pid, 'profile' );
		}
		$result['purged'] = $this->recalc_and_purge( $pid, $before );

		$buyer = (string) get_post_meta( $pid, 'featured_billing_email', true );
		if ( $buyer ) {
			$this->mail(
				$buyer,
				'Your Featured Placement on Goody Doggy has ended',
				$this->p( 'Hi,' )
				. $this->p( 'The Featured Placement for ' . esc_html( get_the_title( $pid ) ) . ' has ended and no further charges will be made. Your free profile stays live at <a href="' . esc_url( get_permalink( $pid ) ) . '">' . esc_html( get_permalink( $pid ) ) . '</a>.' )
				. $this->p( 'To go Featured again, pick a plan at <a href="' . esc_url( home_url( '/featured-checkout/' ) ) . '">' . esc_html( home_url( '/featured-checkout/' ) ) . '</a>.' )
				. $this->p( 'Goody Doggy' )
			);
		}
		$this->mail( self::ADMIN_EMAIL, 'Featured ended: ' . get_the_title( $pid ), $this->p( sprintf( '%s is no longer Featured (%s). Cities trimmed to %s. Subscription %s.', get_the_title( $pid ), $source, implode( ', ', $result['areas']['after'] ) ?: 'none', (string) get_post_meta( $pid, 'stripe_subscription_id', true ) ) ) . $this->p( $this->entry_link( $entry_id ) ) );
		$this->log( $entry_id, 'Profile unfeatured', sprintf( 'Profile %d (%s) back to a free listing on %s. Purged: profile + %d city + %d state pages.', $pid, get_the_title( $pid ), implode( ', ', $result['areas']['after'] ), count( $result['purged']['city'] ), count( $result['purged']['state'] ) ) );
		$result['status'] = 'unfeatured';
		return $result;
	}

	/* ------------------------------------------------------------------
	 * Profile matching (published profiles only - the private backlog never matches)
	 * ---------------------------------------------------------------- */

	/**
	 * @return array post_id (0 = none), reason.
	 */
	public function resolve_profile( array $data ) {
		global $wpdb;
		$pid = isset( $data['profile_id'] ) ? (int) $data['profile_id'] : 0;
		if ( $pid && $this->is_published_profile( $pid ) ) {
			return array( 'post_id' => $pid, 'reason' => 'profile_id' );
		}

		$domain = $this->domain( isset( $data['website'] ) ? $data['website'] : '' );
		if ( $domain ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'url'
				 WHERE p.post_type = 'profile' AND p.post_status = 'publish' AND pm.meta_value LIKE %s",
				'%' . $wpdb->esc_like( $domain ) . '%'
			) );
			$exact = array();
			foreach ( $ids as $id ) {
				if ( $this->domain( get_post_meta( $id, 'url', true ) ) === $domain ) {
					$exact[] = (int) $id;
				}
			}
			$exact = array_values( array_unique( $exact ) );
			if ( 1 === count( $exact ) ) {
				return array( 'post_id' => $exact[0], 'reason' => 'website domain' );
			}
			if ( count( $exact ) > 1 ) {
				$narrowed = $this->narrow_by_name( $exact, $data );
				if ( $narrowed ) {
					return array( 'post_id' => $narrowed, 'reason' => 'website domain + business name' );
				}
				return array( 'post_id' => 0, 'reason' => 'ambiguous: ' . count( $exact ) . ' published profiles share the domain ' . $domain );
			}
		}

		$phone = $this->phone_key( isset( $data['phone'] ) ? $data['phone'] : '' );
		if ( $phone ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'phone'
				 WHERE p.post_type = 'profile' AND p.post_status = 'publish' AND pm.meta_value <> ''"
			) );
			$hits = array();
			foreach ( $ids as $id ) {
				if ( $this->phone_key( get_post_meta( $id, 'phone', true ) ) === $phone ) {
					$hits[] = (int) $id;
				}
			}
			if ( 1 === count( $hits ) ) {
				return array( 'post_id' => $hits[0], 'reason' => 'phone' );
			}
		}

		$name = $this->name_key( isset( $data['business_name'] ) ? $data['business_name'] : '' );
		if ( strlen( $name ) >= 4 ) {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'profile' AND post_status = 'publish'" );
			$hits = array();
			foreach ( $ids as $id ) {
				if ( $this->name_key( get_the_title( $id ) ) === $name ) {
					$hits[] = (int) $id;
				}
			}
			if ( 1 === count( $hits ) ) {
				return array( 'post_id' => $hits[0], 'reason' => 'business name' );
			}
			if ( count( $hits ) > 1 ) {
				$cities = array_map( array( $this, 'city_part' ), $this->requested_cities( $data ) );
				$in_city = array();
				foreach ( $hits as $id ) {
					$city = strtolower( trim( (string) get_post_meta( $id, 'city', true ) ) );
					if ( $city && in_array( $city, $cities, true ) ) {
						$in_city[] = $id;
					}
				}
				if ( 1 === count( $in_city ) ) {
					return array( 'post_id' => $in_city[0], 'reason' => 'business name + city' );
				}
				return array( 'post_id' => 0, 'reason' => 'ambiguous: ' . count( $hits ) . ' published profiles named ' . $data['business_name'] );
			}
		}
		return array( 'post_id' => 0, 'reason' => 'no published profile matches the website, phone or business name' );
	}

	private function narrow_by_name( array $ids, array $data ) {
		$name = $this->name_key( isset( $data['business_name'] ) ? $data['business_name'] : '' );
		$hits = array();
		foreach ( $ids as $id ) {
			if ( $name && $this->name_key( get_the_title( $id ) ) === $name ) {
				$hits[] = $id;
			}
		}
		return 1 === count( $hits ) ? $hits[0] : 0;
	}

	public function is_published_profile( $pid ) {
		$post = get_post( (int) $pid );
		return $post && 'profile' === $post->post_type && 'publish' === $post->post_status;
	}

	/* ------------------------------------------------------------------
	 * Tier and cities
	 * ---------------------------------------------------------------- */

	/**
	 * Tier from the subscription row's amount first (the money is the truth), then the plan
	 * name, then the option index stored in the entry.
	 */
	public function tier_for( $subscription, array $data ) {
		$amount    = is_object( $subscription ) ? (int) $subscription->recurring_amount : 0;
		$interval  = is_object( $subscription ) && $subscription->billing_interval ? (string) $subscription->billing_interval : '';
		$plan_name = is_object( $subscription ) ? (string) $subscription->plan_name : '';
		$tier      = '';
		$how       = '';
		if ( isset( self::AMOUNT_TIER[ $amount ] ) ) {
			$tier = self::AMOUNT_TIER[ $amount ];
			$how  = 'amount';
		} elseif ( $plan_name && preg_match( '/^(plus|pro|premium)\b/i', trim( $plan_name ), $m ) ) {
			$tier = strtolower( $m[1] );
			$how  = 'plan name';
		} elseif ( isset( $data['plan'] ) && is_numeric( $data['plan'] ) && isset( self::PLAN_INDEX[ (int) $data['plan'] ] ) ) {
			$tier     = self::PLAN_INDEX[ (int) $data['plan'] ][0];
			$interval = $interval ?: self::PLAN_INDEX[ (int) $data['plan'] ][1];
			$how      = 'option index';
		} elseif ( isset( $data['plan'] ) && preg_match( '/^(plus|pro|premium)\b/i', (string) $data['plan'], $m ) ) {
			$tier = strtolower( $m[1] );
			$how  = 'entry plan';
		}
		if ( ! $tier ) {
			$tier = 'plus';
			$how  = 'default (unrecognised amount ' . $amount . ')';
		}
		if ( ! $interval ) {
			$interval = in_array( $amount, array( 17100, 35100, 53100 ), true ) ? 'year' : 'month';
		}
		return array( 'tier' => $tier, 'interval' => $interval, 'amount' => $amount, 'plan_name' => $plan_name, 'how' => $how );
	}

	public function requested_cities( array $data ) {
		$out  = array();
		$seen = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$v = isset( $data[ 'city_' . $i ] ) && is_scalar( $data[ 'city_' . $i ] ) ? trim( wp_strip_all_tags( (string) $data[ 'city_' . $i ] ) ) : '';
			$k = strtolower( preg_replace( '/[^a-z0-9]/', '', strtolower( $v ) ) );
			if ( '' === $v || isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = $v;
		}
		return $out;
	}

	/**
	 * Decide the profile's area terms: what it has now stays, then the cities the buyer named,
	 * then the nearest cities in the same state, never more than the allowance.
	 */
	public function plan_areas( $pid, array $requested, $allowed ) {
		$primary = DH_Taxonomy_Helpers::get_primary_area_term( $pid );
		$current = $this->area_ids( $pid );
		$final   = array();
		if ( $primary ) {
			$final[] = (int) $primary->term_id;
		}
		foreach ( $current as $id ) {
			if ( ! in_array( $id, $final, true ) ) {
				$final[] = $id;
			}
		}
		$resolved   = array();
		$unresolved = array();
		$over       = array();
		foreach ( $requested as $name ) {
			$term = $this->find_area_term( $name, $primary );
			if ( ! $term ) {
				$unresolved[] = $name;
				continue;
			}
			if ( in_array( (int) $term->term_id, $final, true ) ) {
				continue;
			}
			if ( count( $final ) >= $allowed ) {
				$over[] = $term->name;
				continue;
			}
			$final[]    = (int) $term->term_id;
			$resolved[] = $term->name;
		}
		$nearest = array();
		if ( count( $final ) < $allowed && $primary ) {
			foreach ( $this->nearest_terms( $primary, $allowed * 3 ) as $row ) {
				if ( count( $final ) >= $allowed ) {
					break;
				}
				if ( in_array( (int) $row->term_id, $final, true ) ) {
					continue;
				}
				$final[]   = (int) $row->term_id;
				$nearest[] = $row->name . ' (' . round( $row->distance ) . ' mi)';
			}
		}
		return compact( 'primary', 'current', 'final', 'resolved', 'unresolved', 'over', 'nearest' );
	}

	private function describe_areas( array $areas ) {
		return array(
			'primary'    => $areas['primary'] ? $areas['primary']->name : '',
			'current'    => $this->term_names( $areas['current'] ),
			'final'      => $this->term_names( $areas['final'] ),
			'resolved'   => $areas['resolved'],
			'unresolved' => $areas['unresolved'],
			'over'       => $areas['over'],
			'nearest'    => $areas['nearest'],
		);
	}

	/**
	 * "Austin, TX" / "Austin TX" / "Austin" (state taken from the primary term) -> area term
	 * that has a published city-listing page, or null.
	 */
	public function find_area_term( $name, $primary = null ) {
		$name  = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
		$state = '';
		if ( preg_match( '/^(.*?)[\s,]+([A-Za-z]{2})\.?$/', $name, $m ) ) {
			$name  = trim( $m[1], " ,." );
			$state = strtolower( $m[2] );
		}
		if ( ! $state && $primary && preg_match( '/-([a-z]{2})$/', $primary->slug, $m ) ) {
			$state = $m[1];
		}
		if ( '' === $name ) {
			return null;
		}
		$candidates = array();
		if ( $state ) {
			$term = get_term_by( 'slug', sanitize_title( $name . ' ' . $state ), 'area' );
			if ( $term ) {
				$candidates[] = $term;
			}
		}
		if ( ! $candidates ) {
			$terms = get_terms( array( 'taxonomy' => 'area', 'hide_empty' => false, 'name__like' => $name, 'number' => 50 ) );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$stem = strtolower( trim( preg_replace( '/\s*[-,]\s*[A-Za-z]{2}$/', '', $term->name ) ) );
				if ( $stem !== strtolower( $name ) ) {
					continue;
				}
				if ( $state && ! preg_match( '/-' . preg_quote( $state, '/' ) . '$/', $term->slug ) ) {
					continue;
				}
				$candidates[] = $term;
			}
		}
		foreach ( $candidates as $term ) {
			if ( $this->city_listing_id( $term->term_id ) ) {
				return $term;
			}
		}
		return null;
	}

	private function city_part( $name ) {
		$name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
		if ( preg_match( '/^(.*?)[\s,]+([A-Za-z]{2})\.?$/', $name, $m ) ) {
			$name = trim( $m[1], " ,." );
		}
		return strtolower( $name );
	}

	/**
	 * Area terms with a published city-listing page, in the primary term's state, ordered by
	 * distance from the primary term's coordinates (Haversine, like modules/nearest-cities).
	 */
	public function nearest_terms( $primary, $limit ) {
		global $wpdb;
		$lat = get_term_meta( $primary->term_id, 'latitude', true );
		$lng = get_term_meta( $primary->term_id, 'longitude', true );
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return array();
		}
		$state = preg_match( '/-([a-z]{2})$/', $primary->slug, $m ) ? $m[1] : '';
		$sql   = "
			SELECT t.term_id, t.name, t.slug,
			       ( 3959 * acos( cos( radians(%f) ) * cos( radians( lat.meta_value ) ) * cos( radians( lng.meta_value ) - radians(%f) ) + sin( radians(%f) ) * sin( radians( lat.meta_value ) ) ) ) AS distance
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'area'
			INNER JOIN {$wpdb->termmeta} lat ON lat.term_id = t.term_id AND lat.meta_key = 'latitude' AND lat.meta_value <> ''
			INNER JOIN {$wpdb->termmeta} lng ON lng.term_id = t.term_id AND lng.meta_key = 'longitude' AND lng.meta_value <> ''
			INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'city-listing' AND p.post_status = 'publish'
			WHERE t.term_id <> %d AND t.slug LIKE %s
			GROUP BY t.term_id
			HAVING distance <= %f
			ORDER BY distance ASC
			LIMIT %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, (float) $lat, (float) $lng, (float) $lat, (int) $primary->term_id, '%-' . $wpdb->esc_like( $state ), (float) self::NEAREST_MAX_MI, (int) $limit ) );
	}

	/* ------------------------------------------------------------------
	 * Rankings + cache: exactly what update-rankings-for-profile does
	 * ---------------------------------------------------------------- */

	/**
	 * Recalc the city pool of every area term on the profile (plus any terms just removed),
	 * the primary-state pool of every state term, then purge only those listing pages and the
	 * profile. Returns the purged ids.
	 */
	public function recalc_and_purge( $pid, array $extra_area_ids = array() ) {
		global $wpdb;
		clean_object_term_cache( $pid, 'profile' );
		$area_ids = array_values( array_unique( array_merge( $this->area_ids( $pid ), array_map( 'intval', $extra_area_ids ) ) ) );
		$city_listing_ids  = array();
		$state_listing_ids = array();

		foreach ( $area_ids as $term_id ) {
			$pool = $wpdb->get_col( $wpdb->prepare( "
				SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'area' AND tt.term_id = %d
				WHERE p.post_type = 'profile' AND p.post_status = 'publish'", $term_id ) );
			if ( $pool ) {
				DH_Profile_Rankings::recalc_pool( $pool, 'city_rank' );
			}
			$listing = $this->city_listing_id( $term_id );
			if ( $listing ) {
				$city_listing_ids[] = $listing;
			}
			if ( class_exists( 'DH_Bricks_Query_Helpers' ) ) {
				$niches = wp_get_object_terms( $pid, 'niche', array( 'fields' => 'ids' ) );
				if ( is_array( $niches ) && $niches ) {
					DH_Bricks_Query_Helpers::clear_proximity_cache( $term_id, $niches );
				}
			}
		}

		$state_terms = get_the_terms( $pid, 'state' );
		foreach ( is_array( $state_terms ) ? $state_terms : array() as $state_term ) {
			$all = $wpdb->get_col( $wpdb->prepare( "
				SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'state' AND tt.term_id = %d
				WHERE p.post_type = 'profile' AND p.post_status = 'publish'", $state_term->term_id ) );
			$pool = array();
			foreach ( $all as $id ) {
				$primary_state = DH_Taxonomy_Helpers::get_primary_state_term( (int) $id );
				if ( $primary_state && (int) $primary_state->term_id === (int) $state_term->term_id ) {
					$pool[] = (int) $id;
				}
			}
			if ( $pool ) {
				DH_Profile_Rankings::recalc_pool( $pool, 'state_rank' );
			}
			$listing = get_posts( array( 'post_type' => 'state-listing', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => 'state', 'field' => 'term_id', 'terms' => $state_term->term_id ) ) ) );
			if ( $listing ) {
				$state_listing_ids[] = (int) $listing[0];
			}
		}

		$city_listing_ids  = array_values( array_unique( $city_listing_ids ) );
		$state_listing_ids = array_values( array_unique( $state_listing_ids ) );
		foreach ( array_merge( $city_listing_ids, $state_listing_ids ) as $listing_id ) {
			do_action( 'litespeed_purge_post', (int) $listing_id );
		}
		do_action( 'litespeed_purge_post', (int) $pid );
		$urls = array();
		foreach ( array_merge( $city_listing_ids, $state_listing_ids, array( (int) $pid ) ) as $id ) {
			$urls[] = get_permalink( $id );
		}
		return array( 'city' => $city_listing_ids, 'state' => $state_listing_ids, 'profile' => (int) $pid, 'urls' => $urls );
	}

	/** Dry-run view of recalc_and_purge(): which pages a run would purge. */
	public function purge_targets( $pid, array $area_ids ) {
		$out = array();
		foreach ( array_unique( array_map( 'intval', $area_ids ) ) as $term_id ) {
			$listing = $this->city_listing_id( $term_id );
			if ( $listing ) {
				$out[] = get_permalink( $listing );
			}
		}
		$state_terms = get_the_terms( $pid, 'state' );
		foreach ( is_array( $state_terms ) ? $state_terms : array() as $state_term ) {
			$listing = get_posts( array( 'post_type' => 'state-listing', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => 'state', 'field' => 'term_id', 'terms' => $state_term->term_id ) ) ) );
			if ( $listing ) {
				$out[] = get_permalink( $listing[0] );
			}
		}
		$out[] = get_permalink( $pid );
		return array_values( array_unique( array_filter( $out ) ) );
	}

	public function city_listing_id( $term_id ) {
		$ids = get_posts( array( 'post_type' => 'city-listing', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => 'area', 'field' => 'term_id', 'terms' => (int) $term_id ) ) ) );
		return $ids ? (int) $ids[0] : 0;
	}

	private function set_featured( $pid, $value ) {
		if ( function_exists( 'update_field' ) ) {
			update_field( self::ACF_FEATURED_KEY, $value, $pid );
		}
		if ( (string) get_post_meta( $pid, 'featured', true ) !== (string) $value ) {
			update_post_meta( $pid, 'featured', $value );
			update_post_meta( $pid, '_featured', self::ACF_FEATURED_KEY );
		}
		wp_cache_delete( $pid, 'post_meta' );
	}

	/* ------------------------------------------------------------------
	 * Update token + emails
	 * ---------------------------------------------------------------- */

	/**
	 * Risk 2: the editing link goes to the buyer only when the buyer is verifiably the business
	 * (email on the profile's own domain, or the Owner's Box link plus the stored contact email);
	 * otherwise it goes to the contact address on file, and the buyer is told.
	 */
	private function maybe_issue_token( $pid, array $data, array $match ) {
		$email   = $this->email_of( $data );
		$domain  = $this->domain( get_post_meta( $pid, 'url', true ) );
		$contact = strtolower( (string) get_post_meta( $pid, 'contact_email', true ) );
		$mail_domain = $email ? substr( strrchr( $email, '@' ), 1 ) : '';
		$verified = ( $email && $domain && $mail_domain === $domain ) || ( 'profile_id' === $match['reason'] && $email && $contact && $email === $contact );
		if ( $verified ) {
			if ( class_exists( 'DH_Contact_Email' ) ) {
				( new DH_Contact_Email() )->record( $pid, $email, 'featured-checkout' );
			}
			return array( 'mode' => 'buyer', 'link' => $this->issue_token( $pid ) );
		}
		if ( $contact && $contact !== $email ) {
			$link = $this->issue_token( $pid );
			$this->mail( $contact, 'Your Goody Doggy profile is now Featured', $this->p( 'Hi,' ) . $this->p( 'Featured Placement was just bought for ' . esc_html( get_the_title( $pid ) ) . ' on Goody Doggy. As the contact on file for this business, you hold the link to update the profile:' ) . $this->p( '<a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a>' ) . $this->p( 'Goody Doggy' ) );
			return array( 'mode' => 'contact on file (' . $contact . ')', 'link' => '' );
		}
		return array( 'mode' => 'owner box', 'link' => '' );
	}

	private function issue_token( $pid ) {
		$raw = bin2hex( random_bytes( 16 ) );
		update_post_meta( $pid, 'update_token_hash', hash( 'sha256', $raw ) );
		update_post_meta( $pid, 'update_token_issued', current_time( 'mysql' ) );
		return home_url( '/update-profile/?t=' . $raw );
	}

	private function send_welcome( $pid, array $data, array $tier, array $areas, array $token ) {
		$to = $this->email_of( $data );
		if ( ! $to ) {
			return;
		}
		$first = isset( $data['names']['first_name'] ) ? trim( (string) $data['names']['first_name'] ) : '';
		$title = get_the_title( $pid );
		$url   = get_permalink( $pid );
		$items = '';
		foreach ( $areas['final'] as $term_id ) {
			$term    = get_term( $term_id, 'area' );
			$listing = $this->city_listing_id( $term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$items .= '<li>' . esc_html( $term->name ) . ( $listing ? ' - <a href="' . esc_url( get_permalink( $listing ) ) . '">' . esc_html( get_permalink( $listing ) ) . '</a>' : '' ) . '</li>';
			}
		}
		$label    = self::TIER_LABEL[ $tier['tier'] ];
		$renews   = 'year' === $tier['interval'] ? 'once a year' : 'every month';
		$badge    = home_url( '/badge/' . $pid . '/profile.svg' );
		$checkout = home_url( '/featured-checkout/' );
		$body  = $this->p( 'Hi' . ( $first ? ' ' . esc_html( $first ) : '' ) . ',' );
		$body .= $this->p( 'Your Featured Placement for <strong>' . esc_html( $title ) . '</strong> is live. Your profile: <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>' );
		$body .= $this->p( 'Your listing now sits in the Featured Dog Trainers section on ' . ( count( $areas['final'] ) === 1 ? 'this city page' : 'these city pages' ) . ':' ) . '<ul>' . $items . '</ul>';
		if ( $areas['unresolved'] ) {
			$body .= $this->p( 'We could not find a city page for: ' . esc_html( implode( ', ', $areas['unresolved'] ) ) . '. ' . ( $areas['nearest'] ? 'We used the nearest city pages instead.' : '' ) . ' You can change your city pages any time through the update link below.' );
		} elseif ( $areas['nearest'] ) {
			$body .= $this->p( 'Where you left a city blank we started you on the city pages nearest your business. You can change them any time through the update link below.' );
		}
		$body .= $this->p( 'It also rotates through the Featured Dog Trainers section on your state page and on the goodydoggy.com homepage, and your profile carries the Featured Trainer badge.' );
		$body .= $this->p( 'Badge: the Listing Tools box on your profile page has the Featured Trainer badge and a Copy Embed Code button for your own website. The badge image is <a href="' . esc_url( $badge ) . '">' . esc_html( $badge ) . '</a>.' );
		if ( 'buyer' === $token['mode'] ) {
			$body .= $this->p( 'Update your profile (more photos, social links, custom descriptions, your city pages) with this link, which is yours alone and works for 12 months: <a href="' . esc_url( $token['link'] ) . '">' . esc_html( $token['link'] ) . '</a>' );
		} elseif ( 0 === strpos( $token['mode'], 'contact' ) ) {
			$body .= $this->p( 'The link to update the profile went to the contact address we already hold for this business, so only the business can change what it says. If that is you, check that inbox.' );
		} else {
			$body .= $this->p( 'To update the profile (more photos, social links, custom descriptions, your city pages), use the Send us your changes link in the Listing Tools box on your profile page.' );
		}
		$body .= $this->p( 'Billing: ' . esc_html( $label ) . ' plan' . ( $tier['plan_name'] ? ' (' . esc_html( $tier['plan_name'] ) . ')' : '' ) . '. It renews ' . $renews . ' until you cancel. Your receipt from Stripe has the link to update your card or cancel; your placement then runs to the end of the period you paid for and your free profile stays. Not happy in the first 14 days? Reply to this email and we refund that charge in full.' );
		$body .= $this->p( 'To move to a different plan, cancel this one and pick the new plan at <a href="' . esc_url( $checkout ) . '">' . esc_html( $checkout ) . '</a>.' );
		$body .= $this->p( 'Goody Doggy' );
		$this->mail( $to, 'Your Featured Placement is live on Goody Doggy', $body );
	}

	private function hold_order( $entry_id, $reason, array $data, array $tier ) {
		$pending = get_option( self::OPTION_PENDING, array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}
		$fresh = ! isset( $pending[ $entry_id ] );
		$pending[ $entry_id ] = array(
			'since'  => isset( $pending[ $entry_id ]['since'] ) ? $pending[ $entry_id ]['since'] : time(),
			'last'   => time(),
			'reason' => $reason,
			'name'   => isset( $data['business_name'] ) ? (string) $data['business_name'] : '',
		);
		update_option( self::OPTION_PENDING, $pending, false );
		$this->log( $entry_id, 'Order held: no profile', $reason . '. Checked again daily.', 'error' );
		if ( $fresh ) {
			$this->mail(
				self::ADMIN_EMAIL,
				'Featured order needs a profile: ' . ( isset( $data['business_name'] ) ? $data['business_name'] : 'entry ' . $entry_id ),
				$this->p( 'A Featured Placement was paid for but no published profile matched (' . esc_html( $reason ) . ').' )
				. $this->p( 'Business: ' . esc_html( isset( $data['business_name'] ) ? $data['business_name'] : '' ) . '<br>Website: ' . esc_html( isset( $data['website'] ) ? $data['website'] : '' ) . '<br>Phone: ' . esc_html( isset( $data['phone'] ) ? $data['phone'] : '' ) . '<br>Email: ' . esc_html( $this->email_of( $data ) ) . '<br>Plan: ' . esc_html( self::TIER_LABEL[ $tier['tier'] ] . ' / ' . $tier['interval'] ) . '<br>Cities: ' . esc_html( implode( '; ', $this->requested_cities( $data ) ) ) )
				. $this->p( 'The order is checked again every day and goes Featured on its own once a published profile matches by website domain, phone or business name. To tie it to a profile now: <code>wp directory-helpers featured-billing replay --entry=' . $entry_id . ' --profile=&lt;post_id&gt;</code>.' )
				. $this->p( $this->entry_link( $entry_id ) )
			);
		}
	}

	private function unhold( $entry_id ) {
		$pending = get_option( self::OPTION_PENDING, array() );
		if ( is_array( $pending ) && isset( $pending[ $entry_id ] ) ) {
			unset( $pending[ $entry_id ] );
			update_option( self::OPTION_PENDING, $pending, false );
		}
	}

	/* ------------------------------------------------------------------
	 * Daily drift check
	 * ---------------------------------------------------------------- */

	public function schedule_drift_check() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 10:20 UTC' ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Three passes: live Stripe status of every Featured profile; Fluent Forms subscriptions that
	 * are active without a Featured profile; held orders (retry, and flag the ones over 24 hours).
	 * Emails Joe only when something was fixed or needs a decision.
	 */
	public function drift_check( $dry = false ) {
		global $wpdb;
		$fixed = array();
		$flags = array();
		$notes = array();

		$featured = $wpdb->get_col( "
			SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = 'featured' AND f.meta_value + 0 > 0
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'stripe_subscription_id' AND s.meta_value <> ''
			WHERE p.post_type = 'profile' AND p.post_status = 'publish'" );
		foreach ( $featured as $pid ) {
			$sub_id = (string) get_post_meta( $pid, 'stripe_subscription_id', true );
			$live   = $this->stripe_subscription( $sub_id );
			if ( is_wp_error( $live ) ) {
				$notes[] = "profile {$pid}: Stripe read failed ({$live->get_error_message()})";
				continue;
			}
			$status = isset( $live->status ) ? (string) $live->status : '';
			if ( in_array( $status, array( 'canceled', 'unpaid', 'incomplete_expired' ), true ) ) {
				$entry_id   = (int) get_post_meta( $pid, 'ff_submission_id', true );
				$submission = $entry_id ? $this->submission( $entry_id ) : null;
				if ( $submission && ! $dry ) {
					$r = $this->cancel( $submission, null, 'drift:stripe ' . $status );
					$fixed[] = "profile {$pid}: Stripe subscription {$sub_id} is {$status}; unfeatured ({$r['status']})";
				} else {
					$fixed[] = "profile {$pid}: Stripe subscription {$sub_id} is {$status}; " . ( $dry ? 'would unfeature' : 'no entry found, left Featured' );
				}
			}
		}

		$active = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fluentform_subscriptions WHERE form_id = %d AND status = 'active'", self::FORM_ID ) );
		foreach ( $active as $sub ) {
			$pid = $this->profile_for_entry( (int) $sub->submission_id );
			if ( $pid && (float) get_post_meta( $pid, 'featured', true ) > 0 ) {
				continue;
			}
			$submission = $this->submission( (int) $sub->submission_id );
			if ( ! $submission ) {
				continue;
			}
			$r = $this->activate( $submission, $sub, 'drift:active without Featured profile', $dry );
			if ( in_array( $r['status'], array( 'featured', 'would-feature' ), true ) ) {
				$fixed[] = "entry {$sub->submission_id}: active subscription had no Featured profile; " . $r['status'] . ' profile ' . $r['match']['post_id'];
			} elseif ( 'held' === $r['status'] ) {
				$flags[] = "entry {$sub->submission_id}: paid, still no profile (" . $r['match']['reason'] . ')' . ( isset( $r['held_hours'] ) ? '' : '' );
			}
		}

		$pending = get_option( self::OPTION_PENDING, array() );
		foreach ( is_array( $pending ) ? $pending : array() as $entry_id => $row ) {
			$hours = round( ( time() - (int) $row['since'] ) / 3600 );
			if ( $hours >= 24 ) {
				$flags[] = "entry {$entry_id} (" . ( isset( $row['name'] ) ? $row['name'] : '' ) . ") held {$hours}h: " . $row['reason'] . ' ' . $this->entry_link( $entry_id, false );
			}
		}

		$summary = array( 'fixed' => $fixed, 'flags' => array_values( array_unique( $flags ) ), 'notes' => $notes, 'checked' => count( $featured ), 'active_subscriptions' => count( $active ) );
		if ( ( $fixed || $summary['flags'] ) && ! $dry ) {
			$body = '';
			if ( $fixed ) {
				$body .= $this->p( 'Fixed:' ) . '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $fixed ) ) . '</li></ul>';
			}
			if ( $summary['flags'] ) {
				$body .= $this->p( 'Needs a decision:' ) . '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $summary['flags'] ) ) . '</li></ul>';
			}
			$this->mail( self::ADMIN_EMAIL, 'Featured billing drift check', $body );
		}
		error_log( '[DH Featured Billing] drift check: ' . wp_json_encode( $summary ) );
		return $summary;
	}

	/** Read a subscription from Stripe through Fluent Forms' own client (its stored connection, no second key). */
	public function stripe_subscription( $sub_id ) {
		if ( ! class_exists( '\FluentFormPro\Payments\PaymentMethods\Stripe\API\ApiRequest' ) || ! class_exists( '\FluentFormPro\Payments\PaymentMethods\Stripe\StripeSettings' ) ) {
			return new WP_Error( 'no_fluentform', 'Fluent Forms Pro Stripe client not loaded' );
		}
		$key = \FluentFormPro\Payments\PaymentMethods\Stripe\StripeSettings::getSecretKey( self::FORM_ID );
		if ( ! $key ) {
			return new WP_Error( 'no_key', 'Stripe is not connected in Fluent Forms' );
		}
		\FluentFormPro\Payments\PaymentMethods\Stripe\API\ApiRequest::set_secret_key( $key );
		$res = \FluentFormPro\Payments\PaymentMethods\Stripe\API\ApiRequest::retrieve( 'subscriptions/' . rawurlencode( $sub_id ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( isset( $res->error ) ) {
			return new WP_Error( 'stripe', isset( $res->error->message ) ? $res->error->message : 'Stripe error' );
		}
		return $res;
	}

	/* ------------------------------------------------------------------
	 * Checkout page: prefill, preselect, no-cache
	 * ---------------------------------------------------------------- */

	/** ?pid=<published profile> fills the hidden profile_id and the business fields it knows. */
	public function prefill_form( $form ) {
		if ( (int) $form->id !== self::FORM_ID || empty( $_GET['pid'] ) ) {
			return $form;
		}
		$pid = (int) wp_unslash( $_GET['pid'] );
		if ( ! $this->is_published_profile( $pid ) || ! isset( $form->fields['fields'] ) || ! is_array( $form->fields['fields'] ) ) {
			return $form;
		}
		$values = array(
			'profile_id'    => (string) $pid,
			'business_name' => get_the_title( $pid ),
			'website'       => (string) get_post_meta( $pid, 'url', true ),
			'phone'         => (string) get_post_meta( $pid, 'phone', true ),
		);
		foreach ( $form->fields['fields'] as &$field ) {
			$name = isset( $field['attributes']['name'] ) ? $field['attributes']['name'] : '';
			if ( $name && isset( $values[ $name ] ) && '' !== $values[ $name ] ) {
				$field['attributes']['value'] = $values[ $name ];
			}
		}
		unset( $field );
		return $form;
	}

	/** ?plan=plus|pro|premium&billing=month|year picks the matching subscription option. */
	public function preselect_plan( $data, $form ) {
		if ( (int) $form->id !== self::FORM_ID || empty( $_GET['plan'] ) || empty( $data['settings']['subscription_options'] ) ) {
			return $data;
		}
		$tier    = strtolower( sanitize_key( wp_unslash( $_GET['plan'] ) ) );
		$billing = isset( $_GET['billing'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['billing'] ) ) ) : 'month';
		$billing = in_array( $billing, array( 'year', 'yearly', 'annual', 'annually' ), true ) ? 'year' : 'month';
		$want    = null;
		foreach ( self::PLAN_INDEX as $i => $pair ) {
			if ( $pair[0] === $tier && $pair[1] === $billing ) {
				$want = $i;
			}
		}
		if ( null === $want ) {
			return $data;
		}
		foreach ( $data['settings']['subscription_options'] as $i => &$opt ) {
			$opt['is_default'] = $i === $want ? 'yes' : 'no';
		}
		unset( $opt );
		return $data;
	}

	public function nocache_checkout_pages() {
		if ( is_page( array( self::CHECKOUT_PAGE_ID, self::WELCOME_PAGE_ID ) ) ) {
			do_action( 'litespeed_control_set_nocache', 'dh featured checkout' );
		}
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	public function response( $submission ) {
		if ( is_array( $submission->response ) ) {
			return $submission->response;
		}
		$data = json_decode( (string) $submission->response, true );
		return is_array( $data ) ? $data : array();
	}

	public function submission( $entry_id ) {
		return function_exists( 'wpFluent' ) ? wpFluent()->table( 'fluentform_submissions' )->where( 'id', (int) $entry_id )->first() : null;
	}

	public function subscription_for_entry( $entry_id ) {
		return function_exists( 'wpFluent' ) ? wpFluent()->table( 'fluentform_subscriptions' )->where( 'submission_id', (int) $entry_id )->orderBy( 'id', 'DESC' )->first() : null;
	}

	/** The profile an entry was fulfilled on: submission meta first, then the profile that points back at the entry. */
	public function profile_for_entry( $entry_id ) {
		$pid = (int) $this->submission_meta( $entry_id, self::SUBMISSION_META );
		if ( $pid && 'profile' === get_post_type( $pid ) ) {
			return $pid;
		}
		$ids = get_posts( array( 'post_type' => 'profile', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => 'ff_submission_id', 'meta_value' => (int) $entry_id ) );
		return $ids ? (int) $ids[0] : 0;
	}

	private function submission_meta( $entry_id, $key ) {
		return class_exists( '\FluentForm\App\Helpers\Helper' ) ? \FluentForm\App\Helpers\Helper::getSubmissionMeta( (int) $entry_id, $key, '' ) : '';
	}

	private function set_submission_meta( $entry_id, $key, $value ) {
		if ( class_exists( '\FluentForm\App\Helpers\Helper' ) ) {
			\FluentForm\App\Helpers\Helper::setSubmissionMeta( (int) $entry_id, $key, $value, self::FORM_ID );
		}
	}

	public function area_ids( $pid ) {
		$terms = get_the_terms( $pid, 'area' );
		return is_array( $terms ) ? array_values( array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ) ) : array();
	}

	private function term_names( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$t = get_term( (int) $id, 'area' );
			if ( $t && ! is_wp_error( $t ) ) {
				$out[] = $t->name;
			}
		}
		return $out;
	}

	public function email_of( array $data ) {
		$email = isset( $data['email'] ) && is_scalar( $data['email'] ) ? strtolower( trim( (string) $data['email'] ) ) : '';
		return is_email( $email ) ? $email : '';
	}

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

	public function phone_key( $phone ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );
		return strlen( $digits ) >= 10 ? substr( $digits, -10 ) : '';
	}

	public function name_key( $name ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( html_entity_decode( (string) $name, ENT_QUOTES ) ) );
	}

	public function entry_link( $entry_id, $html = true ) {
		$url = admin_url( 'admin.php?page=fluent_forms&route=entries&form_id=' . self::FORM_ID . '#/entries/' . (int) $entry_id );
		return $html ? 'Entry: <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>' : $url;
	}

	private function p( $html ) {
		return '<p>' . $html . '</p>';
	}

	private function mail( $to, $subject, $html ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: Goody Doggy <' . self::ADMIN_EMAIL . '>', 'Reply-To: ' . self::ADMIN_EMAIL );
		$sent    = wp_mail( $to, $subject, $html, $headers );
		error_log( '[DH Featured Billing] mail to ' . $to . ' "' . $subject . '": ' . ( $sent ? 'sent' : 'FAILED' ) );
		return $sent;
	}

	/** Fluent Forms entry log (the entry's Logs tab) plus the PHP error log. */
	private function log( $entry_id, $title, $description, $status = 'info' ) {
		do_action( 'fluentform/log_data', array(
			'parent_source_id' => self::FORM_ID,
			'source_type'      => 'submission_item',
			'source_id'        => (int) $entry_id,
			'component'        => 'Featured Billing',
			'status'           => $status,
			'title'            => $title,
			'description'      => $description,
		) );
		error_log( '[DH Featured Billing] entry ' . (int) $entry_id . ': ' . $title . ' - ' . $description );
	}
}
