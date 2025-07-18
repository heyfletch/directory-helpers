<?php
/**
 * City Listing Generator Module
 *
 * @package  Directory_Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Adds the City Listing Generator admin menu page.
 */
function dh_clg_add_admin_menu() {
	add_submenu_page(
		'directory-helpers',
		__( 'City Listing Generator', 'directory-helpers' ),
		__( 'City Listing Generator', 'directory-helpers' ),
		'manage_options',
		'dh-city-listing-generator',
		'dh_clg_render_admin_page'
	);
}
add_action( 'admin_menu', 'dh_clg_add_admin_menu' );

/**
 * Renders the admin page for the City Listing Generator.
 */
function dh_clg_render_admin_page() {
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p><?php esc_html_e( 'Use this tool to bulk-create city listing pages. Enter one city per line, formatted as "City, ST".', 'directory-helpers' ); ?></p>

		<?php
		// Handle form submission.
		if ( isset( $_POST['dh_clg_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dh_clg_nonce'] ) ), 'dh_clg_generate_listings' ) ) {
			dh_clg_handle_form_submission();
		}
		?>

		<form method="post" action="">
			<?php wp_nonce_field( 'dh_clg_generate_listings', 'dh_clg_nonce' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="dh-clg-cities"><?php esc_html_e( 'Cities', 'directory-helpers' ); ?></label></th>
					<td><textarea id="dh-clg-cities" name="dh_clg_cities" class="large-text" rows="15"></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( 'Generate Listings', 'directory-helpers' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Handles the form submission and bulk-creates city listings.
 */
function dh_clg_handle_form_submission() {
	if ( ! isset( $_POST['dh_clg_cities'] ) || empty( $_POST['dh_clg_cities'] ) ) {
		echo '<div class="error"><p>' . esc_html__( 'Please provide a list of cities.', 'directory-helpers' ) . '</p></div>';
		return;
	}

	$cities_input   = sanitize_textarea_field( wp_unslash( $_POST['dh_clg_cities'] ) );
	$cities         = array_filter( array_map( 'trim', explode( "\n", $cities_input ) ) );
	$results        = array();
	$created_count  = 0;
	$skipped_count  = 0;
	$error_count    = 0;

	foreach ( $cities as $city_string ) {
		// --- Generate Post Data ---
		$post_title    = $city_string . ' Dog Trainers';
		$post_slug     = sanitize_title( $post_title );
		$area_slug     = sanitize_title( str_replace( ',', '', $city_string ) );
		$focus_keyword = 'dog trainers in ' . strtolower( $city_string );

		// --- Check for Duplicates ---
		$existing_post = get_page_by_path( $post_slug, OBJECT, 'city-listing' );
		if ( null !== $existing_post ) {
			$results[] = '<li>' . sprintf( esc_html__( 'Skipped: Post for "%s" already exists.', 'directory-helpers' ), esc_html( $city_string ) ) . '</li>';
			$skipped_count++;
			continue;
		}

		// --- Create Post ---
		$post_data = array(
			'post_title'  => $post_title,
			'post_name'   => $post_slug,
			'post_type'   => 'city-listing',
			'post_status' => 'draft',
		);
		$new_post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $new_post_id ) ) {
			$results[] = '<li>' . sprintf( esc_html__( 'Error: Could not create post for "%s". Reason: %s', 'directory-helpers' ), esc_html( $city_string ), esc_html( $new_post_id->get_error_message() ) ) . '</li>';
			$error_count++;
			continue;
		}

		// --- Set Taxonomies ---
		wp_set_object_terms( $new_post_id, $area_slug, 'area', true );
		wp_set_object_terms( $new_post_id, 'dog-trainer', 'niche', true );

		// --- Set Rank Math Keyword ---
		update_post_meta( $new_post_id, 'rank_math_focus_keyword', $focus_keyword );

		$results[] = '<li>' . sprintf( esc_html__( 'Created: Post for "%s" with ID %d.', 'directory-helpers' ), esc_html( $city_string ), esc_html( $new_post_id ) ) . '</li>';
		$created_count++;
	}

	// --- Display Results ---
	echo '<div class="updated">';
	echo '<p><strong>' . esc_html__( 'Processing Complete!', 'directory-helpers' ) . '</strong></p>';
	echo '<p>';
	echo sprintf( esc_html__( 'Created: %d, Skipped: %d, Errors: %d', 'directory-helpers' ), (int) $created_count, (int) $skipped_count, (int) $error_count );
	echo '</p>';
	if ( ! empty( $results ) ) {
		echo '<ul>' . wp_kses_post( implode( '', $results ) ) . '</ul>';
	}
	echo '</div>';
}
