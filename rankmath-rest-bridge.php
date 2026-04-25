<?php
/**
 * Plugin Name:  RankRocket SEO
 * Description:  Full-stack SEO management plugin for the RankRocket remediation pipeline. Handles title/meta, schema injection, image ALT text, llms.txt, XML sitemap, cache purge, and self-updates. RankMath not required.
 * Version:      2.1.1
 * Author:       Rank Rocket Co.
 * Author URI:   https://rankrocket.co
 * Requires PHP: 7.4
 * Requires WP:  5.9
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RMB_VERSION',      '2.1.1' );
define( 'RMB_PLUGIN_FILE',  __FILE__ );
define( 'RMB_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'RMB_SNIPPETS_KEY', 'rmb_managed_snippets' );
define( 'RMB_UPDATE_URL',   'https://raw.githubusercontent.com/PenZenMaster/rankmath-rest-bridge/main/update-manifest.json' );

// ── Auto-update via plugin-update-checker ─────────────────────────────────────
add_action( 'init', function () {
    // Plugin update checker
    $puc_loader = RMB_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if ( file_exists( $puc_loader ) ) {
        require_once $puc_loader;
        $checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            RMB_UPDATE_URL,
            RMB_PLUGIN_FILE,
            'rankmath-rest-bridge'
        );
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

        $display_on    = $snippet['display_on'] ?? 'entire_website';
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
                if ( str_starts_with( $display_on, 'page_id:' ) ) {
                    $page_id       = intval( substr( $display_on, 8 ) );
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

// ── SEO meta output (when RankMath is inactive) ───────────────────────────────
add_action( 'wp_head', function () {
    if ( class_exists( 'RankMath' ) ) return; // RankMath handles it

    if ( ! is_singular() ) return;
    $post_id = get_queried_object_id();

    $title = get_post_meta( $post_id, 'rank_math_title', true );
    $desc  = get_post_meta( $post_id, 'rank_math_description', true );
    $robots = get_post_meta( $post_id, 'rank_math_robots', true );

    // Resolve tokens (%sitename%, %title%, %sep%)
    $title = rmb_resolve_tokens( $title, $post_id );
    $desc  = rmb_resolve_tokens( $desc,  $post_id );

    if ( $title ) {
        // Replace WP's default <title> tag
        add_filter( 'pre_get_document_title', function() use ( $title ) { return $title; } );
    }
    if ( $desc ) {
        echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
    }
    if ( $robots && ! empty( $robots ) ) {
        $robots_val = is_array( $robots ) ? implode( ',', $robots ) : $robots;
        if ( $robots_val ) {
            echo '<meta name="robots" content="' . esc_attr( $robots_val ) . '">' . "\n";
        }
    }

    // og:title / og:description
    $og_title = get_post_meta( $post_id, 'rank_math_og_title', true );
    $og_desc  = get_post_meta( $post_id, 'rank_math_og_description', true );
    if ( $og_title ) echo '<meta property="og:title" content="'       . esc_attr( rmb_resolve_tokens( $og_title, $post_id ) ) . '">' . "\n";
    if ( $og_desc  ) echo '<meta property="og:description" content="' . esc_attr( rmb_resolve_tokens( $og_desc,  $post_id ) ) . '">' . "\n";

}, 1 ); // priority 1 — before most plugins


// ── Token resolver ────────────────────────────────────────────────────────────
function rmb_resolve_tokens( $str, $post_id ) {
    if ( ! $str ) return $str;
    $post   = get_post( $post_id );
    $tokens = [
        '%title%'    => $post ? get_the_title( $post ) : '',
        '%sitename%' => get_bloginfo( 'name' ),
        '%sep%'      => '|',
        '%excerpt%'  => $post ? wp_trim_words( $post->post_excerpt ?: $post->post_content, 20 ) : '',
    ];
    return str_replace( array_keys( $tokens ), array_values( $tokens ), $str );
}


// ── llms.txt generator ────────────────────────────────────────────────────────
// Intercept /llms.txt at init — earliest hook where WPDB + options are available.
// Handles both /llms.txt and /llms.txt/ (trailing slash variant).
add_action( 'init', function () {
    if ( ! isset( $_SERVER['REQUEST_URI'] ) ) return;
    // Only fire on non-admin, non-REST requests
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;
    $uri = strtok( $_SERVER['REQUEST_URI'], '?' );
    $uri = rtrim( $uri, '/' );
    if ( $uri === '/llms.txt' ) {
        rmb_serve_llms_txt();
        exit;
    }
}, 1 ); // priority 1 — before most plugins, before canonical redirect fires

function rmb_serve_llms_txt() {
    // Minimal diagnostic: confirm hook fires and headers can be sent
    while ( ob_get_level() ) ob_end_clean();
    if ( headers_sent( $hf, $hl ) ) {
        // Headers already sent — cannot serve plain text properly
        status_header( 200 );
        echo "# llms.txt\n# Note: headers already sent from {$hf}:{$hl}\n";
        return;
    }
    status_header( 200 );
    header( 'Content-Type: text/plain; charset=UTF-8' );
    header( 'Cache-Control: no-cache' );

    $site_name = get_bloginfo( 'name' );
    $tagline   = get_bloginfo( 'description' );
    $stored    = get_option( 'rmb_llms_config', [] );

    $lines   = [];
    $lines[] = "# {$site_name}";
    if ( $tagline ) $lines[] = "> {$tagline}";
    $lines[] = '';

    if ( ! empty( $stored['intro'] ) ) {
        $lines[] = $stored['intro'];
        $lines[] = '';
    }

    $pages = get_pages( [ 'post_status' => 'publish', 'sort_column' => 'menu_order' ] );
    if ( $pages ) {
        $lines[] = '## Pages';
        foreach ( $pages as $page ) {
            $noindex = get_post_meta( $page->ID, 'rank_math_robots', true );
            if ( $noindex && ( ( is_array( $noindex ) && in_array( 'noindex', $noindex ) ) || strpos( (string) $noindex, 'noindex' ) !== false ) ) continue;
            $desc    = get_post_meta( $page->ID, 'rank_math_description', true );
            $desc    = $desc ? rmb_resolve_tokens( (string) $desc, $page->ID ) : '';
            $lines[] = '- [' . get_the_title( $page ) . '](' . get_permalink( $page->ID ) . ')' . ( $desc ? ': ' . $desc : '' );
        }
        $lines[] = '';
    }

    $posts = get_posts( [ 'numberposts' => 10, 'post_status' => 'publish' ] );
    if ( $posts ) {
        $lines[] = '## Blog Posts';
        foreach ( $posts as $post ) {
            $noindex = get_post_meta( $post->ID, 'rank_math_robots', true );
            if ( $noindex && ( ( is_array( $noindex ) && in_array( 'noindex', $noindex ) ) || strpos( (string) $noindex, 'noindex' ) !== false ) ) continue;
            $desc    = get_post_meta( $post->ID, 'rank_math_description', true );
            $desc    = $desc ? rmb_resolve_tokens( (string) $desc, $post->ID ) : wp_trim_words( $post->post_excerpt ?: $post->post_content, 20 );
            $lines[] = '- [' . get_the_title( $post ) . '](' . get_permalink( $post->ID ) . ')' . ( $desc ? ': ' . $desc : '' );
        }
        $lines[] = '';
    }

    if ( ! empty( $stored['sections'] ) && is_array( $stored['sections'] ) ) {
        foreach ( $stored['sections'] as $section ) {
            $lines[] = '## ' . ( $section['heading'] ?? 'More' );
            foreach ( ( $section['items'] ?? [] ) as $item ) {
                $lines[] = '- ' . $item;
            }
            $lines[] = '';
        }
    }

    echo implode( "\n", $lines );
}


// ── XML Sitemap ───────────────────────────────────────────────────────────────
add_action( 'wp', function () {
    if ( ! isset( $_SERVER['REQUEST_URI'] ) ) return;
    $uri = strtok( $_SERVER['REQUEST_URI'], '?' );
    if ( rtrim( $uri, '/' ) === '/rmb-sitemap.xml' || rtrim( $uri, '/' ) === '/sitemap.xml' ) {
        rmb_serve_sitemap();
        exit;
    }
    if ( rtrim( $uri, '/' ) === '/rmb-sitemap-index.xml' || rtrim( $uri, '/' ) === '/sitemap_index.xml' ) {
        rmb_serve_sitemap_index();
        exit;
    }
} );

function rmb_serve_sitemap_index() {
    while ( ob_get_level() ) ob_end_clean();
    status_header( 200 );
    $site_url = rtrim( get_bloginfo( 'url' ), '/' );
    $now      = gmdate( 'Y-m-d\TH:i:s+00:00' );

    header( 'Content-Type: application/xml; charset=UTF-8' );
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo "  <sitemap><loc>{$site_url}/rmb-sitemap.xml</loc><lastmod>{$now}</lastmod></sitemap>\n";
    echo '</sitemapindex>' . "\n";
    echo "<!-- XML Sitemap generated by RankRocket SEO v" . RMB_VERSION . " -->\n";
}

function rmb_serve_sitemap() {
    while ( ob_get_level() ) ob_end_clean();
    status_header( 200 );
    $entries = [];

    // Pages
    $front_page = (int) get_option( 'page_on_front' );
    $pages = get_pages( [ 'post_status' => 'publish' ] );
    foreach ( $pages as $page ) {
        $noindex = get_post_meta( $page->ID, 'rank_math_robots', true );
        if ( ! empty( $noindex ) && (
            ( is_array( $noindex ) && in_array( 'noindex', $noindex ) ) ||
            ( is_string( $noindex ) && strpos( $noindex, 'noindex' ) !== false )
        ) ) continue;
        $entries[] = [
            'loc'     => get_permalink( $page->ID ),
            'lastmod' => mysql2date( 'Y-m-d\TH:i:s+00:00', $page->post_modified ),
            'pri'     => ( $page->ID === $front_page ) ? '1.0' : '0.8',
        ];
    }

    // Posts
    $posts = get_posts( [ 'numberposts' => -1, 'post_status' => 'publish' ] );
    foreach ( $posts as $post ) {
        $noindex = get_post_meta( $post->ID, 'rank_math_robots', true );
        if ( ! empty( $noindex ) && (
            ( is_array( $noindex ) && in_array( 'noindex', $noindex ) ) ||
            ( is_string( $noindex ) && strpos( $noindex, 'noindex' ) !== false )
        ) ) continue;
        $entries[] = [
            'loc'     => get_permalink( $post->ID ),
            'lastmod' => mysql2date( 'Y-m-d\TH:i:s+00:00', $post->post_modified ),
            'pri'     => '0.6',
        ];
    }

    header( 'Content-Type: application/xml; charset=UTF-8' );
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ( $entries as $e ) {
        echo "  <url>\n";
        echo "    <loc>" . esc_url( $e['loc'] ) . "</loc>\n";
        echo "    <lastmod>" . esc_html( $e['lastmod'] ) . "</lastmod>\n";
        echo "    <priority>" . esc_html( $e['pri'] ) . "</priority>\n";
        echo "  </url>\n";
    }
    echo '</urlset>' . "\n";
    echo "<!-- XML Sitemap generated by RankRocket SEO v" . RMB_VERSION . " -->\n";
}


// ── REST API ──────────────────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {

    $admin_only = function () { return current_user_can( 'manage_options' ); };

    // ── SEO Meta (RankMath-compatible postmeta) ───────────────────────────────
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

    // ── Bulk SEO meta read/write ───────────────────────────────────────────────
    register_rest_route( 'rankmath-bridge/v1', '/meta/bulk-get', [
        'methods'             => 'POST',
        'callback'            => 'rmb_meta_bulk_get',
        'permission_callback' => $admin_only,
        'args' => [
            'post_ids' => [ 'required' => true, 'type' => 'array' ],
        ],
    ] );

    register_rest_route( 'rankmath-bridge/v1', '/meta/bulk-update', [
        'methods'             => 'POST',
        'callback'            => 'rmb_meta_bulk_update',
        'permission_callback' => $admin_only,
        'args' => [
            'updates' => [ 'required' => true, 'type' => 'array' ],
        ],
    ] );

    // ── Image ALT text ─────────────────────────────────────────────────────────
    // GET  /images  — list all attachment images with current ALT text
    register_rest_route( 'rankmath-bridge/v1', '/images', [
        'methods'             => 'GET',
        'callback'            => 'rmb_images_list',
        'permission_callback' => $admin_only,
    ] );

    // POST /images/{id}/alt  — set ALT text on one attachment
    // MUST be registered before the wildcard
    register_rest_route( 'rankmath-bridge/v1', '/images/(?P<id>\d+)/alt', [
        'methods'             => 'POST',
        'callback'            => 'rmb_image_set_alt',
        'permission_callback' => $admin_only,
        'args' => [
            'id'  => [ 'required' => true, 'type' => 'integer' ],
            'alt' => [ 'required' => true, 'type' => 'string'  ],
        ],
    ] );

    // POST /images/bulk-alt  — set ALT text on multiple attachments atomically
    register_rest_route( 'rankmath-bridge/v1', '/images/bulk-alt', [
        'methods'             => 'POST',
        'callback'            => 'rmb_images_bulk_alt',
        'permission_callback' => $admin_only,
        'args' => [
            'updates' => [ 'required' => true, 'type' => 'array' ],
        ],
    ] );

    // ── llms.txt config ────────────────────────────────────────────────────────
    register_rest_route( 'rankmath-bridge/v1', '/llms', [
        [
            'methods'             => 'GET',
            'callback'            => 'rmb_llms_get_config',
            'permission_callback' => $admin_only,
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'rmb_llms_set_config',
            'permission_callback' => $admin_only,
            'args' => [
                'intro'    => [ 'required' => false, 'type' => 'string' ],
                'sections' => [ 'required' => false, 'type' => 'array'  ],
            ],
        ],
    ] );

    // ── Sitemap config ─────────────────────────────────────────────────────────
    register_rest_route( 'rankmath-bridge/v1', '/sitemap/preview', [
        'methods'             => 'GET',
        'callback'            => 'rmb_sitemap_preview',
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
                'location'   => [ 'required' => false, 'type' => 'string', 'default' => 'footer'         ],
                'display_on' => [ 'required' => false, 'type' => 'string', 'default' => 'entire_website' ],
                'status'     => [ 'required' => false, 'type' => 'string', 'default' => 'active'         ],
            ],
        ],
    ] );

    // ── Snippets: Replace all — MUST be registered BEFORE {id} wildcard ──────
    register_rest_route( 'rankmath-bridge/v1', '/snippets/replace-all', [
        'methods'             => 'POST',
        'callback'            => 'rmb_snippets_replace_all',
        'permission_callback' => $admin_only,
        'args' => [
            'snippets' => [ 'required' => true, 'type' => 'array' ],
        ],
    ] );

    // ── Snippets: Update / Delete by ID (wildcard — after replace-all) ───────
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


// ── SEO Meta Handlers ─────────────────────────────────────────────────────────

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

function rmb_meta_bulk_get( WP_REST_Request $request ) {
    $post_ids  = array_map( 'intval', $request->get_param( 'post_ids' ) );
    $meta_keys = [
        'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
        'rank_math_robots', 'rank_math_og_title', 'rank_math_og_description',
        'rank_math_seo_score',
    ];
    $results = [];
    foreach ( $post_ids as $pid ) {
        $post = get_post( $pid );
        if ( ! $post ) continue;
        $meta = [];
        foreach ( $meta_keys as $key ) {
            $meta[ $key ] = get_post_meta( $pid, $key, true );
        }
        $results[] = [
            'post_id' => $pid,
            'slug'    => $post->post_name,
            'title'   => get_the_title( $post ),
            'meta'    => $meta,
        ];
    }
    return rest_ensure_response( [ 'count' => count( $results ), 'pages' => $results ] );
}

function rmb_meta_bulk_update( WP_REST_Request $request ) {
    $updates = $request->get_param( 'updates' );
    $fields  = [
        'title'          => 'rank_math_title',
        'description'    => 'rank_math_description',
        'focus_keyword'  => 'rank_math_focus_keyword',
        'robots'         => 'rank_math_robots',
        'og_title'       => 'rank_math_og_title',
        'og_description' => 'rank_math_og_description',
    ];
    $results = [];
    foreach ( $updates as $upd ) {
        $post_id = intval( $upd['post_id'] ?? 0 );
        if ( ! $post_id || ! get_post( $post_id ) ) {
            $results[] = [ 'post_id' => $post_id, 'success' => false, 'error' => 'Post not found' ];
            continue;
        }
        $updated = [];
        foreach ( $fields as $param => $meta_key ) {
            if ( isset( $upd[ $param ] ) && $upd[ $param ] !== '' ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( $upd[ $param ] ) );
                $updated[ $meta_key ] = $upd[ $param ];
            }
        }
        wp_cache_delete( $post_id, 'post_meta' );
        clean_post_cache( $post_id );
        $results[] = [ 'post_id' => $post_id, 'success' => true, 'updated' => $updated ];
    }
    return rest_ensure_response( [ 'count' => count( $results ), 'results' => $results ] );
}


// ── Image ALT Handlers ────────────────────────────────────────────────────────

function rmb_images_list( WP_REST_Request $request ) {
    $page     = max( 1, intval( $request->get_param( 'page' ) ?? 1 ) );
    $per_page = min( 100, max( 10, intval( $request->get_param( 'per_page' ) ?? 50 ) ) );

    $attachments = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'post_status'    => 'inherit',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    $images = [];
    foreach ( $attachments as $att ) {
        $alt   = get_post_meta( $att->ID, '_wp_attachment_image_alt', true );
        $images[] = [
            'id'       => $att->ID,
            'filename' => basename( get_attached_file( $att->ID ) ),
            'url'      => wp_get_attachment_url( $att->ID ),
            'alt'      => $alt ?: '',
            'title'    => $att->post_title,
            'caption'  => $att->post_excerpt,
            'missing'  => empty( $alt ),
        ];
    }

    $total = wp_count_posts( 'attachment' );

    return rest_ensure_response( [
        'page'     => $page,
        'per_page' => $per_page,
        'count'    => count( $images ),
        'images'   => $images,
        'missing_alt_count' => count( array_filter( $images, fn( $i ) => $i['missing'] ) ),
    ] );
}

function rmb_image_set_alt( WP_REST_Request $request ) {
    $id  = intval( $request->get_param( 'id' ) );
    $alt = sanitize_text_field( $request->get_param( 'alt' ) );

    if ( ! get_post( $id ) ) {
        return new WP_Error( 'not_found', 'Attachment not found', [ 'status' => 404 ] );
    }

    $before = get_post_meta( $id, '_wp_attachment_image_alt', true );
    update_post_meta( $id, '_wp_attachment_image_alt', $alt );

    return rest_ensure_response( [
        'success'  => true,
        'id'       => $id,
        'filename' => basename( get_attached_file( $id ) ),
        'before'   => $before ?: '',
        'after'    => $alt,
    ] );
}

function rmb_images_bulk_alt( WP_REST_Request $request ) {
    $updates = $request->get_param( 'updates' ); // [ { id: 123, alt: "text" }, ... ]
    $results = [];

    foreach ( $updates as $upd ) {
        $id  = intval( $upd['id']  ?? 0 );
        $alt = sanitize_text_field( $upd['alt'] ?? '' );

        if ( ! $id || ! get_post( $id ) ) {
            $results[] = [ 'id' => $id, 'success' => false, 'error' => 'Attachment not found' ];
            continue;
        }

        $before = get_post_meta( $id, '_wp_attachment_image_alt', true );
        update_post_meta( $id, '_wp_attachment_image_alt', $alt );
        $results[] = [
            'id'       => $id,
            'filename' => basename( get_attached_file( $id ) ),
            'success'  => true,
            'before'   => $before ?: '',
            'after'    => $alt,
        ];
    }

    return rest_ensure_response( [ 'count' => count( $results ), 'results' => $results ] );
}


// ── llms.txt Handlers ─────────────────────────────────────────────────────────

function rmb_llms_get_config( WP_REST_Request $request ) {
    $config = get_option( 'rmb_llms_config', [] );
    return rest_ensure_response( [
        'url'    => rtrim( get_bloginfo( 'url' ), '/' ) . '/llms.txt',
        'config' => $config,
    ] );
}

function rmb_llms_set_config( WP_REST_Request $request ) {
    $config = get_option( 'rmb_llms_config', [] );

    $intro = $request->get_param( 'intro' );
    if ( $intro !== null ) $config['intro'] = sanitize_textarea_field( $intro );

    $sections = $request->get_param( 'sections' );
    if ( $sections !== null && is_array( $sections ) ) $config['sections'] = $sections;

    update_option( 'rmb_llms_config', $config );

    return rest_ensure_response( [
        'success' => true,
        'url'     => rtrim( get_bloginfo( 'url' ), '/' ) . '/llms.txt',
        'config'  => $config,
    ] );
}


// ── Sitemap Preview Handler ───────────────────────────────────────────────────

function rmb_sitemap_preview( WP_REST_Request $request ) {
    $entries    = [];
    $front_page = (int) get_option( 'page_on_front' );

    $pages = get_pages( [ 'post_status' => 'publish' ] );
    foreach ( $pages as $page ) {
        $noindex    = get_post_meta( $page->ID, 'rank_math_robots', true );
        $is_noindex = ! empty( $noindex ) && (
            ( is_array( $noindex ) && in_array( 'noindex', $noindex ) ) ||
            ( is_string( $noindex ) && strpos( $noindex, 'noindex' ) !== false )
        );
        $entries[] = [
            'id'       => $page->ID,
            'type'     => 'page',
            'loc'      => get_permalink( $page->ID ),
            'lastmod'  => mysql2date( 'Y-m-d', $page->post_modified ),
            'priority' => $page->ID === $front_page ? '1.0' : '0.8',
            'noindex'  => $is_noindex,
            'included' => ! $is_noindex,
        ];
    }

    $posts = get_posts( [ 'numberposts' => -1, 'post_status' => 'publish' ] );
    foreach ( $posts as $post ) {
        $noindex    = get_post_meta( $post->ID, 'rank_math_robots', true );
        $is_noindex = ! empty( $noindex ) && (
            ( is_array( $noindex ) && in_array( 'noindex', $noindex ) ) ||
            ( is_string( $noindex ) && strpos( $noindex, 'noindex' ) !== false )
        );
        $entries[] = [
            'id'       => $post->ID,
            'type'     => 'post',
            'loc'      => get_permalink( $post->ID ),
            'lastmod'  => mysql2date( 'Y-m-d', $post->post_modified ),
            'priority' => '0.6',
            'noindex'  => $is_noindex,
            'included' => ! $is_noindex,
        ];
    }

    $included = array_values( array_filter( $entries, function( $e ) { return $e['included']; } ) );
    $excluded = array_values( array_filter( $entries, function( $e ) { return ! $e['included']; } ) );

    return rest_ensure_response( [
        'sitemap_url'    => rtrim( get_bloginfo( 'url' ), '/' ) . '/rmb-sitemap.xml',
        'total'          => count( $entries ),
        'included_count' => count( $included ),
        'excluded_count' => count( $excluded ),
        'entries'        => array_values( $entries ),
    ] );
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

    if ( isset( $snippets[ $id ] ) ) {
        $id = $id . '_' . time();
    }

    $snippet = [
        'id'         => $id,
        'title'      => $title,
        'content'    => $request->get_param( 'content' ),
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

    $content = $request->get_param( 'content' );
    if ( $content !== null ) $snippets[ $id ]['content'] = $content;

    $snippets[ $id ]['updated_at'] = current_time( 'mysql' );

    update_option( RMB_SNIPPETS_KEY, $snippets );
    return rest_ensure_response( [ 'success' => true, 'snippet' => $snippets[ $id ] ] );
}

function rmb_snippets_replace_all( WP_REST_Request $request ) {
    $incoming = $request->get_param( 'snippets' );
    if ( ! is_array( $incoming ) ) {
        return new WP_Error( 'invalid_data', 'snippets must be an array', [ 'status' => 400 ] );
    }

    $clean_store = [];
    foreach ( $incoming as $snippet ) {
        $id = sanitize_title( $snippet['title'] ?? '' );
        if ( ! $id ) continue;
        if ( ! empty( $snippet['id'] ) ) {
            $id = sanitize_text_field( $snippet['id'] );
        }
        $clean_store[ $id ] = [
            'id'         => $id,
            'title'      => sanitize_text_field( $snippet['title']      ?? '' ),
            'content'    => $snippet['content']    ?? '',
            'location'   => sanitize_text_field( $snippet['location']   ?? 'footer' ),
            'display_on' => sanitize_text_field( $snippet['display_on'] ?? 'entire_website' ),
            'status'     => sanitize_text_field( $snippet['status']     ?? 'active' ),
            'created_at' => sanitize_text_field( $snippet['created_at'] ?? current_time( 'mysql' ) ),
            'updated_at' => current_time( 'mysql' ),
        ];
    }

    update_option( RMB_SNIPPETS_KEY, $clean_store );

    return rest_ensure_response( [
        'success' => true,
        'count'   => count( $clean_store ),
        'ids'     => array_keys( $clean_store ),
    ] );
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
    $snippets    = get_option( RMB_SNIPPETS_KEY, [] );
    $rankmath_on = class_exists( 'RankMath' );
    return rest_ensure_response( [
        'plugin'         => 'RankRocket SEO',
        'version'        => RMB_VERSION,
        'rankmath_active'=> $rankmath_on,
        'snippet_count'  => count( $snippets ),
        'snippet_ids'    => array_keys( $snippets ),
        'sitemap_url'    => rtrim( get_bloginfo( 'url' ), '/' ) . '/rmb-sitemap.xml',
        'llms_url'       => rtrim( get_bloginfo( 'url' ), '/' ) . '/llms.txt',
        'update_url'     => RMB_UPDATE_URL,
        'php_version'    => PHP_VERSION,
        'wp_version'     => get_bloginfo( 'version' ),
    ] );
}


// ── Self-Update ───────────────────────────────────────────────────────────────
// Allows the bridge to pull and install its own update from GitHub without
// requiring wp-admin login. Uses WP Plugin_Upgrader internally.
// POST /wp-json/rankmath-bridge/v1/self-update  { "zip_url": "https://..." }
// zip_url defaults to the latest release from the GitHub manifest.

add_action( 'rest_api_init', function () {
    $admin_only = function () { return current_user_can( 'manage_options' ); };

    register_rest_route( 'rankmath-bridge/v1', '/self-update', [
        'methods'             => 'POST',
        'callback'            => 'rmb_self_update',
        'permission_callback' => $admin_only,
        'args' => [
            'zip_url' => [ 'required' => false, 'type' => 'string' ],
        ],
    ] );
} );

function rmb_self_update( WP_REST_Request $request ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    // Resolve zip URL — use provided or fetch from manifest
    $zip_url = $request->get_param( 'zip_url' );
    if ( ! $zip_url ) {
        $manifest = wp_remote_get( RMB_UPDATE_URL, [ 'timeout' => 15 ] );
        if ( is_wp_error( $manifest ) ) {
            return new WP_Error( 'manifest_fetch_failed', $manifest->get_error_message(), [ 'status' => 500 ] );
        }
        $manifest_data = json_decode( wp_remote_retrieve_body( $manifest ), true );
        $zip_url       = $manifest_data['download_url'] ?? null;
        $remote_ver    = $manifest_data['version']      ?? 'unknown';
    } else {
        $remote_ver = 'provided';
    }

    if ( ! $zip_url ) {
        return new WP_Error( 'no_zip_url', 'Could not determine zip URL from manifest', [ 'status' => 500 ] );
    }

    $current_ver = RMB_VERSION;

    // Remove any old/deactivated copies with different folder names before upgrading
    $plugins_dir = WP_PLUGIN_DIR;
    $old_copies  = [ 'rankmath-rest-bridge', 'rankrocket-seo' ];
    foreach ( $old_copies as $folder ) {
        $path = "{$plugins_dir}/{$folder}";
        if ( is_dir( $path ) && $path !== WP_PLUGIN_DIR . '/' . dirname( RMB_PLUGIN_FILE ) ) {
            // Only remove if it is NOT the currently active plugin folder
            $active = WP_PLUGIN_DIR . '/' . plugin_basename( RMB_PLUGIN_FILE );
            if ( realpath( $path ) !== realpath( dirname( $active ) ) ) {
                // Safe to remove stale copy
                WP_Filesystem();
                global $wp_filesystem;
                if ( $wp_filesystem ) $wp_filesystem->delete( $path, true );
            }
        }
    }

    $skin     = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader( $skin );
    $result   = $upgrader->install( esc_url_raw( $zip_url ), [ 'overwrite_package' => true ] );

    if ( is_wp_error( $result ) ) {
        return new WP_Error( 'upgrade_failed', $result->get_error_message(), [ 'status' => 500 ] );
    }

    if ( $result === false ) {
        return new WP_Error( 'upgrade_failed', 'Upgrader returned false — check filesystem permissions', [ 'status' => 500 ] );
    }

    // Re-activate plugin after install
    $plugin_file = 'rankmath-rest-bridge/rankmath-rest-bridge.php';
    if ( ! is_plugin_active( $plugin_file ) ) {
        activate_plugin( $plugin_file );
    }

    return rest_ensure_response( [
        'success'      => true,
        'from_version' => $current_ver,
        'to_version'   => $remote_ver,
        'zip_url'      => $zip_url,
        'message'      => "Updated from {$current_ver} to {$remote_ver}. Plugin re-activated.",
    ] );
}
