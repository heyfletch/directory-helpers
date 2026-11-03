<?php
/**
 * WP-CLI command: search-test
 *
 * Runs the Search Plan test from the Search Test module (modules/search-test):
 * half the cities, picked by crc32(salt|area slug), get the test titles
 * and heading; every city page loses "(Updated for YYYY)".
 *
 * Usage:
 *   wp directory-helpers search-test prepare --salt=<salt>   (set the groups; nothing live changes, preview works)
 *   wp directory-helpers search-test start                   (go live, queue every changed page, purge nightly)
 *   wp directory-helpers search-test status
 *   wp directory-helpers search-test export --file=<path>    (every city and profile with its group, for reporting)
 *   wp directory-helpers search-test stop                    (test cities back to today's version; the year stays off)
 *
 * The nightly WP-Cron event (2:00 AM Eastern) runs purge-night, which purges
 * about 1,000 queued pages in batches of 100. Never site-wide.
 */

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

class DH_Search_Test_Command extends WP_CLI_Command {

    /**
     * Set the salt that splits cities into test and control. Nothing live changes.
     *
     * ## OPTIONS
     *
     * --salt=<salt>
     * : Salt for crc32(salt|area slug)
     *
     * @when after_wp_load
     */
    public function prepare( $args, $assoc_args ) {
        $opt = get_option( DH_Search_Test::OPTION, array() );
        if ( isset( $opt['status'] ) && $opt['status'] === 'on' ) {
            WP_CLI::error( 'The test is running; stop it before changing the salt.' );
        }
        update_option( DH_Search_Test::OPTION, array( 'status' => 'off', 'salt' => (string) $assoc_args['salt'], 'started' => '' ), true );
        WP_CLI::success( 'Salt set. Preview any page with ?' . DH_Search_Test::PREVIEW_PARAM . '=' . DH_Search_Test::preview_key() );
    }

    /**
     * Start the test: titles and heading go live on test cities, the year comes off every
     * city page, and every changed page is queued for the nightly purge.
     *
     * @when after_wp_load
     */
    public function start( $args, $assoc_args ) {
        $opt = get_option( DH_Search_Test::OPTION, array() );
        if ( empty( $opt['salt'] ) ) {
            WP_CLI::error( 'Run prepare --salt=<salt> first.' );
        }
        if ( isset( $opt['status'] ) && $opt['status'] === 'on' ) {
            WP_CLI::error( 'Already running since ' . $opt['started'] . '.' );
        }
        $opt['status']  = 'on';
        $opt['started'] = wp_date( 'Y-m-d' );
        update_option( DH_Search_Test::OPTION, $opt, true );
        update_option( DH_Search_Test::CLEANUP_OPTION, '1', true );
        $ids = $this->build_queue( 'start' );
        update_option( DH_Search_Test::QUEUE_OPTION, array( 'mode' => 'start', 'ids' => $ids, 'pos' => 0, 'built' => wp_date( 'c' ) ), false );
        DH_Search_Test::schedule_nightly();
        WP_CLI::success( sprintf( 'Test started %s. %d pages queued for the nightly purge.', $opt['started'], count( $ids ) ) );
    }

    /**
     * Stop the test: test cities go back to today's version (the year stays off city pages),
     * and their pages are queued for the nightly purge.
     *
     * @when after_wp_load
     */
    public function stop( $args, $assoc_args ) {
        $opt = get_option( DH_Search_Test::OPTION, array() );
        if ( empty( $opt['status'] ) || $opt['status'] !== 'on' ) {
            WP_CLI::error( 'The test is not running.' );
        }
        $ids           = $this->build_queue( 'stop' );
        $opt['status'] = 'off';
        $opt['stopped'] = wp_date( 'Y-m-d' );
        update_option( DH_Search_Test::OPTION, $opt, true );
        update_option( DH_Search_Test::QUEUE_OPTION, array( 'mode' => 'stop', 'ids' => $ids, 'pos' => 0, 'built' => wp_date( 'c' ) ), false );
        DH_Search_Test::schedule_nightly();
        WP_CLI::success( sprintf( 'Test stopped. %d test-city pages queued for the nightly purge.', count( $ids ) ) );
    }

    /**
     * Show the test state, group sizes and purge progress.
     *
     * @when after_wp_load
     */
    public function status( $args, $assoc_args ) {
        $opt   = get_option( DH_Search_Test::OPTION, array() );
        $queue = get_option( DH_Search_Test::QUEUE_OPTION, array() );
        $groups = array( 'test' => 0, 'control' => 0 );
        foreach ( $this->published( 'city-listing' ) as $cid ) {
            $g = DH_Search_Test::group_for_area( DH_Search_Test::area_slug( get_post( $cid ) ) );
            if ( $g ) {
                $groups[ $g ]++;
            }
        }
        $next = wp_next_scheduled( DH_Search_Test::CRON_HOOK );
        WP_CLI::line( 'Status:        ' . ( isset( $opt['status'] ) ? $opt['status'] : 'not set' ) . ( ! empty( $opt['started'] ) ? ' (started ' . $opt['started'] . ')' : '' ) );
        WP_CLI::line( 'Salt:          ' . ( ! empty( $opt['salt'] ) ? $opt['salt'] : '-' ) );
        WP_CLI::line( 'Cities:        ' . $groups['test'] . ' test, ' . $groups['control'] . ' control' );
        WP_CLI::line( 'Year cleanup:  ' . ( get_option( DH_Search_Test::CLEANUP_OPTION ) ? 'on' : 'off' ) );
        WP_CLI::line( 'Queue:         ' . ( empty( $queue['ids'] ) ? 'empty' : sprintf( '%s, %d of %d purged', $queue['mode'], $queue['pos'], count( $queue['ids'] ) ) ) );
        WP_CLI::line( 'Next purge:    ' . ( $next ? wp_date( 'Y-m-d H:i T', $next, new DateTimeZone( 'America/New_York' ) ) : '-' ) );
        WP_CLI::line( 'Preview:       ?' . DH_Search_Test::PREVIEW_PARAM . '=' . DH_Search_Test::preview_key() );
    }

    /**
     * Write every published city page and profile with its area and group, for reporting.
     *
     * ## OPTIONS
     *
     * --file=<path>
     * : TSV output path
     *
     * @when after_wp_load
     */
    public function export( $args, $assoc_args ) {
        $fh = fopen( $assoc_args['file'], 'w' );
        if ( ! $fh ) {
            WP_CLI::error( 'Cannot write ' . $assoc_args['file'] );
        }
        fwrite( $fh, "post_id\tpost_type\tpath\tarea\tgroup\tfeatured\n" );
        $n = 0;
        foreach ( array( 'city-listing', 'profile' ) as $type ) {
            foreach ( $this->published( $type ) as $id ) {
                $post = get_post( $id );
                $slug = DH_Search_Test::area_slug( $post );
                fwrite( $fh, implode( "\t", array(
                    $id,
                    $type,
                    wp_make_link_relative( get_permalink( $post ) ),
                    $slug,
                    DH_Search_Test::group_for_area( $slug ),
                    ( $type === 'profile' && DH_Search_Test::is_featured( $id ) ) ? 1 : 0,
                ) ) . "\n" );
                $n++;
            }
        }
        fclose( $fh );
        WP_CLI::success( "Wrote $n rows to " . $assoc_args['file'] );
    }

    /**
     * Purge tonight's share of the queue. Each batch of 100 runs in its own WP-CLI process,
     * because LiteSpeed gathers every purge in a WP-CLI process into one stored header.
     *
     * ## OPTIONS
     *
     * [--pages=<n>]
     * : Pages to purge tonight (default 1000)
     *
     * @subcommand purge-night
     * @when after_wp_load
     */
    public function purge_night( $args, $assoc_args ) {
        while ( ob_get_level() ) {
            ob_end_flush();
        }
        WP_CLI::line( wp_date( 'Y-m-d H:i T', null, new DateTimeZone( 'America/New_York' ) ) . ' purge-night' );
        if ( $this->other_job_running() ) {
            WP_CLI::warning( 'prime-all or apply-ratings is running; skipped tonight.' );
            return;
        }
        $pages = isset( $assoc_args['pages'] ) ? max( 1, (int) $assoc_args['pages'] ) : 1000;
        for ( $done = 0; $done < $pages; $done += 100 ) {
            $run = WP_CLI::runcommand(
                'directory-helpers search-test purge-next --limit=' . min( 100, $pages - $done ),
                array( 'launch' => true, 'return' => 'all', 'exit_error' => false )
            );
            WP_CLI::line( trim( $run->stdout . ' ' . $run->stderr ) );
            if ( $run->return_code !== 0 || strpos( $run->stdout, 'Queue empty' ) !== false ) {
                break;
            }
        }
    }

    /**
     * Purge and re-warm the next pages in the queue.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Pages to purge (default 100)
     *
     * @subcommand purge-next
     * @when after_wp_load
     */
    public function purge_next( $args, $assoc_args ) {
        $limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 100;
        $queue = get_option( DH_Search_Test::QUEUE_OPTION, array() );
        $ids   = empty( $queue['ids'] ) ? array() : array_slice( $queue['ids'], (int) $queue['pos'], $limit );
        if ( ! $ids ) {
            wp_clear_scheduled_hook( DH_Search_Test::CRON_HOOK );
            WP_CLI::line( 'Queue empty.' );
            return;
        }
        foreach ( $ids as $id ) {
            do_action( 'litespeed_purge_post', (int) $id );
        }
        // A WP-CLI purge only reaches LiteSpeed through a later web request: send it now,
        // then warm the fresh pages.
        if ( method_exists( '\LiteSpeed\Core', 'kick_purge_queue' ) ) {
            \LiteSpeed\Core::kick_purge_queue();
        }
        foreach ( $ids as $id ) {
            if ( get_post_status( $id ) === 'publish' ) {
                do_action( 'dh_warm_url', get_permalink( $id ) );
            }
        }
        $queue['pos'] = (int) $queue['pos'] + count( $ids );
        update_option( DH_Search_Test::QUEUE_OPTION, $queue, false );
        WP_CLI::line( sprintf( 'Purged %d pages, %d of %d done.', count( $ids ), $queue['pos'], count( $queue['ids'] ) ) );
    }

    /**
     * Pages whose version changes, grouped by city so each test city's page and profiles
     * change the same night, with test and control cities interleaved in a fixed random order.
     * Start: test city pages, their non-Featured profiles, and control city pages (the year).
     * Stop: test city pages and their non-Featured profiles.
     *
     * @param string $mode start|stop
     * @return int[]
     */
    private function build_queue( $mode ) {
        $units = array();
        foreach ( $this->published( 'city-listing' ) as $cid ) {
            $slug = DH_Search_Test::area_slug( get_post( $cid ) );
            if ( $slug && ( $mode === 'start' || DH_Search_Test::group_for_area( $slug ) === 'test' ) ) {
                $units[ $slug ][] = $cid;
            }
        }
        foreach ( $this->published( 'profile' ) as $pid ) {
            if ( DH_Search_Test::is_featured( $pid ) ) {
                continue;
            }
            $area = DH_Taxonomy_Helpers::get_primary_area_term( $pid );
            if ( $area && DH_Search_Test::group_for_area( $area->slug ) === 'test' ) {
                $units[ $area->slug ][] = $pid;
            }
        }
        $salt = get_option( DH_Search_Test::OPTION, array() )['salt'];
        uksort( $units, function ( $a, $b ) use ( $salt ) {
            return crc32( 'order|' . $salt . '|' . $a ) <=> crc32( 'order|' . $salt . '|' . $b );
        } );
        return $units ? array_map( 'intval', array_merge( ...array_values( $units ) ) ) : array();
    }

    /**
     * @param string $type
     * @return int[]
     */
    private function published( $type ) {
        return get_posts( array(
            'post_type'      => $type,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ) );
    }

    /**
     * True while a prime-all or ratings apply runs, which the nightly purge must never overlap.
     *
     * @return bool
     */
    private function other_job_running() {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }
        exec( 'pgrep -f "directory-helpers [p]rime-all|directory-helpers [a]pply-ratings|[p]rime-cache.sh"', $out, $rc );
        return $rc === 0;
    }
}
