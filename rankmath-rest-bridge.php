<?php
/**
 * Plugin Name:  RankMath REST Bridge
 * Description:  REST endpoints for the SEO Remediation Agent: RankMath title/meta updates, head/footer script injection (schema, analytics tags, etc.), and cache purge. No HFCM dependency required.
 * Version:      1.2.0
 * Author:       Rank Rocket Co.
 * Author URI:   https://rankrocket.co
 * Requires PHP: 7.4
 * Requires WP:  5.9
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RMB_VERSION',      '1.2.0' );
define( 'RMB_PLUGIN_FILE',  __FILE__ );
define( 'RMB_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'RMB_SNIPPETS_KEY', 'rmb_managed_snippets' );
define( 'RMB_UPDATE_URL',   'https://raw.githubusercontent.com/rankrocket-co/rankmath-rest-bridge/main/update-manifest.json' );

// ── Auto-update via plugin-update-checker ─────────────────────────────────────
add_action( 'init', function () {
    $puc_loader = RMB_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if ( file_exists( $puc_loader ) ) {
        require_once $puc_loader;
        $checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            RMB_UPDATE_URL,
            RMB_PLUGIN_FILE,
            'rankmath-rest-bridge'
        );
        // Optional: set branch to track (default is main)
        // $checker->setBranch('main');
    }
} );

// ── Output managed snippets ───────────────────────────────────────────────────
add_action( 'wp_head',   function () { rmb_output_snippets( 'head' ); },   5  );
add_action( 'wp_footer', function () { rmb_output_snippets( 'footer' ); }, 99 );

function rmb_output_snippets( $location ) {
    $snippets = get_option( RMB_SNIPPETS_KEY, [] );
    if ( empty( $snippets ) ) return;

    foreach ( $snippets as $snippet ) {
        if ( ( $snippet['status']   ?? 'active' ) !== 'active'   ) continue;
        if ( ( $snippet['location'] ?? 'footer' ) !== $location   ) continue;

        $display_on = $snippet['display_on'] ?? 'entire_website';
        $should_output = false;

        switch ( $display_on ) {
            case 'entire_website':
                $should_output = true;
                break;
            case 'front_page':
                $should_output = is_front_page();
                break;
            case 'all_pages':
                $should_output = is_page();
                break;
            case 'all_posts':
                $should_output = is_single();
                break;
            default:
                // page_id:1013 or just a numeric string
                if ( str_starts_with( $display_on, 'page_id:' ) ) {
                    $page_id = intval( substr( $display_on, 8 ) );
                    $should_output = ( get_queried_object_id() === $page_id );
                } elseif ( is_numeric( $display_on ) ) {
                    $should_output = ( get_queried_object_id() === intval( $display_on ) );
                }
        }

        if ( $should_output ) {
            echo "\n" . $snippet['content'] . "\n";
        }
    }
}

// ── REST API ──────────────────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {

    $admin_only = function () { return current_user_can( 'manage_options' ); };

    // ── RankMath ──────────────────────────────────────────────────────────────
    register_rest_route( 'rankmath-bridge/v1', '/update', [
        'methods'             => 'POST',
        'callback'            => 'rmb_update_meta',
        'permission_callback' => $admin_only,
        'args' => [
            'post_id'        => [ 'required' => true,  'type' => 'integer' ],
            'title'          => [ 'required' => false, 'type' => 'string'  ],
            'description'    => [ 'required' => false, 'type' => 'string'  ],
            'focus_keyword'  => [ 'required' => false, 'type' => 'string'  ],
            'robots'         => [ 'required' => false, 'type' => 'string'  ],
            'og_title'       => [ 'required' => false, 'type' => 'string'  ],
            'og_description' => [ 'required' => false, 'type' => 'string'  ],
        ],
    ] );

    register_rest_route( 'rankmath-bridge/v1', '/get/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'rmb_get_meta',
        'permission_callback' => $admin_only,
    ] );

    // ── Snippets ──────────────────────────────────────────────────────────────
    register_rest_route( 'rankmath-bridge/v1', '/snippets', [
        [
            'methods'             => 'GET',
            'callback'            => 'rmb_snippets_list',
            'permission_callback' => $admin_only,
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rmb_snippets_create',
            'permission_callback' => $admin_only,
            'args' => [
                'title'      => [ 'required' => true,  'type' => 'string' ],
                'content'    => [ 'required' => true,  'type' => 'string' ],
                'location'   => [ 'required' => false, 'type' => 'string', 'default' => 'footer'          ],
                'display_on' => [ 'required' => false, 'type' => 'string', 'default' => 'entire_website'  ],
                'status'     => [ 'required' => false, 'type' => 'string', 'default' => 'active'          ],
            ],
        ],
    ] );

    register_rest_route( 'rankmath-bridge/v1', '/snippets/(?P<id>[a-zA-Z0-9_-]+)', [
        [
            'methods'             => 'POST',
            'callback'            => 'rmb_snippets_update',
            'permission_callback' => $admin_only,
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => 'rmb_snippets_delete',
            'permission_callback' => $admin_only,
        ],
    ] );

    // ── Cache ─────────────────────────────────────────────────────────────────
    register_rest_route( 'rankmath-bridge/v1', '/cache/purge', [
        'methods'             => 'POST',
        'callback'            => 'rmb_cache_purge',
        'permission_callback' => $admin_only,
    ] );

    // ── Status ────────────────────────────────────────────────────────────────
    register_rest_route( 'rankmath-bridge/v1', '/status', [
        'methods'             => 'GET',
        'callback'            => 'rmb_status',
        'permission_callback' => $admin_only,
    ] );

} );


// ── RankMath Handlers ─────────────────────────────────────────────────────────

function rmb_update_meta( WP_REST_Request $request ) {
    $post_id = intval( $request->get_param( 'post_id' ) );
    if ( ! get_post( $post_id ) ) {
        return new WP_Error( 'invalid_post', 'Post not found', [ 'status' => 404 ] );
    }

    $updated = [];
    $fields  = [
        'title'          => 'rank_math_title',
        'description'    => 'rank_math_description',
        'focus_keyword'  => 'rank_math_focus_keyword',
        'robots'         => 'rank_math_robots',
        'og_title'       => 'rank_math_og_title',
        'og_description' => 'rank_math_og_description',
    ];

    foreach ( $fields as $param => $meta_key ) {
        $value = $request->get_param( $param );
        if ( $value !== null && $value !== '' ) {
            update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
            $updated[ $meta_key ] = $value;
        }
    }

    wp_cache_delete( $post_id, 'post_meta' );
    clean_post_cache( $post_id );

    return rest_ensure_response( [ 'success' => true, 'post_id' => $post_id, 'updated' => $updated ] );
}

function rmb_get_meta( WP_REST_Request $request ) {
    $post_id = intval( $request->get_param( 'id' ) );
    if ( ! get_post( $post_id ) ) {
        return new WP_Error( 'invalid_post', 'Post not found', [ 'status' => 404 ] );
    }

    $meta_keys = [
        'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
        'rank_math_robots', 'rank_math_og_title', 'rank_math_og_description',
        'rank_math_seo_score',
    ];

    $meta = [];
    foreach ( $meta_keys as $key ) {
        $meta[ $key ] = get_post_meta( $post_id, $key, true );
    }

    return rest_ensure_response( [ 'post_id' => $post_id, 'meta' => $meta ] );
}


// ── Snippet Handlers ──────────────────────────────────────────────────────────

function rmb_snippets_list( WP_REST_Request $request ) {
    $snippets = get_option( RMB_SNIPPETS_KEY, [] );
    return rest_ensure_response( [ 'count' => count( $snippets ), 'snippets' => array_values( $snippets ) ] );
}

function rmb_snippets_create( WP_REST_Request $request ) {
    $snippets = get_option( RMB_SNIPPETS_KEY, [] );

    $title = sanitize_text_field( $request->get_param( 'title' ) );
    $id    = sanitize_title( $title );

    // Prevent duplicate IDs
    if ( isset( $snippets[ $id ] ) ) {
        $id = $id . '_' . time();
    }

    $snippet = [
        'id'         => $id,
        'title'      => $title,
        'content'    => $request->get_param( 'content' ),   // raw — allows <script> tags
        'location'   => sanitize_text_field( $request->get_param( 'location'   ) ),
        'display_on' => sanitize_text_field( $request->get_param( 'display_on' ) ),
        'status'     => sanitize_text_field( $request->get_param( 'status'     ) ),
        'created_at' => current_time( 'mysql' ),
        'updated_at' => current_time( 'mysql' ),
    ];

    $snippets[ $id ] = $snippet;
    update_option( RMB_SNIPPETS_KEY, $snippets );

    return rest_ensure_response( [ 'success' => true, 'snippet' => $snippet ] );
}

function rmb_snippets_update( WP_REST_Request $request ) {
    $snippets = get_option( RMB_SNIPPETS_KEY, [] );
    $id       = $request->get_param( 'id' );

    if ( ! isset( $snippets[ $id ] ) ) {
        return new WP_Error( 'not_found', "Snippet '{$id}' not found", [ 'status' => 404 ] );
    }

    foreach ( [ 'title', 'location', 'display_on', 'status' ] as $field ) {
        $val = $request->get_param( $field );
        if ( $val !== null ) $snippets[ $id ][ $field ] = sanitize_text_field( $val );
    }

    // content is raw (allows script tags)
    $content = $request->get_param( 'content' );
    if ( $content !== null ) $snippets[ $id ]['content'] = $content;

    $snippets[ $id ]['updated_at'] = current_time( 'mysql' );

    update_option( RMB_SNIPPETS_KEY, $snippets );
    return rest_ensure_response( [ 'success' => true, 'snippet' => $snippets[ $id ] ] );
}

function rmb_snippets_delete( WP_REST_Request $request ) {
    $snippets = get_option( RMB_SNIPPETS_KEY, [] );
    $id       = $request->get_param( 'id' );

    if ( ! isset( $snippets[ $id ] ) ) {
        return new WP_Error( 'not_found', "Snippet '{$id}' not found", [ 'status' => 404 ] );
    }

    $deleted = $snippets[ $id ];
    unset( $snippets[ $id ] );
    update_option( RMB_SNIPPETS_KEY, $snippets );

    return rest_ensure_response( [ 'success' => true, 'deleted_id' => $id, 'deleted' => $deleted ] );
}


// ── Cache Purge ───────────────────────────────────────────────────────────────

function rmb_cache_purge( WP_REST_Request $request ) {
    $purged = [];

    if ( class_exists( '\LiteSpeed\Purge' ) ) {
        do_action( 'litespeed_purge_all' );
        $purged[] = 'LiteSpeed';
    }
    if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
        sg_cachepress_purge_cache();
        $purged[] = 'SiteGround';
    }
    if ( function_exists( 'rocket_clean_domain' ) ) {
        rocket_clean_domain();
        $purged[] = 'WP Rocket';
    }
    if ( function_exists( 'w3tc_flush_all' ) ) {
        w3tc_flush_all();
        $purged[] = 'W3TC';
    }

    return rest_ensure_response( [
        'success' => true,
        'purged'  => $purged,
        'message' => empty( $purged ) ? 'No supported cache plugin detected' : 'Purged: ' . implode( ', ', $purged ),
    ] );
}


// ── Status ────────────────────────────────────────────────────────────────────

function rmb_status( WP_REST_Request $request ) {
    $snippets = get_option( RMB_SNIPPETS_KEY, [] );
    return rest_ensure_response( [
        'plugin'         => 'RankMath REST Bridge',
        'version'        => RMB_VERSION,
        'snippet_count'  => count( $snippets ),
        'snippet_ids'    => array_keys( $snippets ),
        'update_url'     => RMB_UPDATE_URL,
        'php_version'    => PHP_VERSION,
        'wp_version'     => get_bloginfo( 'version' ),
    ] );
}
