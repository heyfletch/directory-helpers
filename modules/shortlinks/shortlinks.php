<?php
/**
 * Shortlinks Module (Rank Math Redirections)
 *
 * Creates one-time shortlinks for city-listing and state-listing CPTs
 * and provides a guarded migration to repair malformed Rank Math sources.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DH_Shortlinks {
    const META_CREATED = '_dh_shortlink_created';
    const META_SLUG    = '_dh_shortlink_slug';
    const NONCE_ACTION = 'dh_create_shortlink';
    const NONCE_NAME   = 'dh_shortlinks_nonce';
    const OPT_LOG      = 'dh_shortlinks_log';
    const TRANSIENT_MIGRATION_LOCK = 'dh_rm_sources_migration_lock';

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue' ] );
        add_action( 'wp_ajax_dh_create_shortlink', [ $this, 'ajax_create_shortlink' ] );

        // Auto create on first publish/update to publish if not created yet
        add_action( 'transition_post_status', [ $this, 'maybe_auto_create_on_publish' ], 10, 3 );

        // Guarded migration on generic admin requests
        add_action( 'admin_init', [ $this, 'maybe_migrate_rankmath_sources_shape' ] );
    }

    /* ---------------------------------- */
    /* Admin UI                          */
    /* ---------------------------------- */

    public function add_meta_box() {
        $screens = [ 'city-listing', 'state-listing' ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'dh-shortlinks-box',
                __( 'Shortlink', 'directory-helpers' ),
                [ $this, 'render_meta_box' ],
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box( $post ) {
        $created = (bool) get_post_meta( $post->ID, self::META_CREATED, true );
        $slug    = (string) get_post_meta( $post->ID, self::META_SLUG, true );
        $base    = esc_url( home_url( '/' ) );
        $url     = $slug ? esc_url( $base . ltrim( $slug, '/' ) ) : '';

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

        echo '<div class="dh-shortlinks-meta">';
        if ( $created && $slug ) {
            $display = '/' . ltrim( $slug, '/' );
            echo '<p class="dh-shortlink-row">';
            echo '<a href="' . $url . '" target="_blank" rel="noopener">' . esc_html( $display ) . '</a> ';
            // Using dashicons-admin-page. Alternatives: swap class to dashicons-clipboard or dashicons-share
            echo '<button type="button" class="button-link dh-copy-shortlink" data-url="' . $url . '" aria-label="' . esc_attr__( 'Copy shortlink', 'directory-helpers' ) . '" title="' . esc_attr__( 'Copy shortlink', 'directory-helpers' ) . '"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button>';
            echo '</p>';
        } else {
            echo '<p>' . esc_html__( 'No shortlink created yet.', 'directory-helpers' ) . '</p>';
            echo '<p><button type="button" class="button button-primary" id="dh-create-shortlink" data-post="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Create Shortlink', 'directory-helpers' ) . '</button></p>';
        }

        echo '<div id="dh-shortlink-status" style="margin-top:8px;"></div>';
        echo '</div>';
    }

    public function admin_enqueue( $hook ) {
        // Only on post edit screens
        if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, [ 'city-listing', 'state-listing', 'profile' ], true ) ) {
            return;
        }

        // Ensure Dashicons available for copy icon
        wp_enqueue_style( 'dashicons' );
        // Remove underline from the copy icon button, keep link styling intact
        $inline_css = '.dh-shortlinks-meta .dh-copy-shortlink{ text-decoration:none; box-shadow:none; }
            .dh-shortlinks-meta .dh-copy-shortlink:hover{ text-decoration:none; }
            .dh-shortlinks-meta .dh-copy-shortlink .dashicons{ text-decoration:none; }
            /* Permalink copy icon next to Edit */
            #edit-slug-box .dh-copy-permalink{ text-decoration:none; box-shadow:none; margin-left:6px; vertical-align:middle; }
            #edit-slug-box .dh-copy-permalink:hover{ text-decoration:none; }
            #edit-slug-box .dh-copy-permalink .dashicons{ text-decoration:none; }';
        wp_add_inline_style( 'dashicons', $inline_css );
        wp_enqueue_script(
            'dh-shortlinks-admin',
            plugin_dir_url( __FILE__ ) . 'shortlinks.js',
            [ 'jquery' ],
            filemtime( plugin_dir_path( __FILE__ ) . 'shortlinks.js' ),
            true
        );
        wp_localize_script( 'dh-shortlinks-admin', 'DHShortlinks', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
        ] );
    }

    /* ---------------------------------- */
    /* AJAX                               */
    /* ---------------------------------- */

    public function ajax_create_shortlink() {
        if ( ! isset( $_POST['post_id'], $_POST['nonce'] ) ) {
            wp_send_json_error( [ 'message' => 'Bad request' ], 400 );
        }
        $post_id = (int) $_POST['post_id'];
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE_ACTION ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
        }

        $result = $this->create_shortlink_for_post( $post_id );
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        }
        wp_send_json_error( $result );
    }

    /* ---------------------------------- */
    /* Auto-create on publish             */
    /* ---------------------------------- */

    public function maybe_auto_create_on_publish( $new_status, $old_status, $post ) {
        if ( $new_status !== 'publish' || $old_status === 'publish' ) {
            return;
        }
        if ( ! in_array( $post->post_type, [ 'city-listing', 'state-listing' ], true ) ) {
            return;
        }
        if ( get_post_meta( $post->ID, self::META_CREATED, true ) ) {
            return; // already created
        }
        $this->create_shortlink_for_post( $post->ID );
    }

    /* ---------------------------------- */
    /* Core creation logic                */
    /* ---------------------------------- */

    public function create_shortlink_for_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, [ 'city-listing', 'state-listing' ], true ) ) {
            return [ 'success' => false, 'message' => 'Unsupported post type.' ];
        }

        // If already created, just return existing
        $existing_slug = (string) get_post_meta( $post_id, self::META_SLUG, true );
        if ( $existing_slug ) {
            $dest = get_permalink( $post_id );
            $cur  = $this->redirect_destination_for_source( $existing_slug );
            if ( $this->urls_match( $dest, $cur ) ) {
                return [ 'success' => true, 'status' => 'exists', 'slug' => $existing_slug, 'url' => $this->shortlink_url( $existing_slug ) ];
            }
        }

        $candidates = $this->candidate_slugs_for_post( $post );
        if ( empty( $candidates ) ) {
            return [ 'success' => false, 'message' => 'Unable to determine a short slug.' ];
        }

        $permalink = get_permalink( $post_id );

        // Try candidates, then numeric suffix if necessary
        foreach ( $candidates as $slug ) {
            $check = $this->check_or_create_slug( $slug, $permalink, $post_id );
            if ( $check['success'] ) {
                return $check;
            }
        }
        // Numeric fallback
        $base = $candidates[ count( $candidates ) - 1 ]; // last tried as base for numbering
        for ( $i = 2; $i <= 20; $i++ ) {
            $slug = $base . '-' . $i;
            $check = $this->check_or_create_slug( $slug, $permalink, $post_id );
            if ( $check['success'] ) {
                return $check;
            }
        }

        return [ 'success' => false, 'message' => 'No available slug after disambiguation.' ];
    }

    private function check_or_create_slug( $slug, $permalink, $post_id ) {
        $exists_id = $this->redirect_exists_for_source( $slug );
        if ( $exists_id ) {
            $dest = $this->redirect_destination_for_source( $slug );
            if ( $this->urls_match( $permalink, $dest ) ) {
                // Mark meta and return exists
                update_post_meta( $post_id, self::META_CREATED, 1 );
                update_post_meta( $post_id, self::META_SLUG, $slug );
                return [ 'success' => true, 'status' => 'exists', 'slug' => $slug, 'url' => $this->shortlink_url( $slug ) ];
            }
            return [ 'success' => false, 'message' => 'Slug in use', 'code' => 'in_use' ];
        }

        $inserted = $this->insert_rank_math_redirect( $slug, $permalink );
        if ( $inserted ) {
            update_post_meta( $post_id, self::META_CREATED, 1 );
            update_post_meta( $post_id, self::META_SLUG, $slug );
            return [ 'success' => true, 'status' => 'created', 'slug' => $slug, 'url' => $this->shortlink_url( $slug ) ];
        }
        return [ 'success' => false, 'message' => 'Insert failed' ];
    }

    private function candidate_slugs_for_post( WP_Post $post ) {
        $slugs = [];
        if ( $post->post_type === 'city-listing' ) {
            $city = $post->post_name; // e.g., philadelphia-pa-dog-trainers
            $city_base = '';
            $state_code = '';
            if ( preg_match( '/^(.+)-([a-z]{2})-dog-trainers$/', $city, $m ) ) {
                $city_base = sanitize_title( $m[1] );
                $state_code = sanitize_title( $m[2] );
            } else {
                // Fallback to area term
                $terms = get_the_terms( $post->ID, 'area' );
                if ( is_array( $terms ) && ! empty( $terms ) ) {
                    $city_base = sanitize_title( $terms[0]->name );
                } else {
                    $city_base = sanitize_title( $post->post_title );
                }
                // Try to get state code from title slug if present
                if ( preg_match( '/-([a-z]{2})-dog-trainers$/', $city, $mm ) ) {
                    $state_code = sanitize_title( $mm[1] );
                }
            }
            $city_base = $this->trim_leading_trailing_slash( strtolower( $city_base ) );
            if ( $city_base ) {
                $slugs[] = $city_base; // preferred: philadelphia
                if ( $state_code ) {
                    $slugs[] = $city_base . '-' . strtolower( $state_code ); // disambiguation: philadelphia-pa
                }
            }
        } elseif ( $post->post_type === 'state-listing' ) {
            $terms = get_the_terms( $post->ID, 'state' );
            $state_base = '';
            if ( is_array( $terms ) && ! empty( $terms ) ) {
                // Prefer term description for display names, but for slug use term name/slug
                $state_base = sanitize_title( $terms[0]->name );
            } else {
                $state_base = sanitize_title( $post->post_title );
            }
            $state_base = $this->trim_leading_trailing_slash( strtolower( $state_base ) );
            if ( $state_base ) {
                $slugs[] = $state_base;       // e.g., pennsylvania
                $slugs[] = $state_base . '-state'; // disambiguation
            }
        }
        return array_values( array_unique( array_filter( $slugs ) ) );
    }

    private function shortlink_url( $slug ) {
        return home_url( '/' . ltrim( $slug, '/' ) );
    }

    private function urls_match( $a, $b ) {
        if ( empty( $a ) || empty( $b ) ) { return false; }
        $na = untrailingslashit( esc_url_raw( $a ) );
        $nb = untrailingslashit( esc_url_raw( $b ) );
        return strcasecmp( $na, $nb ) === 0;
    }

    private function trim_leading_trailing_slash( $s ) {
        return trim( $s, "/\t\n\r\0\x0B" );
    }

    /* ---------------------------------- */
    /* Rank Math DB helpers               */
    /* ---------------------------------- */

    private function redirections_table() {
        global $wpdb;
        return $wpdb->prefix . 'rank_math_redirections';
    }

    private function redirections_cache_table() {
        global $wpdb;
        return $wpdb->prefix . 'rank_math_redirections_cache';
    }

    // Check if a DB table exists safely
    private function table_exists( $table ) {
        global $wpdb;
        if ( empty( $table ) ) { return false; }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name internal
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return ( $found === $table );
    }

    public function redirect_exists_for_source( $slug ) {
        global $wpdb;
        $slug = $this->trim_leading_trailing_slash( strtolower( $slug ) );
        $redir_table = $this->redirections_table();
        if ( ! $this->table_exists( $redir_table ) ) {
            return 0;
        }
        // Target the serialized pattern structure to reduce false positives, then verify
        $like = '%"pattern";s:%:"' . $wpdb->esc_like( $slug ) . '";%';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, sources FROM {$redir_table} WHERE sources LIKE %s ORDER BY id DESC LIMIT 200", $like ) );
        if ( $rows ) {
            foreach ( $rows as $r ) {
                $data = @maybe_unserialize( $r->sources );
                if ( is_array( $data ) ) {
                    foreach ( $data as $rule ) {
                        if ( is_array( $rule ) && isset( $rule['pattern'] ) && strtolower( untrailingslashit( ltrim( (string) $rule['pattern'], '/' ) ) ) === $slug ) {
                            return (int) $r->id;
                        }
                    }
                }
            }
        }
        return 0;
    }

    public function redirect_destination_for_source( $slug ) {
        global $wpdb;
        $slug = $this->trim_leading_trailing_slash( strtolower( $slug ) );
        $redir_table = $this->redirections_table();
        if ( ! $this->table_exists( $redir_table ) ) {
            return '';
        }
        $like = '%"pattern";s:%:"' . $wpdb->esc_like( $slug ) . '";%';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, sources, url_to FROM {$redir_table} WHERE sources LIKE %s ORDER BY id DESC LIMIT 200", $like ) );
        if ( $rows ) {
            foreach ( $rows as $r ) {
                $data = @maybe_unserialize( $r->sources );
                if ( is_array( $data ) ) {
                    foreach ( $data as $rule ) {
                        if ( is_array( $rule ) && isset( $rule['pattern'] ) && strtolower( untrailingslashit( ltrim( (string) $rule['pattern'], '/' ) ) ) === $slug ) {
                            return (string) $r->url_to;
                        }
                    }
                }
            }
        }
        return '';
    }

    public function insert_rank_math_redirect( $slug, $destination ) {
        global $wpdb;
        $slug = $this->trim_leading_trailing_slash( strtolower( $slug ) );
        $destination = esc_url_raw( $destination );
        if ( empty( $slug ) || empty( $destination ) ) { return false; }

        $rules = [ [ 'ignore' => '', 'pattern' => $slug, 'comparison' => 'exact' ] ];
        // Rank Math uses serialized PHP array in DB for `sources`
        $sources = serialize( $rules );

        $now = current_time( 'mysql' );
        $table = $this->redirections_table();
        if ( ! $this->table_exists( $table ) ) { return false; }
        $inserted = $wpdb->insert(
            $table,
            [
                'sources'       => $sources,
                'url_to'        => $destination,
                'header_code'   => 301,
                'hits'          => 0,
                'status'        => 'active',
                'created'       => $now,
                'updated'       => $now,
                'last_accessed' => '0000-00-00 00:00:00',
            ],
            [ '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );
        return (bool) $inserted;
    }

    /* ---------------------------------- */
    /* Migration to repair malformed rows */
    /* ---------------------------------- */

    public function maybe_migrate_rankmath_sources_shape() {
        if ( $this->is_rankmath_admin_screen() ) { return; }
        // Cheap lock to avoid concurrent runs
        if ( get_transient( self::TRANSIENT_MIGRATION_LOCK ) ) { return; }
        set_transient( self::TRANSIENT_MIGRATION_LOCK, 1, 30 );

        global $wpdb;
        $table = $this->redirections_table();

        // Fetch a small batch of suspect rows: non-serialized JSON looking or plain strings
        if ( ! $this->table_exists( $table ) ) { delete_transient( self::TRANSIENT_MIGRATION_LOCK ); return; }
        $suspects = $wpdb->get_results( "SELECT id, sources FROM {$table} WHERE (sources LIKE '[%' OR sources LIKE '{%' OR sources REGEXP '^[^aOs0-9]') ORDER BY id DESC LIMIT 150" );
        if ( empty( $suspects ) ) {
            delete_transient( self::TRANSIENT_MIGRATION_LOCK );
            return;
        }

        $fixed_ids = [];
        foreach ( $suspects as $row ) {
            $normalized = $this->normalize_sources_value( $row->id, $row->sources );
            if ( $normalized ) {
                $wpdb->update( $table, [ 'sources' => $normalized, 'updated' => current_time( 'mysql' ) ], [ 'id' => (int) $row->id ], [ '%s', '%s' ], [ '%d' ] );
                $fixed_ids[] = (int) $row->id;
            }
        }

        if ( ! empty( $fixed_ids ) ) {
            $this->log( 'Fixed sources for IDs: ' . implode( ',', $fixed_ids ) );
            // Let Rank Math rebuild cache as needed; we avoid truncating automatically.
        }

        delete_transient( self::TRANSIENT_MIGRATION_LOCK );
    }

    private function normalize_sources_value( $id, $raw ) {
        // 1) Try to unserialize safely
        $data = @maybe_unserialize( $raw );
        if ( is_array( $data ) ) {
            $patterns = $this->extract_patterns_from_mixed( $data );
            if ( $patterns ) { return $this->serialize_patterns( $patterns ); }
        }
        // 2) Try JSON
        $json = json_decode( $raw, true );
        if ( $json !== null ) {
            $patterns = $this->extract_patterns_from_mixed( $json );
            if ( $patterns ) { return $this->serialize_patterns( $patterns ); }
        }
        // 3) If it looks like escaped serialized (e.g., with quotes), try stripslashes
        if ( is_string( $raw ) && ( strpos( $raw, 'a:' ) !== false || strpos( $raw, 's:' ) !== false ) ) {
            $try = @maybe_unserialize( stripslashes( $raw ) );
            if ( is_array( $try ) ) {
                $patterns = $this->extract_patterns_from_mixed( $try );
                if ( $patterns ) { return $this->serialize_patterns( $patterns ); }
            }
        }
        // 4) Single string fallback
        if ( is_string( $raw ) ) {
            $str = trim( $raw, " \t\n\r\0\x0B\"'" );
            // If it includes commas or whitespace, split
            $candidates = preg_split( '/[\n,]+/', $str );
            $candidates = array_map( 'trim', (array) $candidates );
            $candidates = array_filter( $candidates );
            if ( ! empty( $candidates ) ) {
                $patterns = array_map( function ( $v ) { return $this->sanitize_source_pattern( $v ); }, $candidates );
                $patterns = array_filter( array_unique( $patterns ) );
                if ( $patterns ) { return $this->serialize_patterns( $patterns ); }
            }
        }
        // 5) Try cache table to reconstruct
        $cache_patterns = $this->patterns_from_cache_for_id( (int) $id );
        if ( $cache_patterns ) {
            return $this->serialize_patterns( $cache_patterns );
        }
        return '';
    }

    private function extract_patterns_from_mixed( $mixed ) {
        $patterns = [];
        if ( is_array( $mixed ) ) {
            foreach ( $mixed as $item ) {
                if ( is_array( $item ) ) {
                    // Rank Math expected array: [ 'ignore' => '', 'pattern' => 'slug', 'comparison' => 'exact' ]
                    if ( isset( $item['pattern'] ) && is_string( $item['pattern'] ) ) {
                        $patterns[] = $this->sanitize_source_pattern( $item['pattern'] );
                        continue;
                    }
                    // Sometimes nested under pattern array
                    if ( isset( $item['pattern'] ) && is_array( $item['pattern'] ) ) {
                        foreach ( $item['pattern'] as $p ) {
                            if ( is_string( $p ) ) { $patterns[] = $this->sanitize_source_pattern( $p ); }
                            elseif ( is_array( $p ) && isset( $p['pattern'] ) && is_string( $p['pattern'] ) ) {
                                $patterns[] = $this->sanitize_source_pattern( $p['pattern'] );
                            }
                        }
                        continue;
                    }
                    // Object-like arrays
                    foreach ( $item as $maybe ) {
                        if ( is_string( $maybe ) ) {
                            $patterns[] = $this->sanitize_source_pattern( $maybe );
                        } elseif ( is_array( $maybe ) && isset( $maybe['pattern'] ) && is_string( $maybe['pattern'] ) ) {
                            $patterns[] = $this->sanitize_source_pattern( $maybe['pattern'] );
                        }
                    }
                } elseif ( is_string( $item ) ) {
                    $patterns[] = $this->sanitize_source_pattern( $item );
                }
            }
        } elseif ( is_string( $mixed ) ) {
            $patterns[] = $this->sanitize_source_pattern( $mixed );
        }
        $patterns = array_values( array_unique( array_filter( $patterns ) ) );
        return $patterns;
    }

    private function sanitize_source_pattern( $v ) {
        $v = strtolower( wp_strip_all_tags( (string) $v ) );
        $v = ltrim( $v );
        // Remove leading site URL or leading slash
        $home = set_url_scheme( home_url( '/' ), 'https' );
        if ( strpos( $v, $home ) === 0 ) {
            $v = substr( $v, strlen( $home ) );
        }
        $v = ltrim( $v, '/' );
        // Remove trailing slash
        $v = untrailingslashit( $v );
        $v = rawurldecode( $v );
        // Keep only url-safe path characters: a-z 0-9 / _ -
        $v = preg_replace( '/[^a-z0-9\/_-]+/i', '-', $v );
        // Collapse multiple dashes
        $v = preg_replace( '/-+/', '-', $v );
        $v = trim( $v, '-' );
        return strtolower( $v );
    }

    private function serialize_patterns( array $patterns ) {
        $patterns = array_values( array_unique( array_filter( $patterns ) ) );
        if ( empty( $patterns ) ) { return ''; }
        $rules = [];
        foreach ( $patterns as $p ) {
            $rules[] = [ 'ignore' => '', 'pattern' => $p, 'comparison' => 'exact' ];
        }
        return serialize( $rules );
    }

    private function patterns_from_cache_for_id( $redirection_id ) {
        global $wpdb;
        if ( ! $redirection_id ) { return []; }
        $cache_table = $this->redirections_cache_table();
        if ( ! $this->table_exists( $cache_table ) ) { return []; }
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT from_url FROM {$cache_table} WHERE redirection_id = %d", $redirection_id ) );
        if ( empty( $rows ) ) { return []; }
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = $this->sanitize_source_pattern( $r );
        }
        return array_values( array_unique( array_filter( $out ) ) );
    }

    private function is_rankmath_admin_screen() {
        if ( ! is_admin() ) { return false; }
        $page = isset( $_GET['page'] ) ? (string) $_GET['page'] : '';
        if ( $page && ( stripos( $page, 'rank-math' ) !== false ) && ( stripos( $page, 'redirect' ) !== false || stripos( $page, 'redirection' ) !== false ) ) {
            return true;
        }
        return false;
    }

    private function log( $msg ) {
        $enabled = apply_filters( 'dh_shortlinks_logging_enabled', true );
        if ( $enabled ) {
            $existing = get_option( self::OPT_LOG, [] );
            if ( ! is_array( $existing ) ) { $existing = []; }
            $existing[] = '[' . current_time( 'mysql' ) . '] ' . $msg;
            update_option( self::OPT_LOG, $existing, false );
        }
    }
}

// Admin JS (small inline file served via enqueue)
// Created as a physical file next to this PHP for cache busting.
