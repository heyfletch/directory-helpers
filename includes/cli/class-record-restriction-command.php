<?php
/**
 * WP-CLI commands: restrict-listing / unrestrict-listing
 *
 * Report a Concern (2026-09-24). When Joe approves an official-record review,
 * the profile stays published but is restricted (DH_Profile_Status_Notice):
 * editorial note on the profile, off the state and city pages and search, no
 * numeric rank, no badges, no Owner's Box, no website or social links.
 *
 * Both commands then do the same bookkeeping as refresh-closed, for one profile:
 *   - recalc every city and state pool the profile sits in via recalc_pool()
 *   - recount the listing _profile_count
 *   - rebuild the instant search index
 *   - bump post_modified + IndexNow for the profile and its own listing pages
 *   - purge only the profile, its own listing pages and the proximity neighbours
 *
 * Usage:
 *   wp directory-helpers restrict-listing --profile=123 --note='<html>' --record-url=https://... [--agency='Name'] [--entry=45] [--dry-run]
 *   wp directory-helpers unrestrict-listing --profile=123 [--entry=46] [--dry-run]
 */

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

class DH_Record_Restriction_Command extends WP_CLI_Command {

    /**
     * Restrict a profile after an approved official-record review.
     *
     * ## OPTIONS
     *
     * --profile=<id>
     * : Published profile post ID
     *
     * --note=<html>
     * : The approved editorial note, without the "Editorial note:" label. Only <a> links survive.
     *
     * --record-url=<url>
     * : Link to the official record
     *
     * [--agency=<name>]
     * : Agency or body that published the record
     *
     * [--entry=<id>]
     * : Fluent Forms entry ID of the report
     *
     * [--dry-run]
     * : Validate and report without writing
     *
     * @when after_wp_load
     */
    public function restrict( $args, $assoc_args ) {
        $pid = $this->published_profile( $assoc_args );

        $note = isset( $assoc_args['note'] ) ? trim( wp_kses( $assoc_args['note'], array( 'a' => array( 'href' => array(), 'rel' => array(), 'target' => array() ) ) ) ) : '';
        $url  = isset( $assoc_args['record-url'] ) ? esc_url_raw( $assoc_args['record-url'] ) : '';
        if ( $note === '' || $url === '' ) {
            WP_CLI::error( '--note and --record-url are both required.' );
        }

        WP_CLI::line( 'Restricting ' . get_the_title( $pid ) . " ({$pid})." );
        if ( isset( $assoc_args['dry-run'] ) ) {
            WP_CLI::line( 'Note: ' . $note );
            WP_CLI::success( 'Dry run - nothing written.' );
            return;
        }

        update_post_meta( $pid, DH_Profile_Status_Notice::RESTRICTED_META, '1' );
        update_post_meta( $pid, 'dh_record_note', $note );
        update_post_meta( $pid, 'dh_record_url', $url );
        update_post_meta( $pid, 'dh_record_agency', isset( $assoc_args['agency'] ) ? sanitize_text_field( $assoc_args['agency'] ) : '' );
        update_post_meta( $pid, 'dh_record_noted', current_time( 'Y-m-d' ) );
        update_post_meta( $pid, 'dh_record_checked', current_time( 'Y-m-d' ) );
        if ( isset( $assoc_args['entry'] ) ) {
            update_post_meta( $pid, 'dh_record_entry', (int) $assoc_args['entry'] );
        }
        delete_post_meta( $pid, 'dh_record_lifted' );

        $this->settle( $pid );
        WP_CLI::success( "Profile {$pid} restricted." );
    }

    /**
     * Lift a restriction: the profile returns to its state and city pages, search and ranks.
     *
     * ## OPTIONS
     *
     * --profile=<id>
     * : Published profile post ID
     *
     * [--entry=<id>]
     * : Fluent Forms entry ID of the relisting request
     *
     * [--dry-run]
     * : Validate and report without writing
     *
     * @when after_wp_load
     */
    public function unrestrict( $args, $assoc_args ) {
        $pid = $this->published_profile( $assoc_args );
        if ( ! DH_Profile_Status_Notice::is_restricted( $pid ) ) {
            WP_CLI::error( "Profile {$pid} is not restricted." );
        }

        WP_CLI::line( 'Lifting the restriction on ' . get_the_title( $pid ) . " ({$pid})." );
        if ( isset( $assoc_args['dry-run'] ) ) {
            WP_CLI::success( 'Dry run - nothing written.' );
            return;
        }

        // The record URL, agency, entry and dates stay as history.
        delete_post_meta( $pid, DH_Profile_Status_Notice::RESTRICTED_META );
        delete_post_meta( $pid, 'dh_record_note' );
        update_post_meta( $pid, 'dh_record_lifted', current_time( 'Y-m-d' ) );
        if ( isset( $assoc_args['entry'] ) ) {
            update_post_meta( $pid, 'dh_record_lift_entry', (int) $assoc_args['entry'] );
        }

        $this->settle( $pid );
        WP_CLI::success( "Restriction lifted on profile {$pid}." );
    }

    /**
     * @return int
     */
    private function published_profile( $assoc_args ) {
        if ( ! class_exists( 'DH_Profile_Status_Notice' ) || ! class_exists( 'DH_Apply_Ratings_Command' ) || ! class_exists( 'DH_Refresh_Closed_Command' ) ) {
            WP_CLI::error( 'Profile Status Notice module, apply-ratings and refresh-closed commands must be loaded.' );
        }
        $pid = isset( $assoc_args['profile'] ) ? (int) $assoc_args['profile'] : 0;
        if ( ! $pid || get_post_type( $pid ) !== 'profile' || get_post_status( $pid ) !== 'publish' ) {
            WP_CLI::error( '--profile must be a published profile ID.' );
        }
        return $pid;
    }

    /**
     * Ranks, counts, search, freshness and a targeted purge for one changed profile.
     */
    private function settle( $pid ) {
        DH_Profile_Status_Notice::reset_restricted_cache();
        wp_cache_delete( $pid, 'post_meta' );
        if ( class_exists( 'DH_Profile_Badges' ) ) {
            DH_Profile_Badges::instance()->clear_profile_badge_caches( $pid );
        }

        $area_terms  = get_the_terms( $pid, 'area' );
        $state_terms = get_the_terms( $pid, 'state' );
        $area_terms  = ( empty( $area_terms ) || is_wp_error( $area_terms ) ) ? array() : $area_terms;
        $state_terms = ( empty( $state_terms ) || is_wp_error( $state_terms ) ) ? array() : $state_terms;

        $moved = 0;
        foreach ( $area_terms as $term ) {
            $pool = DH_Apply_Ratings_Command::city_pool( $term->term_id );
            if ( ! empty( $pool ) ) {
                $moved += count( DH_Profile_Rankings::recalc_pool( $pool, 'city_rank' ) );
            }
        }
        foreach ( $state_terms as $term ) {
            $pool = DH_Apply_Ratings_Command::state_pool( $term->term_id );
            if ( ! empty( $pool ) ) {
                $moved += count( DH_Profile_Rankings::recalc_pool( $pool, 'state_rank' ) );
            }
        }
        WP_CLI::line( "Rank pools recalculated: {$moved} ranks moved." );

        $own_ids = array();
        $counts  = class_exists( 'DH_Listing_Counts' ) ? DH_Listing_Counts::instance() : null;
        foreach ( $area_terms as $term ) {
            $listing = DH_Apply_Ratings_Command::listing_for_term( 'city-listing', 'area', $term->term_id );
            if ( $listing ) {
                $own_ids[] = (int) $listing;
            }
            if ( $counts ) {
                $counts->update_city_profile_count( $term->term_id );
            }
        }
        foreach ( $state_terms as $term ) {
            $listing = DH_Apply_Ratings_Command::listing_for_term( 'state-listing', 'state', $term->term_id );
            if ( $listing ) {
                $own_ids[] = (int) $listing;
            }
            if ( $counts ) {
                $counts->update_state_counts( $term->slug );
            }
        }
        WP_CLI::line( 'Listing counts updated for ' . count( $own_ids ) . ' own listing pages.' );

        WP_CLI::runcommand( 'dh search rebuild-cache', array( 'launch' => false, 'return' => true, 'exit_error' => false ) );
        WP_CLI::line( 'Instant search index rebuilt.' );

        if ( class_exists( 'DH_IndexNow_Helper' ) ) {
            DH_IndexNow_Helper::refresh_and_submit( array_merge( array( $pid ), $own_ids ) );
            WP_CLI::line( 'Freshness bumped and IndexNow submitted for the profile and its own listing pages.' );
        }

        $near_ids  = array_values( array_diff( DH_Refresh_Closed_Command::proximity_listing_ids( array( $pid ) ), $own_ids ) );
        $purge_ids = array_values( array_unique( array_merge( array( $pid ), $own_ids, $near_ids ) ) );
        foreach ( $purge_ids as $id ) {
            do_action( 'litespeed_purge_post', $id );
            usleep( 200 * 1000 );
        }
        WP_CLI::line( 'Purged ' . count( $purge_ids ) . ' pages (profile, ' . count( $own_ids ) . ' own listings, ' . count( $near_ids ) . ' proximity neighbours).' );
    }
}
