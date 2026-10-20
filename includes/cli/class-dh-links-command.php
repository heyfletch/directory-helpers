<?php
/**
 * WP-CLI commands: links-recheck, profile-urls-check, links-report
 *
 * Rechecks external-link rows and trainer profile website URLs through the
 * existing DH_External_Link_Management checker (cli_check_url) and reports
 * broken and bot-blocked items. Designed for a monthly job over SSH.
 *
 * Usage:
 *   wp directory-helpers links-recheck [--older-than=<days>] [--limit=<n>] [--sleep=<ms>] [--dry-run] [--format=<table|json>]
 *   wp directory-helpers profile-urls-check [--post-status=<status>] [--older-than=<days>] [--limit=<n>] [--sleep=<ms>] [--dry-run] [--format=<table|json>]
 *   wp directory-helpers links-report [--type=<links|profiles>] [--bucket=<broken|blocked|unknown>] [--format=<table|csv|json>]
 */

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

class DH_Links_Command extends WP_CLI_Command {

    /**
     * Recheck stale external-link rows through the existing checker.
     *
     * ## OPTIONS
     *
     * [--older-than=<days>]
     * : Recheck rows last checked more than this many days ago (default 30)
     *
     * [--limit=<n>]
     * : Check at most this many rows (default: no limit)
     *
     * [--sleep=<ms>]
     * : Sleep between HTTP requests in milliseconds (default 250)
     *
     * [--dry-run]
     * : Select and count without any HTTP request or write
     *
     * [--format=<table|json>]
     * : Output format; json prints only the summary as one JSON object (default table)
     *
     * @when after_wp_load
     */
    public function links_recheck( $args, $assoc_args ) {
        global $wpdb, $dh_external_link_management;

        if ( ! $dh_external_link_management instanceof DH_External_Link_Management ) {
            WP_CLI::error( 'External Link Management module is not active.' );
            return;
        }

        $older_than = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'older-than', 30 );
        $limit      = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
        $sleep_ms   = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'sleep', 250 );
        $dry_run    = isset( $assoc_args['dry-run'] );
        $format     = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
            WP_CLI::error( 'Invalid --format; use table or json.' );
            return;
        }

        $table = $wpdb->prefix . 'dh_external_links';
        $now   = current_time( 'mysql' );

        $rows = $wpdb->get_results( "SELECT id, post_id, current_url, status_code, last_checked, is_duplicate, status_override_code, status_override_expires FROM {$table} WHERE current_url <> ''" );

        $selected         = array();
        $skipped_override = 0;
        $skipped_duplicate = 0;
        $skipped_fresh    = 0;

        foreach ( (array) $rows as $r ) {
            if ( ! empty( $r->is_duplicate ) ) {
                $skipped_duplicate++;
                continue;
            }
            $ov_code = isset( $r->status_override_code ) ? (int) $r->status_override_code : 0;
            $ov_exp  = ( isset( $r->status_override_expires ) && $r->status_override_expires ) ? strtotime( (string) $r->status_override_expires ) : 0;
            if ( $ov_code && $ov_exp && $ov_exp > time() ) {
                $skipped_override++;
                continue;
            }
            if ( ! DH_Link_Status::needs_recheck( $r->last_checked, $older_than, $now, (int) $r->status_code ) ) {
                $skipped_fresh++;
                continue;
            }
            $selected[] = $r;
        }

        $skipped_limit = 0;
        if ( $limit > 0 && count( $selected ) > $limit ) {
            $skipped_limit = count( $selected ) - $limit;
            $selected      = array_slice( $selected, 0, $limit );
        }

        $summary = array(
            'selected'          => count( $selected ) + $skipped_limit,
            'checked'           => 0,
            'skipped'           => 0,
            'skipped_override'  => $skipped_override,
            'skipped_duplicate' => $skipped_duplicate,
            'skipped_fresh'     => $skipped_fresh,
            'skipped_limit'     => $skipped_limit,
            'ok'                => 0,
            'blocked'           => 0,
            'broken'            => 0,
            'unknown'           => 0,
            'link_to_text'      => 0,
            'text_to_link'      => 0,
            'dry_run'           => $dry_run,
        );

        if ( ! $dry_run ) {
            $progress = ( 'json' !== $format ) ? WP_CLI\Utils\make_progress_bar( 'Checking links', count( $selected ), 10 ) : null;
            $total    = count( $selected );

            foreach ( $selected as $i => $r ) {
                $was_link = DH_Link_Status::renders_as_link( DH_Link_Status::effective_code( (int) $r->status_code, $r->status_override_code, $r->status_override_expires, $now ) );

                list( $code, $text ) = $dh_external_link_management->cli_check_url( (string) $r->current_url, 15, (int) $r->id );

                // Same write path as bulk_recheck_links()
                $wpdb->update(
                    $table,
                    array(
                        'status_code'  => $code,
                        'status_text'  => $text,
                        'last_checked' => current_time( 'mysql' ),
                        'updated_at'   => current_time( 'mysql' ),
                    ),
                    array( 'id' => $r->id ),
                    array( '%d', '%s', '%s', '%s' ),
                    array( '%d' )
                );

                $summary['checked']++;
                $summary[ DH_Link_Status::classify( $code ) ]++;

                $is_link = DH_Link_Status::renders_as_link( $code );
                if ( $was_link && ! $is_link ) {
                    $summary['link_to_text']++;
                } elseif ( ! $was_link && $is_link ) {
                    $summary['text_to_link']++;
                }

                if ( $progress ) {
                    $progress->tick();
                }
                if ( $sleep_ms > 0 && $i + 1 < $total ) {
                    usleep( $sleep_ms * 1000 );
                }
            }

            if ( $progress ) {
                $progress->finish();
            }
        }

        $summary['skipped'] = $skipped_override + $skipped_duplicate + $skipped_fresh + $skipped_limit;

        if ( 'json' === $format ) {
            WP_CLI::line( json_encode( $summary ) );
            return;
        }

        WP_CLI::line( 'Selected for recheck: ' . $summary['selected'] );
        WP_CLI::line( 'Checked: ' . $summary['checked'] );
        WP_CLI::line( 'Skipped: ' . $summary['skipped'] . ' (' . $summary['skipped_override'] . ' override, ' . $summary['skipped_duplicate'] . ' duplicate, ' . $summary['skipped_fresh'] . ' fresh, ' . $summary['skipped_limit'] . ' over limit)' );
        WP_CLI::line( 'Status buckets: ok=' . $summary['ok'] . ' blocked=' . $summary['blocked'] . ' broken=' . $summary['broken'] . ' unknown=' . $summary['unknown'] );
        WP_CLI::line( 'Renders changed: ' . $summary['link_to_text'] . ' link to plain text, ' . $summary['text_to_link'] . ' plain text back to link' );

        if ( $dry_run ) {
            WP_CLI::success( 'Dry run complete - nothing checked or written.' );
        } else {
            WP_CLI::success( 'Done. Checked ' . $summary['checked'] . ' links.' );
        }
    }

    /**
     * Check trainer profile website URLs and store the result in post meta.
     *
     * ## OPTIONS
     *
     * [--post-status=<status>]
     * : Check profiles with this post status (default publish)
     *
     * [--older-than=<days>]
     * : Recheck profiles last checked more than this many days ago (default 30)
     *
     * [--limit=<n>]
     * : Check at most this many profiles (default: no limit)
     *
     * [--sleep=<ms>]
     * : Sleep between HTTP requests in milliseconds (default 250)
     *
     * [--dry-run]
     * : Select and count without any HTTP request or write
     *
     * [--format=<table|json>]
     * : Output format; json prints only the summary as one JSON object (default table)
     *
     * @when after_wp_load
     */
    public function profile_urls_check( $args, $assoc_args ) {
        global $dh_external_link_management;

        if ( ! $dh_external_link_management instanceof DH_External_Link_Management ) {
            WP_CLI::error( 'External Link Management module is not active.' );
            return;
        }

        $post_status = WP_CLI\Utils\get_flag_value( $assoc_args, 'post-status', 'publish' );
        $older_than  = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'older-than', 30 );
        $limit       = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
        $sleep_ms    = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'sleep', 250 );
        $dry_run     = isset( $assoc_args['dry-run'] );
        $format      = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
            WP_CLI::error( 'Invalid --format; use table or json.' );
            return;
        }

        // MySQL datetime, UTC
        $now = gmdate( 'Y-m-d H:i:s' );

        $ids = get_posts( array(
            'post_type'      => 'profile',
            'post_status'    => $post_status,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => array(
                array(
                    'key'     => 'url',
                    'value'   => '',
                    'compare' => '!=',
                ),
            ),
        ) );

        $selected      = array();
        $skipped_fresh = 0;

        foreach ( (array) $ids as $id ) {
            $url = get_post_meta( $id, 'url', true );
            if ( '' === $url || null === $url ) {
                continue;
            }
            $checked = get_post_meta( $id, '_dh_url_checked', true );
            $code    = (int) get_post_meta( $id, '_dh_url_status_code', true );
            if ( ! DH_Link_Status::needs_recheck( $checked, $older_than, $now, $code ) ) {
                $skipped_fresh++;
                continue;
            }
            $selected[] = array( 'id' => (int) $id, 'url' => $url );
        }

        $skipped_limit = 0;
        if ( $limit > 0 && count( $selected ) > $limit ) {
            $skipped_limit = count( $selected ) - $limit;
            $selected      = array_slice( $selected, 0, $limit );
        }

        $summary = array(
            'selected'      => count( $selected ) + $skipped_limit,
            'checked'       => 0,
            'skipped'       => 0,
            'skipped_fresh' => $skipped_fresh,
            'skipped_limit' => $skipped_limit,
            'ok'            => 0,
            'blocked'       => 0,
            'broken'        => 0,
            'unknown'       => 0,
            'dry_run'       => $dry_run,
        );

        if ( ! $dry_run ) {
            $progress = ( 'json' !== $format ) ? WP_CLI\Utils\make_progress_bar( 'Checking profile URLs', count( $selected ), 10 ) : null;
            $total    = count( $selected );

            foreach ( $selected as $i => $profile ) {
                // No link row exists for a profile URL, so no override is written
                list( $code, $text ) = $dh_external_link_management->cli_check_url( $profile['url'], 15, null );

                $bucket = DH_Link_Status::classify( $code );
                // A 403 body the checker identifies as a Cloudflare or bot challenge
                // comes back as 200 with a marker text; store those as blocked.
                if ( 'ok' === $bucket && ( false !== stripos( (string) $text, 'cloudflare' ) || false !== stripos( (string) $text, 'bot-protected' ) ) ) {
                    $bucket = 'blocked';
                }

                update_post_meta( $profile['id'], '_dh_url_status_code', (int) $code );
                update_post_meta( $profile['id'], '_dh_url_status', $bucket );
                update_post_meta( $profile['id'], '_dh_url_checked', gmdate( 'Y-m-d H:i:s' ) );

                $summary['checked']++;
                $summary[ $bucket ]++;

                if ( $progress ) {
                    $progress->tick();
                }
                if ( $sleep_ms > 0 && $i + 1 < $total ) {
                    usleep( $sleep_ms * 1000 );
                }
            }

            if ( $progress ) {
                $progress->finish();
            }
        }

        $summary['skipped'] = $skipped_fresh + $skipped_limit;

        if ( 'json' === $format ) {
            WP_CLI::line( json_encode( $summary ) );
            return;
        }

        WP_CLI::line( 'Selected for check: ' . $summary['selected'] );
        WP_CLI::line( 'Checked: ' . $summary['checked'] );
        WP_CLI::line( 'Skipped: ' . $summary['skipped'] . ' (' . $summary['skipped_fresh'] . ' fresh, ' . $summary['skipped_limit'] . ' over limit)' );
        WP_CLI::line( 'Status buckets: ok=' . $summary['ok'] . ' blocked=' . $summary['blocked'] . ' broken=' . $summary['broken'] . ' unknown=' . $summary['unknown'] );

        if ( $dry_run ) {
            WP_CLI::success( 'Dry run complete - nothing checked or written.' );
        } else {
            WP_CLI::success( 'Done. Checked ' . $summary['checked'] . ' profile URLs.' );
        }
    }

    /**
     * Report broken and bot-blocked external links or profile URLs.
     *
     * ## OPTIONS
     *
     * [--type=<links|profiles>]
     * : What to report (default links)
     *
     * [--bucket=<broken|blocked|unknown>]
     * : Only show this bucket (default: broken and blocked)
     *
     * [--format=<table|csv|json>]
     * : Output format (default table)
     *
     * @when after_wp_load
     */
    public function links_report( $args, $assoc_args ) {
        global $wpdb;

        $type   = WP_CLI\Utils\get_flag_value( $assoc_args, 'type', 'links' );
        $bucket = WP_CLI\Utils\get_flag_value( $assoc_args, 'bucket', null );
        $format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        if ( ! in_array( $type, array( 'links', 'profiles' ), true ) ) {
            WP_CLI::error( 'Invalid --type; use links or profiles.' );
            return;
        }
        if ( null !== $bucket && ! in_array( $bucket, array( 'broken', 'blocked', 'unknown' ), true ) ) {
            WP_CLI::error( 'Invalid --bucket; use broken, blocked or unknown.' );
            return;
        }
        if ( ! in_array( $format, array( 'table', 'csv', 'json' ), true ) ) {
            WP_CLI::error( 'Invalid --format; use table, csv or json.' );
            return;
        }

        $items = array();

        if ( 'links' === $type ) {
            $table = $wpdb->prefix . 'dh_external_links';
            $rows  = $wpdb->get_results( "SELECT id, post_id, current_url, status_code, status_override_code, status_override_expires, last_checked FROM {$table} WHERE current_url <> '' ORDER BY id ASC" );
            $now   = current_time( 'mysql' );

            foreach ( (array) $rows as $r ) {
                $effective = DH_Link_Status::effective_code( (int) $r->status_code, $r->status_override_code, $r->status_override_expires, $now );
                $b         = DH_Link_Status::classify( $effective );
                if ( null !== $bucket ? $b !== $bucket : ( 'broken' !== $b && 'blocked' !== $b ) ) {
                    continue;
                }
                $items[] = array(
                    'id'           => (int) $r->id,
                    'post_id'      => (int) $r->post_id,
                    'permalink'    => get_permalink( (int) $r->post_id ),
                    'current_url'  => (string) $r->current_url,
                    'status_code'  => (int) $r->status_code,
                    'last_checked' => (string) $r->last_checked,
                );
            }

            WP_CLI\Utils\format_items( $format, $items, array( 'id', 'post_id', 'permalink', 'current_url', 'status_code', 'last_checked' ) );
            return;
        }

        $ids = get_posts( array(
            'post_type'      => 'profile',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => '_dh_url_status_code',
            'meta_compare'   => 'EXISTS',
        ) );

        foreach ( (array) $ids as $id ) {
            $bucket_stored = get_post_meta( $id, '_dh_url_status', true );
            $b             = $bucket_stored !== '' ? $bucket_stored : DH_Link_Status::classify( (int) get_post_meta( $id, '_dh_url_status_code', true ) );
            if ( null !== $bucket ? $b !== $bucket : ( 'broken' !== $b && 'blocked' !== $b ) ) {
                continue;
            }
            $items[] = array(
                'post_id'     => (int) $id,
                'title'       => get_the_title( $id ),
                'permalink'   => get_permalink( $id ),
                'url'         => (string) get_post_meta( $id, 'url', true ),
                'status_code' => (int) get_post_meta( $id, '_dh_url_status_code', true ),
                'checked'     => (string) get_post_meta( $id, '_dh_url_checked', true ),
            );
        }

        WP_CLI\Utils\format_items( $format, $items, array( 'post_id', 'title', 'permalink', 'url', 'status_code', 'checked' ) );
    }
}
