<?php
/**
 * WP-CLI command to regenerate Bricks code signatures after DB sync
 * 
 * Automates the process of re-signing Bricks PHP code (query loops, code elements)
 * which is required after syncing databases between environments with different salts.
 */

if ( ! defined( 'WP_CLI' ) ) {
    return;
}

/**
 * Regenerate Bricks code signatures for staging/production sync
 */
class DH_Bricks_Resign_Command extends WP_CLI_Command {

    /**
     * Regenerate all Bricks code signatures
     *
     * This command regenerates code signatures for:
     * - All Bricks templates (query loops, code elements)
     * - Global queries stored in Bricks Query Manager
     *
     * Run this after syncing database from production to staging.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be done without making changes
     *
     * ## EXAMPLES
     *
     *     wp dh bricks-resign
     *     wp dh bricks-resign --dry-run
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ) {
        $dry_run = isset( $assoc_args['dry-run'] );

        WP_CLI::line( "=== Bricks Code Signature Regeneration ===" );
        WP_CLI::line( "Dry run: " . ( $dry_run ? 'Yes' : 'No' ) );
        WP_CLI::line( "" );

        // Check if Bricks is active
        if ( ! defined( 'BRICKS_VERSION' ) ) {
            WP_CLI::error( "Bricks Builder is not active." );
            return;
        }

        WP_CLI::line( "Bricks version: " . BRICKS_VERSION );
        WP_CLI::line( "" );

        $total_signed = 0;

        // Method 1: Use Bricks internal method for templates/pages (preferred)
        if ( class_exists( '\Bricks\Admin' ) && method_exists( '\Bricks\Admin', 'crawl_and_update_code_signatures' ) ) {
            WP_CLI::line( "Using Bricks internal regeneration method..." );
            
            if ( ! $dry_run ) {
                $success = \Bricks\Admin::crawl_and_update_code_signatures();
                if ( $success ) {
                    WP_CLI::success( "Code signatures regenerated via Bricks internal method." );
                    $total_signed++;
                } else {
                    WP_CLI::warning( "Bricks internal method returned false." );
                }
            } else {
                WP_CLI::line( "[DRY RUN] Would call Bricks\\Admin::crawl_and_update_code_signatures()" );
                $total_signed++;
            }
        } else {
            // Fallback: Manual regeneration
            WP_CLI::line( "Falling back to manual signature regeneration..." );
            
            $templates_signed = $this->regenerate_template_signatures( $dry_run );
            $total_signed += $templates_signed;
        }

        // Method 2: Always handle Global Queries (Bricks internal method doesn't cover these)
        $queries_signed = $this->regenerate_global_query_signatures( $dry_run );
        $total_signed += $queries_signed;

        WP_CLI::line( "" );
        
        if ( $dry_run ) {
            WP_CLI::success( "Dry run complete. Would re-sign {$total_signed} items." );
        } else {
            WP_CLI::success( "Code signature regeneration complete! Re-signed {$total_signed} items." );
        }

        WP_CLI::line( "" );
        WP_CLI::line( "Note: You may also need to clear any page caches after this." );
    }

    /**
     * Regenerate signatures for all Bricks templates and pages
     */
    private function regenerate_template_signatures( $dry_run ) {
        WP_CLI::line( "--- Processing Bricks Templates & Pages ---" );
        
        global $wpdb;
        
        // Find all posts with Bricks content
        $meta_keys = array( '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' );
        $signed_count = 0;
        
        foreach ( $meta_keys as $meta_key ) {
            $posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
                $meta_key
            ) );
            
            if ( empty( $posts ) ) {
                continue;
            }
            
            WP_CLI::line( "Found " . count( $posts ) . " posts with {$meta_key}" );
            
            foreach ( $posts as $post ) {
                $elements = maybe_unserialize( $post->meta_value );
                
                if ( ! is_array( $elements ) ) {
                    continue;
                }
                
                $modified = false;
                $elements = $this->sign_elements_recursive( $elements, $modified );
                
                if ( $modified ) {
                    $post_title = get_the_title( $post->post_id );
                    WP_CLI::line( "  Re-signing: {$post_title} (ID: {$post->post_id})" );
                    
                    if ( ! $dry_run ) {
                        update_post_meta( $post->post_id, $meta_key, $elements );
                    }
                    
                    $signed_count++;
                }
            }
        }
        
        WP_CLI::line( "Templates/pages processed: {$signed_count}" );
        return $signed_count;
    }

    /**
     * Recursively sign elements that contain executable code
     */
    private function sign_elements_recursive( $elements, &$modified ) {
        if ( ! is_array( $elements ) ) {
            return $elements;
        }
        
        foreach ( $elements as &$element ) {
            // Check for code that needs signing
            $code_fields = array(
                'code',           // Code element
                'queryEditor',    // Query loop PHP editor
            );
            
            $settings = isset( $element['settings'] ) ? $element['settings'] : array();
            
            foreach ( $code_fields as $field ) {
                if ( ! empty( $settings[ $field ] ) ) {
                    $code = $settings[ $field ];
                    $new_signature = $this->generate_signature( $code );
                    
                    $signature_field = $field . 'Signature';
                    $old_signature = isset( $settings[ $signature_field ] ) ? $settings[ $signature_field ] : '';
                    
                    if ( $old_signature !== $new_signature ) {
                        $element['settings'][ $signature_field ] = $new_signature;
                        $modified = true;
                    }
                }
            }
            
            // Handle nested elements (children)
            if ( ! empty( $element['children'] ) && is_array( $element['children'] ) ) {
                $element['children'] = $this->sign_elements_recursive( $element['children'], $modified );
            }
        }
        
        return $elements;
    }

    /**
     * Regenerate signatures for Global Queries
     */
    private function regenerate_global_query_signatures( $dry_run ) {
        WP_CLI::line( "" );
        WP_CLI::line( "--- Processing Global Queries ---" );
        
        // Global queries are stored in options
        $global_queries = get_option( 'bricks_global_queries', array() );
        
        if ( empty( $global_queries ) || ! is_array( $global_queries ) ) {
            WP_CLI::line( "No global queries found." );
            return 0;
        }
        
        WP_CLI::line( "Found " . count( $global_queries ) . " global queries" );
        
        $signed_count = 0;
        $modified = false;
        
        foreach ( $global_queries as $id => &$query ) {
            // Check if query has PHP code
            if ( ! empty( $query['settings']['queryEditor'] ) ) {
                $code = $query['settings']['queryEditor'];
                $new_signature = $this->generate_signature( $code );
                
                $old_signature = isset( $query['settings']['signature'] ) ? $query['settings']['signature'] : '';
                
                if ( $old_signature !== $new_signature ) {
                    $query_name = isset( $query['name'] ) ? $query['name'] : $id;
                    WP_CLI::line( "  Re-signing global query: {$query_name}" );
                    
                    $query['settings']['signature'] = $new_signature;
                    $modified = true;
                    $signed_count++;
                }
            }
        }
        
        if ( $modified && ! $dry_run ) {
            update_option( 'bricks_global_queries', $global_queries );
        }
        
        WP_CLI::line( "Global queries re-signed: {$signed_count}" );
        return $signed_count;
    }

    /**
     * Generate a code signature using WordPress hash
     * This mimics how Bricks generates signatures internally
     */
    private function generate_signature( $code ) {
        // Bricks uses wp_hash() which uses HMAC with the site's auth salt
        return wp_hash( $code );
    }
}

WP_CLI::add_command( 'dh bricks-resign', 'DH_Bricks_Resign_Command' );
