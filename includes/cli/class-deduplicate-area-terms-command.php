<?php
/**
 * Deduplicates area taxonomy terms.
 *
 * @package Directory_Helpers
 */

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	return;
}

/**
 * Finds and renames area terms with duplicate names.
 */
class DH_Deduplicate_Area_Terms_Command extends WP_CLI_Command {

	/**
	 * Deduplicates area term names for cities that exist in multiple states.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : If set, the command will only report the changes that would be made, without executing them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp directory-helpers deduplicate_area_terms
	 *     wp directory-helpers deduplicate_area_terms --dry-run
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			WP_CLI::line( 'Running in dry-run mode. No changes will be made.' );
		}

		$terms = get_terms( array(
			'taxonomy'   => 'area',
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) ) {
			WP_CLI::error( 'Could not retrieve area terms.' );
		}

		$grouped_terms = array();
		foreach ( $terms as $term ) {
			$grouped_terms[ $term->name ][] = $term;
		}

		$updated_count = 0;

		foreach ( $grouped_terms as $name => $term_group ) {
			if ( count( $term_group ) > 1 ) {
				WP_CLI::line( sprintf( 'Found %d terms with the name "%s":', count( $term_group ), $name ) );
				foreach ( $term_group as $term ) {
					$slug_parts = explode( '-', $term->slug );
					$state_abbr = strtoupper( end( $slug_parts ) );
					$new_name   = $term->name . ', ' . $state_abbr;

					WP_CLI::line( sprintf( '  - Term ID %d (slug: %s) will be renamed to "%s"', $term->term_id, $term->slug, $new_name ) );

					if ( ! $dry_run ) {
						$result = wp_update_term( $term->term_id, 'area', array( 'name' => $new_name ) );
						if ( is_wp_error( $result ) ) {
							WP_CLI::warning( sprintf( '    Failed to update term %d: %s', $term->term_id, $result->get_error_message() ) );
						} else {
							$updated_count++;
						}
					}
				}
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run complete. No changes were made.' );
		} else {
			WP_CLI::success( sprintf( 'Operation complete. Updated %d term(s).', $updated_count ) );
		}
	}

	/**
	 * Updates area term names from "City, ST" format to "City - ST" format.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : If set, the command will only report the changes that would be made, without executing them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp directory-helpers update_area_term_format
	 *     wp directory-helpers update_area_term_format --dry-run
	 *
	 * @when after_wp_load
	 */
	public function update_area_term_format( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			WP_CLI::line( 'Running in dry-run mode. No changes will be made.' );
		}

		$terms = get_terms( array(
			'taxonomy'   => 'area',
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) ) {
			WP_CLI::error( 'Could not retrieve area terms.' );
		}

		$updated_count = 0;

		foreach ( $terms as $term ) {
			// Check if the term name matches the "City, ST" pattern
			if ( preg_match( '/^(.+), ([A-Z]{2})$/', $term->name, $matches ) ) {
				$city_name  = $matches[1];
				$state_abbr = $matches[2];
				$new_name   = $city_name . ' - ' . $state_abbr;

				WP_CLI::line( sprintf( 'Term ID %d: "%s" will be renamed to "%s"', $term->term_id, $term->name, $new_name ) );

				if ( ! $dry_run ) {
					$result = wp_update_term( $term->term_id, 'area', array( 'name' => $new_name ) );
					if ( is_wp_error( $result ) ) {
						WP_CLI::warning( sprintf( '  Failed to update term %d: %s', $term->term_id, $result->get_error_message() ) );
					} else {
						$updated_count++;
					}
				}
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run complete. No changes were made.' );
		} else {
			WP_CLI::success( sprintf( 'Operation complete. Updated %d term(s).', $updated_count ) );
		}
	}

	/**
	 * Updates state listing post titles from "State Dog Trainers" to just "State".
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : If set, the command will only report the changes that would be made, without executing them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp directory-helpers update_state_listing_titles
	 *     wp directory-helpers update_state_listing_titles --dry-run
	 *
	 * @when after_wp_load
	 */
	public function update_state_listing_titles( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( $dry_run ) {
			WP_CLI::line( 'Running in dry-run mode. No changes will be made.' );
		}

		$posts = get_posts( array(
			'post_type'      => 'state-listing',
			'post_status'    => 'any',
			'posts_per_page' => -1,
		) );

		if ( empty( $posts ) ) {
			WP_CLI::error( 'No state-listing posts found.' );
		}

		WP_CLI::line( sprintf( 'Found %d state-listing posts to check.', count( $posts ) ) );
		$updated_count = 0;

		foreach ( $posts as $post ) {
			// Check if the post title ends with " Dog Trainers"
			if ( preg_match( '/^(.+) Dog Trainers$/', $post->post_title, $matches ) ) {
				$state_name = trim( $matches[1] );
				$new_title  = $state_name;

				WP_CLI::line( sprintf( 'Post ID %d: "%s" will be renamed to "%s"', $post->ID, $post->post_title, $new_title ) );

				if ( ! $dry_run ) {
					$result = wp_update_post( array(
						'ID'         => $post->ID,
						'post_title' => $new_title,
					) );
					if ( is_wp_error( $result ) ) {
						WP_CLI::warning( sprintf( '  Failed to update post %d: %s', $post->ID, $result->get_error_message() ) );
					} elseif ( $result === 0 ) {
						WP_CLI::warning( sprintf( '  Failed to update post %d: Unknown error', $post->ID ) );
					} else {
						$updated_count++;
					}
				}
			}
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run complete. No changes were made.' );
		} else {
			WP_CLI::success( sprintf( 'Operation complete. Updated %d post(s).', $updated_count ) );
		}
	}
}
