<?php
/**
 * Plugin Name:  RankRocket SEO Control Layer
 * Description:  Native SEO control layer for the RankRocket remediation pipeline.
 *               Manages title/meta, schema injection, image ALT text, llms.txt,
 *               XML sitemap, cache purge, and self-updates. Reads legacy rank_math_*
 *               post-meta as a migration fallback; RankMath is not required.
 * Version:      2.11.3
 * Author:       Rank Rocket Co.
 * Author URI:   https://rankrocket.co
 * Requires PHP: 7.4
 * Requires WP:  5.9
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RMB_VERSION', '2.11.3' );
define( 'RMB_PLUGIN_FILE', __FILE__ );
define( 'RMB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RMB_SNIPPETS_KEY', 'rmb_managed_snippets' );
define( 'RMB_UPDATE_URL', 'https://raw.githubusercontent.com/PenZenMaster/rankmath-rest-bridge/main/update-manifest.json' );

// Native post-meta keys written by this plugin.
define(
	'RR_SEO_META_KEYS',
	array(
		'title'               => 'rr_seo_title',
		'description'         => 'rr_seo_description',
		'focus_keyword'       => 'rr_seo_focus_keyword',
		'robots'              => 'rr_seo_robots',
		'og_title'            => 'rr_seo_og_title',
		'og_description'      => 'rr_seo_og_description',
		'og_image'            => 'rr_seo_og_image',
		'canonical'           => '_rr_seo_canonical',
		'twitter_card'        => '_rr_seo_twitter_card',
		'twitter_title'       => '_rr_seo_twitter_title',
		'twitter_description' => '_rr_seo_twitter_description',
		'twitter_image'       => '_rr_seo_twitter_image',
	)
);

// Legacy RankMath keys — read-only migration fallback when native key is absent.
define(
	'RR_SEO_LEGACY_META_KEYS',
	array(
		'title'               => 'rank_math_title',
		'description'         => 'rank_math_description',
		'focus_keyword'       => 'rank_math_focus_keyword',
		'robots'              => 'rank_math_robots',
		'og_title'            => 'rank_math_og_title',
		'og_description'      => 'rank_math_og_description',
		'og_image'            => 'rank_math_og_image',
		'canonical'           => 'rank_math_canonical_url',
		'twitter_card'        => 'rank_math_twitter_card_type',
		'twitter_title'       => 'rank_math_twitter_title',
		'twitter_description' => 'rank_math_twitter_description',
		'twitter_image'       => 'rank_math_twitter_image',
	)
);

// Schema + audit log meta keys.
define( 'RR_SCHEMA_META_KEY', '_rrseo_schema_graph' );
define( 'RR_CHANGE_LOG_KEY', '_rrseo_change_log' );

// Validation allowlists.
define( 'RR_ALLOWED_POST_TYPES', array( 'post', 'page', 'product' ) );

define(
	'RR_ALLOWED_ROBOTS',
	array(
		'index',
		'noindex',
		'follow',
		'nofollow',
		'noarchive',
		'noodp',
		'noimageindex',
		'nosnippet',
	)
);

define(
	'RR_ALLOWED_SCHEMA_TYPES',
	array(
		'Article',
		'BlogPosting',
		'NewsArticle',
		'WebPage',
		'FAQPage',
		'HowTo',
		'Product',
		'LocalBusiness',
		'Organization',
		'Person',
		'Event',
		'Recipe',
		'VideoObject',
		'ImageObject',
		'BreadcrumbList',
		'Review',
		'Service',
	)
);

// Custom capability guarding the destructive replace-all endpoint.
// Granted to the administrator role on first load; can be revoked per-role
// to restrict bulk-sync access independently of manage_options.
define( 'RR_REPLACE_ALL_CAP', 'rrseo_replace_all_snippets' );

// Hard limits for AI-generated content.
define( 'RR_TITLE_MAX', 120 ); // Chars; hard error above this.
define( 'RR_TITLE_WARN_MAX', 60 ); // Chars; warning above this.
define( 'RR_TITLE_WARN_MIN', 30 ); // Chars; warning below this.
define( 'RR_DESC_MAX', 320 ); // Chars; hard error above this.
define( 'RR_DESC_WARN_MAX', 160 ); // Chars; warning above this.
define( 'RR_DESC_WARN_MIN', 50 ); // Chars; warning below this.
define( 'RR_BATCH_MAX', 20 ); // Items; hard error above this.

// llms.txt config option key (replaces legacy 'rmb_llms_config'; both are read during migration).
define( 'RR_LLMS_CONFIG_KEY', 'rrseo_llms_config' );

// robots.txt directive config option key.
define( 'RR_ROBOTS_CONFIG_KEY', 'rrseo_robots_config' );

// Post meta key for per-post llms.txt section override (set via POST /update or /meta/bulk-update).
define( 'META_LLMS_SECTION', '_rrseo_llms_section' );


// ── Canonical URL Set helper ──────────────────────────────────────────────────
// Loaded unconditionally — required by sitemaps, llms.txt, and REST preview endpoints.
require_once RMB_PLUGIN_DIR . 'includes/class-rrseo-canonical.php';

// ── llms.txt generator (section classifier, business_facts, renderer) ─────────
require_once RMB_PLUGIN_DIR . 'includes/class-rrseo-llms.php';

// ── AEO/GEO audit data layer (canonical preview, readiness, entity, schema, sync) ──
require_once RMB_PLUGIN_DIR . 'includes/class-rrseo-aeo-geo.php';


// ── Admin UI (loaded only in the WordPress admin; zero front-end cost) ─────────
if ( is_admin() ) {
	require_once RMB_PLUGIN_DIR . 'includes/class-rrseo-admin.php';
	require_once RMB_PLUGIN_DIR . 'includes/class-rrseo-metabox.php';
	new RRSEO_Admin();
	new RRSEO_MetaBox();
}


// ── Auto-update via plugin-update-checker ─────────────────────────────────────
// Stored globally so rmb_check_updates() can trigger an immediate manifest
// fetch without contacting WordPress.org (this is a private plugin).
add_action(
	'init',
	function () {
		$puc_loader = RMB_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( file_exists( $puc_loader ) ) {
			require_once $puc_loader;
			$GLOBALS['rrseo_puc_checker'] = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				RMB_UPDATE_URL,
				RMB_PLUGIN_FILE,
				'rankmath-rest-bridge', // GitHub repo slug; must match repo folder name.
				// Check period in hours — filterable so staging can reduce to 1h for testing.
				(int) apply_filters( 'rrseo_puc_check_period_hours', 12 )
			);
		}
	}
);


// ── Activation hook ──────────────────────────────────────────────────────────
// On activation (including upgrades), sync any existing rrseo_robots_txt content
// to a physical file at the webroot so it persists even when the plugin is inactive.
register_activation_hook(
	RMB_PLUGIN_FILE,
	function () {
		$content = (string) get_option( 'rrseo_robots_txt', '' );
		if ( '' !== $content && ! file_exists( ABSPATH . 'robots.txt' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( ABSPATH . 'robots.txt', $content );
		}
	}
);

// ── Capability provisioning ───────────────────────────────────────────────────
// Grants RR_REPLACE_ALL_CAP to the administrator role on first load.
// Stored in the DB with the role, so the write only happens once.
// To revoke: $role->remove_cap( RR_REPLACE_ALL_CAP ) in a one-time script.
add_action(
	'init',
	function () {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( RR_REPLACE_ALL_CAP ) ) {
			$role->add_cap( RR_REPLACE_ALL_CAP );
		}
	}
);

// ── Post meta registration ────────────────────────────────────────────────────
// Registers _rrseo_llms_section as a first-class WordPress meta key so it is
// discoverable via show_in_rest, sanitized by the meta layer, and visible in
// REST /wp/v2/posts responses.
add_action(
	'init',
	function () {
		$post_types = apply_filters( 'rrseo_metabox_post_types', array( 'post', 'page' ) );
		foreach ( $post_types as $post_type ) {
			register_post_meta(
				$post_type,
				META_LLMS_SECTION,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => function () {
						return current_user_can( 'manage_options' );
					},
				)
			);
		}
	}
);


// ── Output managed snippets ───────────────────────────────────────────────────
/**
 * Outputs managed snippets for the given head/footer location.
 *
 * @param string $location 'head' or 'footer'.
 */
add_action(
	'wp_head',
	function () {
		rmb_output_snippets( 'head' );
	},
	5
);
add_action(
	'wp_footer',
	function () {
		rmb_output_snippets( 'footer' );
	},
	99
);

/**
 * Outputs active snippets for the given location.
 *
 * @param string $location 'head' or 'footer'.
 */
function rmb_output_snippets( $location ) {
	$snippets = get_option( RMB_SNIPPETS_KEY, array() );
	if ( empty( $snippets ) ) {
		return;
	}

	foreach ( $snippets as $snippet ) {
		if ( ( $snippet['status'] ?? 'active' ) !== 'active' ) {
			continue;
		}
		if ( ( $snippet['location'] ?? 'footer' ) !== $location ) {
			continue;
		}

		$display_on    = $snippet['display_on'] ?? 'entire_website';
		$should_output = false;

		switch ( $display_on ) {
			case 'all':            // RankRocket canonical value for sitewide.
			case 'entire_website': // Legacy alias.
				$should_output = true;
				break;
			case 'home':
			case 'homepage':
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
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- managed snippets are admin-only, capability-guarded at write; must not strip <script>/<style>.
			echo "\n" . $snippet['content'] . "\n";
		}
	}
}


// ── Document <title> override ─────────────────────────────────────────────────
// Must be registered at plugin-load time, NOT inside a wp_head callback.
// WordPress core hooks _wp_render_title_tag() to wp_head at priority 1, and it
// was registered before this plugin loaded, so it always fires first at priority 1.
// Any add_filter( 'pre_get_document_title', ... ) call made inside a same-priority
// wp_head callback therefore runs after the <title> has already been rendered.
// Registering here guarantees the filter is in place when _wp_render_title_tag() runs.
add_filter( 'pre_get_document_title', 'rr_override_document_title', 20 );

// ── Canonical URL count cache invalidation ────────────────────────────────────
// Clears the rrseo_canonical_counts transient whenever site content changes so
// /status?include_counts=true always reflects the current canonical URL set.
add_action( 'save_post', 'rr_invalidate_canonical_cache' );
add_action( 'delete_post', 'rr_invalidate_canonical_cache' );
add_action( 'transition_post_status', 'rr_invalidate_canonical_cache' );
add_action( 'update_option_' . RR_LLMS_CONFIG_KEY, 'rr_invalidate_canonical_cache' );
add_action( 'update_option_rrseo_robots_txt', 'rr_invalidate_canonical_cache' );

// ── Legacy namespace alias ────────────────────────────────────────────────────
// Intercepts REST requests to /rankmath-bridge/v1/... (the original namespace
// before the v2.2.0 rename) and re-dispatches them to /rankrocket-seo/v1/...
// using the same method and body. Appends a _deprecated field to every response
// so callers know they need to update their base URL.
// Uses rest_pre_dispatch so WordPress never tries to match the old namespace
// against registered routes — no duplicate route registrations needed.
add_filter(
	'rest_pre_dispatch',
	'rr_legacy_namespace_proxy',
	10,
	3
);

/**
 * Re-dispatches legacy rankmath-bridge/v1 requests to rankrocket-seo/v1.
 *
 * @param mixed           $result  Pre-dispatch result (null when not short-circuited).
 * @param WP_REST_Server  $server  The REST server instance.
 * @param WP_REST_Request $request Incoming request object.
 * @return mixed WP_REST_Response for old-namespace requests; original $result otherwise.
 */
function rr_legacy_namespace_proxy( $result, WP_REST_Server $server, WP_REST_Request $request ) {
	$route      = $request->get_route();
	$old_prefix = '/rankmath-bridge/v1';

	if ( 0 !== strpos( $route, $old_prefix ) ) {
		return $result;
	}

	$new_route   = '/rankrocket-seo/v1' . substr( $route, strlen( $old_prefix ) );
	$new_request = new WP_REST_Request( $request->get_method(), $new_route );
	$new_request->set_query_params( $request->get_query_params() );
	$new_request->set_body( $request->get_body() );
	$new_request->set_headers( $request->get_headers() );

	$response = $server->dispatch( $new_request );

	if ( is_a( $response, 'WP_REST_Response' ) ) {
		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$data['_deprecated'] = array(
				'deprecated_namespace' => 'rankmath-bridge/v1',
				'preferred_namespace'  => 'rankrocket-seo/v1',
				'message'              => 'This namespace was renamed in v2.2.0. Update your client to use rankrocket-seo/v1.',
			);
			$response->set_data( $data );
		}
	}

	return $response;
}

// ── robots.txt override ───────────────────────────────────────────────────────
// Priority 99 to run after other plugins. Only applies when WordPress is serving
// its virtual robots.txt; a physical robots.txt file in the webroot takes
// precedence at the web-server level and bypasses this filter entirely.
add_filter( 'robots_txt', 'rr_robots_txt_output', 99, 2 );

// ── wp_robots() consolidation ─────────────────────────────────────────────────
// Merges per-post robots directives into the single <meta name="robots"> tag
// WordPress core emits via wp_robots(). When rrseo_consolidate_wp_robots is
// enabled (default), the standalone tag previously written by the RankRocket
// wp_head callback is suppressed and the directives are folded into core's
// associative array — preserving max-image-preview:large alongside our values.
add_filter( 'wp_robots', 'rr_merge_wp_robots', 20 );

// ── SEO meta output — description, robots, OG tags ───────────────────────────
// Title is handled above via pre_get_document_title; only echoed tags go here.
add_action(
	'wp_head',
	function () {
		if ( class_exists( 'RankMath' ) ) {
			return; // RankMath handles its own meta.
		}

		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();

		$desc   = rr_get_seo_meta( $post_id, 'description' );
		$robots = rr_get_seo_meta( $post_id, 'robots' );
		$desc   = rmb_resolve_tokens( $desc, $post_id );

		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		// Emit a standalone <meta name="robots"> only when consolidation with WP
		// core's wp_robots() is disabled. When enabled, rr_merge_wp_robots()
		// merges our directives into the single tag core renders.
		$consolidate = (bool) get_option( 'rrseo_consolidate_wp_robots', true );
		if ( ! $consolidate && $robots && ! empty( $robots ) ) {
			$robots_val = is_array( $robots ) ? implode( ',', $robots ) : $robots;
			if ( $robots_val ) {
				echo '<meta name="robots" content="' . esc_attr( $robots_val ) . '">' . "\n";
			}
		}

		$og_title = rr_get_seo_meta( $post_id, 'og_title' );
		$og_desc  = rr_get_seo_meta( $post_id, 'og_description' );
		$og_image = rr_get_seo_meta( $post_id, 'og_image' );
		if ( ! $og_image ) {
			$thumb_id = get_post_thumbnail_id( $post_id );
			if ( $thumb_id ) {
				$og_image = wp_get_attachment_image_url( $thumb_id, 'large' );
			}
		}
		if ( $og_title ) {
			echo '<meta property="og:title" content="' . esc_attr( rmb_resolve_tokens( $og_title, $post_id ) ) . '">' . "\n";
		}
		if ( $og_desc ) {
			echo '<meta property="og:description" content="' . esc_attr( rmb_resolve_tokens( $og_desc, $post_id ) ) . '">' . "\n";
		}
		if ( $og_image ) {
			echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
		}
	},
	1
);


// ── Canonical URL emission ────────────────────────────────────────────────────
// Emits <link rel="canonical"> for singular posts/pages/products in the allowed
// post types. Source precedence: (1) per-post _rr_seo_canonical override,
// (2) computed canonical from rr_get_post_canonical_url(), (3) get_permalink().
// Suppressed when the post robots meta declares noindex, when another emitter
// has already written a canonical tag during this request, or when the
// rrseo_emit_canonical filter returns false.
add_action(
	'wp_head',
	function () {
		if ( class_exists( 'RankMath' ) ) {
			return; // RankMath handles its own canonical.
		}
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$allowed_types = apply_filters( 'rrseo_allowed_post_types', RR_ALLOWED_POST_TYPES );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}

		// Suppress when the post is marked noindex.
		$robots = rr_get_seo_meta( $post_id, 'robots' );
		if ( $robots ) {
			$robots_val = is_array( $robots ) ? implode( ',', $robots ) : (string) $robots;
			if ( false !== stripos( $robots_val, 'noindex' ) ) {
				return;
			}
		}

		// Allow external suppression (e.g. another SEO plugin already emitted one).
		if ( ! apply_filters( 'rrseo_emit_canonical', true, $post_id ) ) {
			return;
		}

		// Source priority: per-post override → computed canonical → permalink.
		$override = (string) get_post_meta( $post_id, '_rr_seo_canonical', true );
		if ( '' === $override ) {
			// Legacy RankMath fallback.
			$override = (string) get_post_meta( $post_id, 'rank_math_canonical_url', true );
		}

		if ( '' !== $override ) {
			$canonical_url = $override;
		} else {
			$canonical_url = (string) get_permalink( $post );
		}

		$canonical_url = (string) apply_filters( 'rrseo_canonical_url', $canonical_url, $post_id );

		if ( '' === $canonical_url ) {
			return;
		}

		echo '<link rel="canonical" href="' . esc_url( $canonical_url ) . '">' . "\n";
	},
	1
);


// ── Twitter Card emission ─────────────────────────────────────────────────────
// Emits twitter:card, twitter:title, twitter:description, and twitter:image for
// singular posts in the allowed post types. Per-post _rr_seo_twitter_* values
// take precedence; otherwise we fall back to OG fields, then post defaults.
add_action(
	'wp_head',
	function () {
		if ( class_exists( 'RankMath' ) ) {
			return; // RankMath handles its own Twitter tags.
		}
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$allowed_types = apply_filters( 'rrseo_allowed_post_types', RR_ALLOWED_POST_TYPES );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}

		if ( ! apply_filters( 'rrseo_emit_twitter_cards', true, $post_id ) ) {
			return;
		}

		// Card type — default summary_large_image, overridable per post.
		$card = rr_get_seo_meta( $post_id, 'twitter_card' );
		if ( ! $card ) {
			$card = 'summary_large_image';
		}

		// Title precedence: twitter_title → og_title → rr_seo_title → post title.
		$title = rr_get_seo_meta( $post_id, 'twitter_title' );
		if ( ! $title ) {
			$title = rr_get_seo_meta( $post_id, 'og_title' );
		}
		if ( ! $title ) {
			$title = rr_get_seo_meta( $post_id, 'title' );
		}
		if ( ! $title ) {
			$title = get_the_title( $post );
		}
		$title = rmb_resolve_tokens( $title, $post_id );

		// Description precedence: twitter_description → og_description → rr_seo_description → excerpt.
		$description = rr_get_seo_meta( $post_id, 'twitter_description' );
		if ( ! $description ) {
			$description = rr_get_seo_meta( $post_id, 'og_description' );
		}
		if ( ! $description ) {
			$description = rr_get_seo_meta( $post_id, 'description' );
		}
		if ( ! $description ) {
			$excerpt     = $post->post_excerpt ? $post->post_excerpt : $post->post_content;
			$description = wp_trim_words( wp_strip_all_tags( $excerpt ), 30 );
		}
		$description = rmb_resolve_tokens( $description, $post_id );

		// Image precedence: twitter_image → og_image → featured image.
		$image = rr_get_seo_meta( $post_id, 'twitter_image' );
		if ( ! $image ) {
			$image = rr_get_seo_meta( $post_id, 'og_image' );
		}
		if ( ! $image ) {
			$thumb_id = get_post_thumbnail_id( $post_id );
			if ( $thumb_id ) {
				$image = wp_get_attachment_image_url( $thumb_id, 'large' );
			}
		}

		echo '<meta name="twitter:card" content="' . esc_attr( $card ) . '">' . "\n";
		if ( $title ) {
			echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
		}
		if ( $description ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
		}
		if ( $image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
		}
	},
	1
);


// ── Schema JSON-LD output ─────────────────────────────────────────────────────
// Outputs whatever graph is stored in _rrseo_schema_graph for singular posts.
// Safe to coexist with RankMath (different @type values produce separate blocks).
add_action(
	'wp_head',
	function () {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		$schema  = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
		if ( ! $schema || ! is_array( $schema ) ) {
			return;
		}
		echo '<script type="application/ld+json">' . "\n";
		echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		echo "\n</script>\n";
	},
	5
);


// ── Token resolver ────────────────────────────────────────────────────────────
/**
 * Replaces %title%, %sitename%, %sep%, and %excerpt% tokens in a string.
 *
 * @param string $str     The template string.
 * @param int    $post_id Post ID for token resolution context.
 * @return string
 */
function rmb_resolve_tokens( $str, $post_id ) {
	if ( ! $str ) {
		return $str;
	}
	$post    = get_post( $post_id );
	$excerpt = $post ? ( $post->post_excerpt ? $post->post_excerpt : $post->post_content ) : '';
	$tokens  = array(
		'%title%'    => $post ? get_the_title( $post ) : '',
		'%sitename%' => get_bloginfo( 'name' ),
		'%sep%'      => '|',
		'%excerpt%'  => $post ? wp_trim_words( $excerpt, 20 ) : '',
	);
	return str_replace( array_keys( $tokens ), array_values( $tokens ), $str );
}


// ── Document title override callback ─────────────────────────────────────────
/**
 * Overrides the document title with the stored rr_seo_title for singular posts.
 *
 * Registered on pre_get_document_title at plugin-load time (not inside wp_head)
 * so the filter is in place before _wp_render_title_tag() fires at priority 1.
 *
 * @param string $title The default document title.
 * @return string
 */
function rr_override_document_title( $title ) {
	if ( class_exists( 'RankMath' ) ) {
		return $title;
	}
	if ( ! is_singular() ) {
		return $title;
	}
	$post_id   = get_queried_object_id();
	$seo_title = rr_get_seo_meta( $post_id, 'title' );
	if ( ! $seo_title ) {
		return $title;
	}
	return rmb_resolve_tokens( $seo_title, $post_id );
}


// ── Canonical cache helper ────────────────────────────────────────────────────
/**
 * Deletes the cached canonical URL counts transient.
 * Hooked to save_post, delete_post, transition_post_status, and option updates.
 */
function rr_invalidate_canonical_cache(): void {
	delete_transient( 'rrseo_canonical_counts' );
}


// ── SEO meta read helper ──────────────────────────────────────────────────────
/**
 * Reads the native rr_seo_* key; falls back to rank_math_* when unset.
 *
 * @param int    $post_id Post ID.
 * @param string $field   Field key (e.g. 'title', 'description').
 * @return mixed
 */
function rr_get_seo_meta( $post_id, $field ) {
	$val = get_post_meta( $post_id, RR_SEO_META_KEYS[ $field ], true );
	if ( '' === $val || false === $val ) {
		$val = get_post_meta( $post_id, RR_SEO_LEGACY_META_KEYS[ $field ], true );
	}
	return $val;
}


// ── Validation layer ──────────────────────────────────────────────────────────

/**
 * Validates SEO field values for a given post.
 *
 * Returns array( 'errors' => string[], 'warnings' => string[] ).
 * Errors are hard failures; warnings are advisory.
 *
 * @param array    $fields  Map of field name => value.
 * @param int|null $post_id Optional post ID for post-type validation.
 * @return array{errors: string[], warnings: string[]}
 */
function rr_validate_seo_fields( array $fields, $post_id = null ) {
	$errors   = array();
	$warnings = array();

	if ( null !== $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			$errors[] = "post_id {$post_id}: post not found";
		} else {
			$allowed_types = apply_filters( 'rrseo_allowed_post_types', RR_ALLOWED_POST_TYPES );
			if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
				$errors[] = "post_id {$post_id}: post type '{$post->post_type}' not allowed. Allowed: " . implode( ', ', $allowed_types );
			}
		}
	}

	if ( isset( $fields['title'] ) && '' !== $fields['title'] ) {
		$len = mb_strlen( $fields['title'] );
		if ( $len > RR_TITLE_MAX ) {
			$errors[] = "title: {$len} chars — exceeds hard limit of " . RR_TITLE_MAX;
		} elseif ( $len > RR_TITLE_WARN_MAX ) {
			$warnings[] = "title: {$len} chars — above recommended maximum of " . RR_TITLE_WARN_MAX;
		} elseif ( $len < RR_TITLE_WARN_MIN ) {
			$warnings[] = "title: {$len} chars — below recommended minimum of " . RR_TITLE_WARN_MIN;
		}
	}

	if ( isset( $fields['description'] ) && '' !== $fields['description'] ) {
		$len = mb_strlen( $fields['description'] );
		if ( $len > RR_DESC_MAX ) {
			$errors[] = "description: {$len} chars — exceeds hard limit of " . RR_DESC_MAX;
		} elseif ( $len > RR_DESC_WARN_MAX ) {
			$warnings[] = "description: {$len} chars — above recommended maximum of " . RR_DESC_WARN_MAX;
		} elseif ( $len < RR_DESC_WARN_MIN ) {
			$warnings[] = "description: {$len} chars — below recommended minimum of " . RR_DESC_WARN_MIN;
		}
	}

	if ( isset( $fields['og_image'] ) && '' !== $fields['og_image'] ) {
		if ( ! filter_var( $fields['og_image'], FILTER_VALIDATE_URL ) ) {
			$errors[] = 'og_image: not a valid URL';
		}
	}

	if ( isset( $fields['canonical'] ) && '' !== $fields['canonical'] ) {
		if ( ! filter_var( $fields['canonical'], FILTER_VALIDATE_URL ) ) {
			$errors[] = 'canonical: not a valid URL';
		}
	}

	if ( isset( $fields['twitter_image'] ) && '' !== $fields['twitter_image'] ) {
		if ( ! filter_var( $fields['twitter_image'], FILTER_VALIDATE_URL ) ) {
			$errors[] = 'twitter_image: not a valid URL';
		}
	}

	if ( isset( $fields['twitter_card'] ) && '' !== $fields['twitter_card'] ) {
		$allowed_cards = array( 'summary', 'summary_large_image', 'app', 'player' );
		if ( ! in_array( $fields['twitter_card'], $allowed_cards, true ) ) {
			$errors[] = 'twitter_card: invalid value. Allowed: ' . implode( ', ', $allowed_cards );
		}
	}

	if ( isset( $fields['robots'] ) && '' !== $fields['robots'] ) {
		$values  = array_map( 'trim', explode( ',', $fields['robots'] ) );
		$invalid = array_diff( $values, RR_ALLOWED_ROBOTS );
		if ( $invalid ) {
			$errors[] = 'robots: invalid value(s): ' . implode( ', ', $invalid )
						. '. Allowed: ' . implode( ', ', RR_ALLOWED_ROBOTS );
		}
	}

	return array(
		'errors'   => $errors,
		'warnings' => $warnings,
	);
}

/**
 * Validates a JSON-LD schema object.
 *
 * Accepts either a PHP array or a JSON string.
 * Returns array( 'errors' => string[], 'warnings' => string[], 'schema' => array|null ).
 *
 * @param array|string $schema Schema as a PHP array or raw JSON string.
 * @return array{errors: string[], warnings: string[], schema: array|null}
 */
function rr_validate_schema( $schema ) {
	$errors   = array();
	$warnings = array();

	if ( is_string( $schema ) ) {
		$decoded = json_decode( $schema, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'errors'   => array( 'schema: invalid JSON — ' . json_last_error_msg() ),
				'warnings' => array(),
				'schema'   => null,
			);
		}
		$schema = $decoded;
	}

	if ( ! is_array( $schema ) ) {
		return array(
			'errors'   => array( 'schema: must be a JSON object' ),
			'warnings' => array(),
			'schema'   => null,
		);
	}

	if ( empty( $schema['@context'] ) ) {
		$errors[] = 'schema: missing required @context';
	}

	if ( empty( $schema['@type'] ) ) {
		$errors[] = 'schema: missing required @type';
	} else {
		$allowed = apply_filters( 'rrseo_allowed_schema_types', RR_ALLOWED_SCHEMA_TYPES );
		if ( ! in_array( $schema['@type'], $allowed, true ) ) {
			$errors[] = "schema: @type '{$schema['@type']}' not allowed. Allowed: " . implode( ', ', $allowed );
		}
	}

	return array(
		'errors'   => $errors,
		'warnings' => $warnings,
		'schema'   => $schema,
	);
}


// ── Audit log ─────────────────────────────────────────────────────────────────
/**
 * Appends an entry to the per-post audit log, capped at 100 entries.
 *
 * @param int    $post_id    Post ID.
 * @param string $endpoint   REST endpoint that triggered the write.
 * @param array  $changes    Map of field => array( 'before' => mixed, 'after' => mixed ).
 * @param string $request_id X-Request-ID value for correlation.
 * @param string $status     'written', 'migrated', etc.
 */
function rr_audit_log( $post_id, $endpoint, array $changes, $request_id, $status ) {
	$log  = get_post_meta( $post_id, RR_CHANGE_LOG_KEY, true );
	$log  = is_array( $log ) ? $log : array();
	$uid  = get_current_user_id();
	$user = $uid ? get_userdata( $uid ) : null;

	$log[] = array(
		'timestamp'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'user_id'    => $uid,
		'user_login' => $user ? $user->user_login : 'system',
		'endpoint'   => $endpoint,
		'post_id'    => $post_id,
		'changes'    => $changes,
		'request_id' => $request_id,
		'status'     => $status,
	);

	// Cap at 100 entries per post to prevent unbounded growth.
	if ( count( $log ) > 100 ) {
		$log = array_slice( $log, -100 );
	}

	update_post_meta( $post_id, RR_CHANGE_LOG_KEY, $log );
}

/**
 * Returns the X-Request-ID header value, or generates a UUID if absent.
 *
 * @param WP_REST_Request $request Current REST request.
 * @return string
 */
function rr_request_id( WP_REST_Request $request ) {
	$id = $request->get_header( 'x-request-id' );
	return $id ? $id : wp_generate_uuid4();
}


// ── llms.txt generator ────────────────────────────────────────────────────────
add_action(
	'init',
	function () {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		$uri = strtok( $_SERVER['REQUEST_URI'], '?' );
		$uri = rtrim( $uri, '/' );
		if ( '/llms.txt' === $uri ) {
			rmb_serve_llms_txt();
			exit;
		}
	},
	1
);

/**
 * Serves the dynamic /llms.txt plain-text response.
 */
function rmb_serve_llms_txt() {
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	if ( headers_sent( $hf, $hl ) ) {
		status_header( 200 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text response; $hf/$hl come from PHP's headers_sent().
		echo "# llms.txt\n# Note: headers already sent from {$hf}:{$hl}\n";
		return;
	}
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	header( 'Cache-Control: no-cache' );

	$config = get_option( RR_LLMS_CONFIG_KEY, get_option( 'rmb_llms_config', array() ) );
	$result = rr_render_llms_txt( $config );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text llms.txt; content is site-admin-controlled.
	echo $result['content'];
}


// ── XML Sitemap ───────────────────────────────────────────────────────────────
add_action(
	'wp',
	function () {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$uri = rtrim( strtok( $_SERVER['REQUEST_URI'], '?' ), '/' );

		// XSL stylesheet — must be registered before any XML that references it.
		if ( '/rmb-sitemap.xsl' === $uri ) {
			rmb_serve_sitemap_xsl();
			exit;
		}
		// Per-type sub-sitemaps.
		if ( '/rmb-sitemap-posts.xml' === $uri ) {
			rmb_serve_sitemap_type( 'post' );
			exit;
		}
		if ( '/rmb-sitemap-pages.xml' === $uri ) {
			rmb_serve_sitemap_type( 'page' );
			exit;
		}
		// Index — points to per-type sub-sitemaps.
		if ( '/rmb-sitemap-index.xml' === $uri || '/sitemap_index.xml' === $uri ) {
			rmb_serve_sitemap_index();
			exit;
		}
		// Legacy combined sitemap kept for backward compatibility.
		if ( '/rmb-sitemap.xml' === $uri || '/sitemap.xml' === $uri ) {
			rmb_serve_sitemap();
			exit;
		}
	}
);

/**
 * Serves the XSL stylesheet that styles sitemaps in the browser.
 *
 * Reads includes/sitemap.xsl and replaces RRSEO_* tokens with live site values
 * before output so the stylesheet is self-contained without a separate request.
 */
function rmb_serve_sitemap_xsl(): void {
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	status_header( 200 );
	header( 'Content-Type: text/xsl; charset=UTF-8' );
	header( 'Cache-Control: public, max-age=86400' );

	$xsl_file = RMB_PLUGIN_DIR . 'includes/sitemap.xsl';
	if ( ! file_exists( $xsl_file ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, not remote URL.
	$xsl = file_get_contents( $xsl_file );

	$xsl = str_replace(
		array( 'RRSEO_SITE_NAME', 'RRSEO_INDEX_URL', 'RRSEO_VERSION' ),
		array(
			esc_html( get_bloginfo( 'name' ) ),
			esc_url( rtrim( get_bloginfo( 'url' ), '/' ) . '/sitemap_index.xml' ),
			esc_html( RMB_VERSION ),
		),
		$xsl
	);

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XSL file is a committed plugin asset; tokens replaced above.
	echo $xsl;
}

/**
 * Outputs the sitemap index document, pointing to per-type sub-sitemaps.
 *
 * Replaces the legacy single-entry index. Each sub-sitemap carries its own
 * lastmod reflecting the most recently modified post of that type.
 */
function rmb_serve_sitemap_index(): void {
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	status_header( 200 );

	$site_url = rtrim( get_bloginfo( 'url' ), '/' );
	$xsl_url  = $site_url . '/rmb-sitemap.xsl';
	$fallback = gmdate( 'Y-m-d\TH:i:s+00:00' );

	// Real lastmod per type — use post_modified_gmt for accurate UTC timestamp.
	$last_post = get_posts(
		array(
			'numberposts' => 1,
			'orderby'     => 'modified',
			'order'       => 'DESC',
			'post_status' => 'publish',
		)
	);
	$posts_mod = $last_post
		? mysql2date( 'Y-m-d\TH:i:s+00:00', $last_post[0]->post_modified_gmt )
		: $fallback;

	$last_page = get_posts(
		array(
			'post_type'   => 'page',
			'numberposts' => 1,
			'orderby'     => 'modified',
			'order'       => 'DESC',
			'post_status' => 'publish',
		)
	);
	$pages_mod = $last_page
		? mysql2date( 'Y-m-d\TH:i:s+00:00', $last_page[0]->post_modified_gmt )
		: $fallback;

	header( 'Content-Type: application/xml; charset=UTF-8' );
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document; all values are from trusted WP functions or esc_url/esc_html.
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl_url ) . '"?>' . "\n";
	echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	echo "  <sitemap>\n";
	echo '    <loc>' . esc_url( $site_url . '/rmb-sitemap-posts.xml' ) . "</loc>\n";
	echo '    <lastmod>' . esc_html( $posts_mod ) . "</lastmod>\n";
	echo "  </sitemap>\n";
	echo "  <sitemap>\n";
	echo '    <loc>' . esc_url( $site_url . '/rmb-sitemap-pages.xml' ) . "</loc>\n";
	echo '    <lastmod>' . esc_html( $pages_mod ) . "</lastmod>\n";
	echo "  </sitemap>\n";
	echo '</sitemapindex>' . "\n";
	echo '<!-- XML Sitemap Index generated by RankRocket SEO Control Layer v' . RMB_VERSION . " -->\n";
	// phpcs:enable
}

/**
 * Outputs a per-type XML sitemap using the shared Canonical URL Set.
 *
 * Replaces the previous independent URL loop with rr_get_canonical_url_set()
 * to guarantee consistent filtering with llms.txt and the sitemap preview.
 *
 * @param string $post_type 'post' or 'page'.
 */
function rmb_serve_sitemap_type( string $post_type ): void {
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	status_header( 200 );

	$site_url   = rtrim( get_bloginfo( 'url' ), '/' );
	$xsl_url    = $site_url . '/rmb-sitemap.xsl';
	$front_page = 'page' === $post_type ? (int) get_option( 'page_on_front' ) : 0;

	$canonical = rr_get_canonical_url_set( array( 'post_types' => array( $post_type ) ) );

	header( 'Content-Type: application/xml; charset=UTF-8' );
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document; all values escaped inline below.
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl_url ) . '"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $canonical['urls'] as $entry ) {
		$priority = '0.6';
		if ( 'page' === $post_type ) {
			$priority = ( $entry['post_id'] === $front_page ) ? '1.0' : '0.8';
		}
		echo "  <url>\n";
		echo '    <loc>' . esc_url( $entry['url'] ) . "</loc>\n";
		echo '    <lastmod>' . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
		echo '    <priority>' . esc_html( $priority ) . "</priority>\n";
		echo "  </url>\n";
	}
	echo '</urlset>' . "\n";
	echo '<!-- XML Sitemap (' . esc_html( $post_type ) . ') generated by RankRocket SEO Control Layer v' . RMB_VERSION . " -->\n";
	// phpcs:enable
}

/**
 * Outputs the legacy combined XML sitemap using the shared Canonical URL Set.
 *
 * Kept for backward compatibility; canonical sitemap is the per-type sub-sitemaps
 * linked from /sitemap_index.xml. Now uses rr_get_canonical_url_set() for
 * consistent filtering.
 */
function rmb_serve_sitemap(): void {
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	status_header( 200 );

	$front_page = (int) get_option( 'page_on_front' );
	$site_url   = rtrim( get_bloginfo( 'url' ), '/' );
	$xsl_url    = $site_url . '/rmb-sitemap.xsl';

	$canonical = rr_get_canonical_url_set(
		array( 'post_types' => array( 'post', 'page' ) )
	);

	header( 'Content-Type: application/xml; charset=UTF-8' );
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document; all values escaped inline below.
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl_url ) . '"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $canonical['urls'] as $entry ) {
		if ( 'page' === $entry['post_type'] ) {
			$priority = ( $entry['post_id'] === $front_page ) ? '1.0' : '0.8';
		} else {
			$priority = '0.6';
		}
		echo "  <url>\n";
		echo '    <loc>' . esc_url( $entry['url'] ) . "</loc>\n";
		echo '    <lastmod>' . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
		echo '    <priority>' . esc_html( $priority ) . "</priority>\n";
		echo "  </url>\n";
	}
	echo '</urlset>' . "\n";
	echo '<!-- XML Sitemap (combined/legacy) generated by RankRocket SEO Control Layer v' . RMB_VERSION . " -->\n";
	// phpcs:enable
}


// ── REST API ──────────────────────────────────────────────────────────────────
add_action(
	'rest_api_init',
	function () {

		$admin_only = function () {
			return current_user_can( 'manage_options' );
		};

		// ── SEO Meta ──────────────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/update',
			array(
				'methods'             => 'POST',
				'callback'            => 'rmb_update_meta',
				'permission_callback' => $admin_only,
				'args'                => array(
					'post_id'        => array(
						'required' => true,
						'type'     => 'integer',
					),
					'title'          => array(
						'required' => false,
						'type'     => 'string',
					),
					'description'    => array(
						'required' => false,
						'type'     => 'string',
					),
					'focus_keyword'  => array(
						'required' => false,
						'type'     => 'string',
					),
					'robots'         => array(
						'required' => false,
						'type'     => 'string',
					),
					'og_title'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'og_description' => array(
						'required' => false,
						'type'     => 'string',
					),
					'og_image'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'canonical'           => array(
						'required' => false,
						'type'     => 'string',
					),
					'twitter_card'        => array(
						'required' => false,
						'type'     => 'string',
					),
					'twitter_title'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'twitter_description' => array(
						'required' => false,
						'type'     => 'string',
					),
					'twitter_image'       => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			'rankrocket-seo/v1',
			'/get/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_get_meta',
				'permission_callback' => $admin_only,
			)
		);

		// ── Preview / dry-run ─────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/preview-update',
			array(
				'methods'             => 'POST',
				'callback'            => 'rmb_preview_update',
				'permission_callback' => $admin_only,
				'args'                => array(
					'post_id'        => array(
						'required' => true,
						'type'     => 'integer',
					),
					'title'          => array(
						'required' => false,
						'type'     => 'string',
					),
					'description'    => array(
						'required' => false,
						'type'     => 'string',
					),
					'focus_keyword'  => array(
						'required' => false,
						'type'     => 'string',
					),
					'robots'         => array(
						'required' => false,
						'type'     => 'string',
					),
					'og_title'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'og_description' => array(
						'required' => false,
						'type'     => 'string',
					),
					'og_image'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'canonical'           => array(
						'required' => false,
						'type'     => 'string',
					),
					'twitter_card'        => array(
						'required' => false,
						'type'     => 'string',
					),
					'twitter_title'       => array(
						'required' => false,
						'type'     => 'string',
					),
					'twitter_description' => array(
						'required' => false,
						'type'     => 'string',
					),
					'twitter_image'       => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		// ── Bulk SEO meta read/write ───────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/meta/bulk-get',
			array(
				'methods'             => 'POST',
				'callback'            => 'rmb_meta_bulk_get',
				'permission_callback' => $admin_only,
				'args'                => array(
					'post_ids' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);

		register_rest_route(
			'rankrocket-seo/v1',
			'/meta/bulk-update',
			array(
				'methods'             => 'POST',
				'callback'            => 'rmb_meta_bulk_update',
				'permission_callback' => $admin_only,
				'args'                => array(
					'updates' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);

		// ── Schema ────────────────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/schema/(?P<post_id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => 'rmb_schema_get',
					'permission_callback' => $admin_only,
				),
				array(
					'methods'             => 'POST',
					'callback'            => 'rmb_schema_set',
					'permission_callback' => $admin_only,
					'args'                => array(
						'schema'  => array(
							'required' => true,
							'type'     => 'object',
						),
						'dry_run' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						),
					),
				),
			)
		);

		// ── Audit log ─────────────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/log/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_log_get',
				'permission_callback' => $admin_only,
			)
		);

		// ── Image ALT text ─────────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/images',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_images_list',
				'permission_callback' => $admin_only,
			)
		);

		// MUST be registered before the wildcard.
		register_rest_route(
			'rankrocket-seo/v1',
			'/images/(?P<id>\d+)/alt',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => 'rmb_image_get_alt',
					'permission_callback' => $admin_only,
				),
				array(
					'methods'             => 'POST',
					'callback'            => 'rmb_image_set_alt',
					'permission_callback' => $admin_only,
					'args'                => array(
						'id'  => array(
							'required' => true,
							'type'     => 'integer',
						),
						'alt' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			'rankrocket-seo/v1',
			'/images/bulk-alt',
			array(
				'methods'             => 'POST',
				'callback'            => 'rmb_images_bulk_alt',
				'permission_callback' => $admin_only,
				'args'                => array(
					'updates' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);

		// ── llms.txt config ────────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/llms',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => 'rmb_llms_get_config',
					'permission_callback' => $admin_only,
				),
				array(
					'methods'             => 'POST',
					'callback'            => 'rmb_llms_set_config',
					'permission_callback' => $admin_only,
					'args'                => array(
						'intro'                 => array(
							'required' => false,
							'type'     => 'string',
						),
						'sections'              => array(
							'required' => false,
							'type'     => 'object',
						),
						'custom_sections'       => array(
							'required' => false,
							'type'     => 'array',
						),
						'business_facts'        => array(
							'required' => false,
							'type'     => 'object',
						),
						'schema_source_post_id' => array(
							'required' => false,
							'type'     => 'integer',
						),
						'include_sitemaps'      => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'include_lastmod'       => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'exclude_noindex'       => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'exclude_utility_pages' => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'exclude_patterns'      => array(
							'required' => false,
							'type'     => 'array',
						),
						'group_by_intent'       => array(
							'required' => false,
							'type'     => 'boolean',
						),
						'max_description_chars' => array(
							'required' => false,
							'type'     => 'integer',
						),
					),
				),
			)
		);

		// ── llms.txt preview ──────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/llms/preview',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_llms_preview',
				'permission_callback' => $admin_only,
				'args'                => array(
					'format' => array(
						'required' => false,
						'type'     => 'string',
						'enum'     => array( 'json', 'text' ),
						'default'  => 'json',
					),
				),
			)
		);

		// ── Sitemap config ─────────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/sitemap/preview',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_sitemap_preview',
				'permission_callback' => $admin_only,
			)
		);

		// ── Snippets ──────────────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/snippets',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => 'rmb_snippets_list',
					'permission_callback' => $admin_only,
				),
				array(
					'methods'             => 'POST',
					'callback'            => 'rmb_snippets_create',
					'permission_callback' => $admin_only,
					'args'                => array(
						'title'      => array(
							'required' => true,
							'type'     => 'string',
						),
						'content'    => array(
							'required' => true,
							'type'     => 'string',
						),
						'location'   => array(
							'required' => false,
							'type'     => 'string',
							'default'  => 'footer',
						),
						'display_on' => array(
							'required' => false,
							'type'     => 'string',
							'default'  => 'entire_website',
						),
						'status'     => array(
							'required' => false,
							'type'     => 'string',
							'default'  => 'active',
						),
					),
				),
			)
		);

		// MUST be registered BEFORE the {id} wildcard.
		// @deprecated — prefer per-snippet create/update/delete. Target removal: v3.0.0.
		register_rest_route(
			'rankrocket-seo/v1',
			'/snippets/replace-all',
			array(
				'methods'             => 'POST',
				'callback'            => 'rmb_snippets_replace_all',
				'permission_callback' => function () {
					return current_user_can( RR_REPLACE_ALL_CAP ); },
				'args'                => array(
					'snippets' => array(
						'required' => true,
						'type'     => 'array',
					),
					'confirm'  => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		// ── Snippets: Update / Delete by ID (wildcard — after replace-all) ───────
		register_rest_route(
			'rankrocket-seo/v1',
			'/snippets/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => 'rmb_snippets_update',
					'permission_callback' => $admin_only,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => 'rmb_snippets_delete',
					'permission_callback' => $admin_only,
				),
			)
		);

		// ── Cache ─────────────────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/cache/purge',
			array(
				'methods'             => 'POST',
				'callback'            => 'rmb_cache_purge',
				'permission_callback' => $admin_only,
			)
		);

		// ── Status ────────────────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_status',
				'permission_callback' => $admin_only,
			)
		);

		// ── Force update check ────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/check-updates',
			array(
				'methods'             => 'POST',
				'callback'            => 'rmb_check_updates',
				'permission_callback' => $admin_only,
			)
		);

		// ── robots.txt ────────────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/robots-txt',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => 'rmb_robots_get',
					'permission_callback' => $admin_only,
				),
				array(
					'methods'             => 'POST',
					'callback'            => 'rmb_robots_set',
					'permission_callback' => $admin_only,
					'args'                => array(
						'content'                  => array(
							'required' => true,
							'type'     => 'string',
						),
						'ensure_sitemap_directive' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						),
						'preferred_sitemap_only'   => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => true,
						),
					),
				),
			)
		);

		// ── AEO/GEO audit data layer ─────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/canonical-urls/preview',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_canonical_urls_preview',
				'permission_callback' => $admin_only,
			)
		);

		register_rest_route(
			'rankrocket-seo/v1',
			'/aeo-geo/readiness',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_aeo_geo_readiness',
				'permission_callback' => $admin_only,
			)
		);

		register_rest_route(
			'rankrocket-seo/v1',
			'/aeo-geo/entity',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_aeo_geo_entity',
				'permission_callback' => $admin_only,
			)
		);

		register_rest_route(
			'rankrocket-seo/v1',
			'/aeo-geo/schema-audit',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_aeo_geo_schema_audit',
				'permission_callback' => $admin_only,
			)
		);

		register_rest_route(
			'rankrocket-seo/v1',
			'/aeo-geo/source-sync',
			array(
				'methods'             => 'GET',
				'callback'            => 'rmb_aeo_geo_source_sync',
				'permission_callback' => $admin_only,
			)
		);

		// ── Legacy migration ──────────────────────────────────────────────────────
		register_rest_route(
			'rankrocket-seo/v1',
			'/migrate-legacy',
			array(
				'methods'             => 'POST',
				'callback'            => 'rmb_migrate_legacy',
				'permission_callback' => $admin_only,
				'args'                => array(
					'post_ids' => array(
						'required' => true,
						'type'     => 'array',
					),
					'dry_run'  => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);
	}
);


// ── SEO Meta Handlers ─────────────────────────────────────────────────────────
/**
 * Handles POST /update — writes SEO meta for a single post.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_update_meta( WP_REST_Request $request ) {
	$post_id    = intval( $request->get_param( 'post_id' ) );
	$url_fields = array( 'og_image', 'canonical', 'twitter_image' );
	$raw_fields = array();

	foreach ( RR_SEO_META_KEYS as $param => $native_key ) {
		$value = $request->get_param( $param );
		if ( null !== $value && '' !== $value ) {
			$raw_fields[ $param ] = $value;
		}
	}

	$validation = rr_validate_seo_fields( $raw_fields, $post_id );
	if ( ! empty( $validation['errors'] ) ) {
		return new WP_Error(
			'validation_failed',
			'Validation failed',
			array(
				'status'   => 422,
				'errors'   => $validation['errors'],
				'warnings' => $validation['warnings'],
			)
		);
	}

	$updated       = array();
	$audit_changes = array();
	$request_id    = rr_request_id( $request );

	foreach ( $raw_fields as $param => $value ) {
		$meta_key  = RR_SEO_META_KEYS[ $param ];
		$before    = rr_get_seo_meta( $post_id, $param );
		$sanitized = in_array( $param, $url_fields, true ) ? esc_url_raw( $value ) : sanitize_text_field( $value );
		update_post_meta( $post_id, $meta_key, $sanitized );
		$updated[ $meta_key ]    = $sanitized;
		$audit_changes[ $param ] = array(
			'before' => $before ? $before : '',
			'after'  => $sanitized,
		);
	}

	// Handle llms_section classification meta (outside RR_SEO_META_KEYS — different validation).
	$section = $request->get_param( 'llms_section' );
	if ( null !== $section ) {
		$before_section = get_post_meta( $post_id, META_LLMS_SECTION, true );
		if ( '' === $section ) {
			delete_post_meta( $post_id, META_LLMS_SECTION );
			$audit_changes['llms_section'] = array(
				'before' => $before_section,
				'after'  => '',
			);
		} else {
			$section_validation = rr_validate_llms_section( sanitize_text_field( $section ) );
			if ( is_wp_error( $section_validation ) ) {
				return $section_validation;
			}
			update_post_meta( $post_id, META_LLMS_SECTION, sanitize_text_field( $section ) );
			$audit_changes['llms_section'] = array(
				'before' => $before_section,
				'after'  => $section,
			);
		}
	}

	wp_cache_delete( $post_id, 'post_meta' );
	clean_post_cache( $post_id );

	if ( ! empty( $audit_changes ) ) {
		rr_audit_log( $post_id, '/update', $audit_changes, $request_id, 'written' );
	}

	return rest_ensure_response(
		array(
			'success'  => true,
			'post_id'  => $post_id,
			'updated'  => $updated,
			'warnings' => $validation['warnings'],
		)
	);
}

/**
 * Handles GET /get/{id} — returns SEO meta for a single post.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_get_meta( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'id' ) );
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
	}

	$meta = array();
	foreach ( RR_SEO_META_KEYS as $field => $native_key ) {
		$meta[ $native_key ] = rr_get_seo_meta( $post_id, $field );
	}
	$meta['rr_seo_score'] = get_post_meta( $post_id, 'rank_math_seo_score', true );

	// Alias the underscore-prefixed canonical/twitter keys so external consumers can
	// reference them without knowing they are stored as hidden post meta.
	$meta['rr_seo_canonical']           = $meta['_rr_seo_canonical'] ?? '';
	$meta['rr_seo_twitter_card']        = $meta['_rr_seo_twitter_card'] ?? '';
	$meta['rr_seo_twitter_title']       = $meta['_rr_seo_twitter_title'] ?? '';
	$meta['rr_seo_twitter_description'] = $meta['_rr_seo_twitter_description'] ?? '';
	$meta['rr_seo_twitter_image']       = $meta['_rr_seo_twitter_image'] ?? '';

	$thumb_id                    = get_post_thumbnail_id( $post_id );
	$meta['_featured_image_url'] = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';

	// llms.txt section classification metadata.
	$stored_section    = get_post_meta( $post_id, META_LLMS_SECTION, true );
	$config            = get_option( RR_LLMS_CONFIG_KEY, get_option( 'rmb_llms_config', array() ) );
	$sections_config   = ( ! empty( $config['sections'] ) && is_array( $config['sections'] ) && ! isset( $config['sections'][0] ) )
		? $config['sections']
		: array();
	$section_warnings  = array();
	$effective_section = $stored_section;
	if ( '' !== $stored_section && ! empty( $sections_config ) && ! isset( $sections_config[ $stored_section ] ) ) {
		// Stale key — report it.
		$effective_section  = '';
		$section_warnings[] = array(
			'code'              => 'stale_section_key',
			'stored_section'    => $stored_section,
			'effective_section' => '',
			'message'           => 'Stored llms_section is not present in current llms section config.',
		);
	}

	return rest_ensure_response(
		array(
			'post_id'                => $post_id,
			'meta'                   => $meta,
			'llms_section'           => $stored_section,
			'effective_llms_section' => $effective_section,
			'llms_warnings'          => $section_warnings,
		)
	);
}

/**
 * Handles POST /meta/bulk-get — returns SEO meta for multiple posts.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_meta_bulk_get( WP_REST_Request $request ) {
	$post_ids = array_map( 'intval', $request->get_param( 'post_ids' ) );

	if ( count( $post_ids ) > RR_BATCH_MAX ) {
		return new WP_Error( 'batch_too_large', 'Batch size exceeds maximum of ' . RR_BATCH_MAX, array( 'status' => 422 ) );
	}

	$results = array();
	foreach ( $post_ids as $pid ) {
		$post = get_post( $pid );
		if ( ! $post ) {
			continue;
		}
		$meta = array();
		foreach ( RR_SEO_META_KEYS as $field => $native_key ) {
			$meta[ $native_key ] = rr_get_seo_meta( $pid, $field );
		}
		$meta['rr_seo_score']               = get_post_meta( $pid, 'rank_math_seo_score', true );
		$meta['rr_seo_canonical']           = $meta['_rr_seo_canonical'] ?? '';
		$meta['rr_seo_twitter_card']        = $meta['_rr_seo_twitter_card'] ?? '';
		$meta['rr_seo_twitter_title']       = $meta['_rr_seo_twitter_title'] ?? '';
		$meta['rr_seo_twitter_description'] = $meta['_rr_seo_twitter_description'] ?? '';
		$meta['rr_seo_twitter_image']       = $meta['_rr_seo_twitter_image'] ?? '';
		$stored_section                     = get_post_meta( $pid, META_LLMS_SECTION, true );
		$results[]            = array(
			'post_id'                => $pid,
			'slug'                   => $post->post_name,
			'title'                  => get_the_title( $post ),
			'meta'                   => $meta,
			'llms_section'           => $stored_section,
			'effective_llms_section' => $stored_section,
		);
	}
	return rest_ensure_response(
		array(
			'count' => count( $results ),
			'pages' => $results,
		)
	);
}

/**
 * Handles POST /meta/bulk-update — writes SEO meta for multiple posts.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_meta_bulk_update( WP_REST_Request $request ) {
	$updates    = $request->get_param( 'updates' );
	$url_fields = array( 'og_image', 'canonical', 'twitter_image' );
	$request_id = rr_request_id( $request );

	if ( count( $updates ) > RR_BATCH_MAX ) {
		return new WP_Error( 'batch_too_large', 'Batch size exceeds maximum of ' . RR_BATCH_MAX, array( 'status' => 422 ) );
	}

	$results = array();
	foreach ( $updates as $upd ) {
		$post_id = intval( $upd['post_id'] ?? 0 );

		$raw_fields = array();
		foreach ( RR_SEO_META_KEYS as $param => $native_key ) {
			if ( isset( $upd[ $param ] ) && '' !== $upd[ $param ] ) {
				$raw_fields[ $param ] = $upd[ $param ];
			}
		}

		$validation = rr_validate_seo_fields( $raw_fields, $post_id ? $post_id : null );
		if ( ! empty( $validation['errors'] ) ) {
			$results[] = array(
				'post_id'  => $post_id,
				'success'  => false,
				'errors'   => $validation['errors'],
				'warnings' => $validation['warnings'],
			);
			continue;
		}

		$updated       = array();
		$audit_changes = array();

		foreach ( $raw_fields as $param => $value ) {
			$meta_key  = RR_SEO_META_KEYS[ $param ];
			$before    = rr_get_seo_meta( $post_id, $param );
			$sanitized = in_array( $param, $url_fields, true ) ? esc_url_raw( $value ) : sanitize_text_field( $value );
			update_post_meta( $post_id, $meta_key, $sanitized );
			$updated[ $meta_key ]    = $value;
			$audit_changes[ $param ] = array(
				'before' => $before ? $before : '',
				'after'  => $sanitized,
			);
		}

		// Handle llms_section per-item if provided.
		$item_section = isset( $upd['llms_section'] ) ? $upd['llms_section'] : null;
		if ( null !== $item_section ) {
			$before_section = get_post_meta( $post_id, META_LLMS_SECTION, true );
			if ( '' === $item_section ) {
				delete_post_meta( $post_id, META_LLMS_SECTION );
				$audit_changes['llms_section'] = array(
					'before' => $before_section,
					'after'  => '',
				);
			} else {
				$sv = rr_validate_llms_section( sanitize_text_field( $item_section ) );
				if ( ! is_wp_error( $sv ) ) {
					update_post_meta( $post_id, META_LLMS_SECTION, sanitize_text_field( $item_section ) );
					$audit_changes['llms_section'] = array(
						'before' => $before_section,
						'after'  => $item_section,
					);
				}
			}
		}

		wp_cache_delete( $post_id, 'post_meta' );
		clean_post_cache( $post_id );

		if ( ! empty( $audit_changes ) ) {
			rr_audit_log( $post_id, '/meta/bulk-update', $audit_changes, $request_id, 'written' );
		}

		$results[] = array(
			'post_id'  => $post_id,
			'success'  => true,
			'updated'  => $updated,
			'warnings' => $validation['warnings'],
		);
	}
	return rest_ensure_response(
		array(
			'count'   => count( $results ),
			'results' => $results,
		)
	);
}


// ── Preview / Dry-run Handler ─────────────────────────────────────────────────
/**
 * Handles POST /preview-update — validates and diffs without writing to DB.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_preview_update( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'post_id' ) );

	$raw_fields = array();
	foreach ( RR_SEO_META_KEYS as $param => $native_key ) {
		$value = $request->get_param( $param );
		if ( null !== $value && '' !== $value ) {
			$raw_fields[ $param ] = $value;
		}
	}

	$validation = rr_validate_seo_fields( $raw_fields, $post_id );

	$changes = array();
	foreach ( $raw_fields as $param => $value ) {
		$current           = rr_get_seo_meta( $post_id, $param );
		$changes[ $param ] = array(
			'before' => $current ? $current : '',
			'after'  => $value,
		);
	}

	return rest_ensure_response(
		array(
			'post_id'  => $post_id,
			'changes'  => $changes,
			'warnings' => $validation['warnings'],
			'errors'   => $validation['errors'],
			'valid'    => empty( $validation['errors'] ),
		)
	);
}


// ── Schema Handlers ───────────────────────────────────────────────────────────
/**
 * Handles GET /schema/{post_id} — returns the stored JSON-LD schema.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_schema_get( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'post_id' ) );
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
	}
	$schema = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
	return rest_ensure_response(
		array(
			'post_id' => $post_id,
			'schema'  => $schema ? $schema : null,
		)
	);
}

/**
 * Handles POST /schema/{post_id} — validates and stores a JSON-LD schema.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_schema_set( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'post_id' ) );
	$dry_run = (bool) $request->get_param( 'dry_run' );
	$schema  = $request->get_param( 'schema' );

	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
	}

	$validation = rr_validate_schema( $schema );
	if ( ! empty( $validation['errors'] ) ) {
		return new WP_Error(
			'validation_failed',
			'Schema validation failed',
			array(
				'status'   => 422,
				'errors'   => $validation['errors'],
				'warnings' => $validation['warnings'],
			)
		);
	}

	$clean_schema  = $validation['schema'];
	$before_schema = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
	$before        = $before_schema ? $before_schema : null;

	if ( ! $dry_run ) {
		update_post_meta( $post_id, RR_SCHEMA_META_KEY, $clean_schema );
		rr_audit_log(
			$post_id,
			'/schema',
			array(
				'schema' => array(
					'before' => $before,
					'after'  => $clean_schema,
				),
			),
			rr_request_id( $request ),
			'written'
		);
	}

	return rest_ensure_response(
		array(
			'post_id'  => $post_id,
			'dry_run'  => $dry_run,
			'valid'    => true,
			'warnings' => $validation['warnings'],
			'schema'   => $clean_schema,
		)
	);
}


// ── Audit Log Handler ─────────────────────────────────────────────────────────
/**
 * Handles GET /log/{post_id} — returns the audit log for a post.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_log_get( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'post_id' ) );
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
	}
	$log = get_post_meta( $post_id, RR_CHANGE_LOG_KEY, true );
	$log = is_array( $log ) ? $log : array();
	return rest_ensure_response(
		array(
			'post_id' => $post_id,
			'count'   => count( $log ),
			'log'     => array_reverse( $log ), // Most recent first.
		)
	);
}


// ── Image ALT Handlers ────────────────────────────────────────────────────────
/**
 * Handles GET /images — returns paginated list of media library images with ALT status.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_images_list( WP_REST_Request $request ) {
	$page     = max( 1, intval( $request->get_param( 'page' ) ?? 1 ) );
	$per_page = min( 100, max( 10, intval( $request->get_param( 'per_page' ) ?? 50 ) ) );

	$attachments = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	$images = array();
	foreach ( $attachments as $att ) {
		$alt      = get_post_meta( $att->ID, '_wp_attachment_image_alt', true );
		$images[] = array(
			'id'       => $att->ID,
			'filename' => basename( get_attached_file( $att->ID ) ),
			'url'      => wp_get_attachment_url( $att->ID ),
			'alt'      => $alt ? $alt : '',
			'title'    => $att->post_title,
			'caption'  => $att->post_excerpt,
			'missing'  => empty( $alt ),
		);
	}

	return rest_ensure_response(
		array(
			'page'              => $page,
			'per_page'          => $per_page,
			'count'             => count( $images ),
			'images'            => $images,
			'missing_alt_count' => count( array_filter( $images, fn( $i ) => $i['missing'] ) ),
		)
	);
}

/**
 * Handles POST /images/{id}/alt — sets the ALT text for a single attachment.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_image_set_alt( WP_REST_Request $request ) {
	$id  = intval( $request->get_param( 'id' ) );
	$alt = sanitize_text_field( $request->get_param( 'alt' ) );

	if ( ! get_post( $id ) ) {
		return new WP_Error( 'not_found', 'Attachment not found', array( 'status' => 404 ) );
	}

	$before = get_post_meta( $id, '_wp_attachment_image_alt', true );
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );

	return rest_ensure_response(
		array(
			'success'  => true,
			'id'       => $id,
			'filename' => basename( get_attached_file( $id ) ),
			'before'   => $before ? $before : '',
			'after'    => $alt,
		)
	);
}

/**
 * Handles POST /images/bulk-alt — sets ALT text for multiple attachments.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
/**
 * Handles GET /images/{id}/alt — returns current ALT text for a single attachment.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_image_get_alt( WP_REST_Request $request ) {
	$id   = intval( $request->get_param( 'id' ) );
	$post = get_post( $id );
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Attachment not found', array( 'status' => 404 ) );
	}
	return rest_ensure_response(
		array(
			'id'       => $id,
			'url'      => wp_get_attachment_url( $id ),
			'filename' => basename( get_attached_file( $id ) ),
			'alt'      => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'    => get_the_title( $post ),
			'caption'  => $post->post_excerpt,
		)
	);
}

/**
 * Handles POST /images/bulk-alt — sets ALT text for multiple attachments.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_images_bulk_alt( WP_REST_Request $request ) {
	$updates = $request->get_param( 'updates' );

	if ( count( $updates ) > RR_BATCH_MAX ) {
		return new WP_Error( 'batch_too_large', 'Batch size exceeds maximum of ' . RR_BATCH_MAX, array( 'status' => 422 ) );
	}

	$results = array();

	foreach ( $updates as $upd ) {
		$id  = intval( $upd['id'] ?? 0 );
		$alt = sanitize_text_field( $upd['alt'] ?? '' );

		if ( ! $id || ! get_post( $id ) ) {
			$results[] = array(
				'id'      => $id,
				'success' => false,
				'error'   => 'Attachment not found',
			);
			continue;
		}

		$before = get_post_meta( $id, '_wp_attachment_image_alt', true );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		$results[] = array(
			'id'       => $id,
			'filename' => basename( get_attached_file( $id ) ),
			'success'  => true,
			'before'   => $before ? $before : '',
			'after'    => $alt,
		);
	}

	return rest_ensure_response(
		array(
			'count'   => count( $results ),
			'results' => $results,
		)
	);
}


// ── robots.txt Handler ────────────────────────────────────────────────────────

/**
 * WordPress robots_txt filter callback.
 *
 * Returns the stored custom content when set; otherwise passes through
 * the default WordPress robots.txt content unchanged.
 * Has no effect when a physical robots.txt file exists at the webroot —
 * in that case the web server serves the file directly.
 *
 * @param string $output  WordPress-generated robots.txt content.
 * @param int    $is_public Site visibility setting (1 = public, 0 = discourage).
 * @return string
 */
function rr_robots_txt_output( string $output, int $is_public ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	$custom = get_option( 'rrseo_robots_txt', '' );
	if ( '' !== $custom ) {
		return $custom;
	}

	// Auto-sync: strip Sitemap: lines pointing to WP core's /wp-sitemap.xml and
	// append the RankRocket sitemap_index.xml directive so robots.txt agrees with
	// the active sitemap. Gated by rrseo_robots_txt_auto_sync (default on).
	$auto_sync = (bool) get_option( 'rrseo_robots_txt_auto_sync', true );
	if ( ! $auto_sync ) {
		return $output;
	}

	$site_url     = rtrim( get_bloginfo( 'url' ), '/' );
	$rr_sitemap   = $site_url . '/sitemap_index.xml';
	$wp_sitemap   = $site_url . '/wp-sitemap.xml';
	$lines        = explode( "\n", str_replace( "\r\n", "\n", $output ) );
	$kept         = array();
	$has_rr_line  = false;

	foreach ( $lines as $line ) {
		$trim = trim( $line );
		if ( 0 === stripos( $trim, 'Sitemap:' ) ) {
			$url_part = trim( substr( $trim, 8 ) );
			if ( strtolower( $url_part ) === strtolower( $wp_sitemap ) ) {
				continue; // Drop core's directive.
			}
			if ( strtolower( $url_part ) === strtolower( $rr_sitemap ) ) {
				$has_rr_line = true;
			}
		}
		$kept[] = $line;
	}

	// Trim trailing blanks before appending.
	while ( ! empty( $kept ) && '' === trim( end( $kept ) ) ) {
		array_pop( $kept );
	}

	if ( ! $has_rr_line ) {
		$kept[] = '';
		$kept[] = 'Sitemap: ' . $rr_sitemap;
	}

	return implode( "\n", $kept ) . "\n";
}

/**
 * Merges per-post robots directives into WordPress core's wp_robots array.
 *
 * Core's wp_robots() callback emits a single <meta name="robots"> tag built from
 * the associative array returned by the wp_robots filter (e.g. max-image-preview
 * is added by wp_robots_max_image_preview_large()). This callback folds the
 * post-level rr_seo_robots value into that array on singular requests so only
 * one robots tag renders.
 *
 * Gated by rrseo_consolidate_wp_robots (default true) so the previous two-tag
 * behaviour remains available for users who opt out.
 *
 * @param array $directives Existing directives keyed by name.
 * @return array
 */
function rr_merge_wp_robots( array $directives ): array {
	if ( ! (bool) get_option( 'rrseo_consolidate_wp_robots', true ) ) {
		return $directives;
	}
	if ( class_exists( 'RankMath' ) ) {
		return $directives;
	}
	if ( ! is_singular() ) {
		return $directives;
	}

	$post_id = get_queried_object_id();
	if ( ! $post_id ) {
		return $directives;
	}

	$robots = rr_get_seo_meta( $post_id, 'robots' );
	if ( ! $robots ) {
		return $directives;
	}

	$values = is_array( $robots ) ? $robots : array_map( 'trim', explode( ',', (string) $robots ) );
	foreach ( $values as $value ) {
		if ( '' === $value ) {
			continue;
		}
		// key=value directives (e.g. max-image-preview:large) preserve their value;
		// boolean directives (index/noindex/follow/nofollow/...) flip a flag.
		if ( false !== strpos( $value, ':' ) ) {
			list( $k, $v ) = array_map( 'trim', explode( ':', $value, 2 ) );
			$directives[ $k ] = $v;
		} else {
			$directives[ $value ] = true;
		}
	}

	// noindex wins over index when both are present.
	if ( ! empty( $directives['noindex'] ) ) {
		unset( $directives['index'] );
	}
	if ( ! empty( $directives['nofollow'] ) ) {
		unset( $directives['follow'] );
	}

	return $directives;
}

/**
 * Handles GET /robots-txt — returns current robots.txt content and status.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_robots_get( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$custom        = get_option( 'rrseo_robots_txt', '' );
	$physical_path = ABSPATH . 'robots.txt';
	clearstatcache( true, $physical_path );
	$has_file = file_exists( $physical_path );
	// Plugin manages the physical file when custom content is stored and the file exists.
	$plugin_managed = '' !== $custom && $has_file;

	$warning = null;
	if ( $has_file && ! $plugin_managed ) {
		$warning = 'A physical robots.txt exists at the webroot but no custom content is stored. '
			. 'The web server serves it directly. Save content via POST /robots-txt to take ownership.';
	}

	return rest_ensure_response(
		array(
			'content'               => $custom,
			'source'                => '' !== $custom ? 'custom' : 'wordpress_default',
			'physical_file_exists'  => $has_file,
			'physical_file_managed' => $plugin_managed,
			'warning'               => $warning,
		)
	);
}

/**
 * Handles POST /robots-txt — writes a custom robots.txt body.
 *
 * When ensure_sitemap_directive is true, normalises Sitemap: directives before
 * storing. preferred_sitemap_only (default true) replaces all existing Sitemap:
 * lines with the RankRocket preferred directive; when false, existing Sitemap:
 * lines are preserved and the preferred directive is appended if absent.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_robots_set( WP_REST_Request $request ): WP_REST_Response {
	$content          = sanitize_textarea_field( $request->get_param( 'content' ) );
	$ensure_directive = (bool) $request->get_param( 'ensure_sitemap_directive' );
	$preferred_only   = null !== $request->get_param( 'preferred_sitemap_only' )
		? (bool) $request->get_param( 'preferred_sitemap_only' )
		: true;
	$physical_path    = ABSPATH . 'robots.txt';

	if ( $ensure_directive && '' !== $content ) {
		$preferred_url = rtrim( get_bloginfo( 'url' ), '/' ) . '/sitemap_index.xml';
		$content       = rmb_robots_inject_sitemap_directive( $content, $preferred_url, $preferred_only );
	}

	update_option( 'rrseo_robots_txt', $content );
	// Bust WP object cache so subsequent GET requests read the fresh DB value, not a stale cache entry.
	wp_cache_delete( 'rrseo_robots_txt', 'options' );
	wp_cache_delete( 'alloptions', 'options' );

	// Write or remove the physical robots.txt so changes persist if the plugin is ever deactivated.
	// The web server serves a physical file directly — no WordPress involved.
	$physical_managed = false;
	$physical_error   = null;

	if ( '' !== $content ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $physical_path, $content );
		if ( false === $bytes ) {
			$physical_error = 'Could not write robots.txt to the webroot — check directory permissions. Content saved to database only.';
		} else {
			$physical_managed = true;
		}
	} elseif ( file_exists( $physical_path ) ) {
		// Content cleared — remove physical file so WP virtual robots.txt takes over.
		wp_delete_file( $physical_path );
	}

	clearstatcache( true, $physical_path );
	$has_file          = file_exists( $physical_path );
	$missing_directive = '' !== $content && false === stripos( $content, 'Sitemap:' );
	$response_warnings = array();

	if ( $missing_directive ) {
		$response_warnings[] = array(
			'code'    => 'missing_sitemap_directive',
			'message' => 'robots.txt content has no Sitemap: directive. Pass ensure_sitemap_directive:true to auto-add it.',
		);
	}
	if ( null !== $physical_error ) {
		$response_warnings[] = array(
			'code'    => 'physical_file_write_failed',
			'message' => $physical_error,
		);
	}

	return rest_ensure_response(
		array(
			'success'               => true,
			'content'               => $content,
			'source'                => '' !== $content ? 'custom' : 'wordpress_default',
			'physical_file_exists'  => $has_file,
			'physical_file_managed' => $physical_managed,
			'warnings'              => $response_warnings,
		)
	);
}

/**
 * Normalises Sitemap: directives in a robots.txt body.
 *
 * Implements the v4 §15.7 five-step procedure:
 * 1. Parse all existing Sitemap: lines case-insensitively.
 * 2. Preserve non-Sitemap: lines exactly.
 * 3. If preferred_only, remove all existing Sitemap: lines and append preferred once.
 * 4. If not preferred_only, keep existing lines and append preferred if absent.
 * 5. Deduplicate identical Sitemap: lines.
 *
 * @param string $content       Current robots.txt body.
 * @param string $preferred_url Full URL of the preferred sitemap (e.g. sitemap_index.xml).
 * @param bool   $preferred_only Replace all existing Sitemap: lines when true.
 * @return string Normalised robots.txt body.
 */
function rmb_robots_inject_sitemap_directive( string $content, string $preferred_url, bool $preferred_only ): string {
	$preferred_line = 'Sitemap: ' . $preferred_url;
	$lines          = explode( "\n", str_replace( "\r\n", "\n", $content ) );
	$non_sitemap    = array();
	$existing_maps  = array();

	foreach ( $lines as $line ) {
		if ( 0 === stripos( trim( $line ), 'Sitemap:' ) ) {
			$existing_maps[] = trim( $line );
		} else {
			$non_sitemap[] = $line;
		}
	}

	// Remove trailing blank lines from non-sitemap block to keep output tidy.
	while ( ! empty( $non_sitemap ) && '' === trim( end( $non_sitemap ) ) ) {
		array_pop( $non_sitemap );
	}

	if ( $preferred_only ) {
		$sitemap_lines = array( $preferred_line );
	} else {
		$already_present = false;
		foreach ( $existing_maps as $m ) {
			if ( strtolower( $m ) === strtolower( $preferred_line ) ) {
				$already_present = true;
			}
		}
		$sitemap_lines = $existing_maps;
		if ( ! $already_present ) {
			$sitemap_lines[] = $preferred_line;
		}
		// Deduplicate (case-insensitive).
		$seen    = array();
		$deduped = array();
		foreach ( $sitemap_lines as $sl ) {
			$key = strtolower( $sl );
			if ( ! in_array( $key, $seen, true ) ) {
				$seen[]    = $key;
				$deduped[] = $sl;
			}
		}
		$sitemap_lines = $deduped;
	}

	$result_lines = array_merge( $non_sitemap, array( '' ), $sitemap_lines );
	return implode( "\n", $result_lines );
}


// ── llms.txt Handlers ─────────────────────────────────────────────────────────
/**
 * Handles GET /llms — returns the current llms.txt configuration.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_llms_get_config( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	// Read the new key first; fall back to the legacy key for migration compat.
	$config = get_option( RR_LLMS_CONFIG_KEY, get_option( 'rmb_llms_config', array() ) );
	return rest_ensure_response(
		array(
			'url'    => rtrim( get_bloginfo( 'url' ), '/' ) . '/llms.txt',
			'config' => $config,
		)
	);
}

/**
 * Handles POST /llms — updates the llms.txt configuration.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_llms_set_config( WP_REST_Request $request ) {
	// Read from the new key first; migrate legacy config if new key is absent.
	$config = get_option( RR_LLMS_CONFIG_KEY, get_option( 'rmb_llms_config', array() ) );

	// Simple scalar / text fields.
	$scalar_fields = array( 'intro', 'max_description_chars', 'schema_source_post_id' );
	foreach ( $scalar_fields as $field ) {
		$val = $request->get_param( $field );
		if ( null !== $val ) {
			$config[ $field ] = 'intro' === $field ? sanitize_textarea_field( $val ) : (int) $val;
		}
	}

	// Boolean flags.
	$bool_fields = array( 'include_sitemaps', 'include_lastmod', 'exclude_noindex', 'exclude_utility_pages', 'group_by_intent' );
	foreach ( $bool_fields as $field ) {
		$val = $request->get_param( $field );
		if ( null !== $val ) {
			$config[ $field ] = (bool) $val;
		}
	}

	// sections (object form — URL classifier config).
	$sections = $request->get_param( 'sections' );
	if ( null !== $sections && is_array( $sections ) && ! isset( $sections[0] ) ) {
		$config['sections'] = $sections;
	}

	// custom_sections (array of {heading, items} text blocks — legacy).
	$custom_sections = $request->get_param( 'custom_sections' );
	if ( null !== $custom_sections && is_array( $custom_sections ) ) {
		$config['custom_sections'] = $custom_sections;
	}

	// exclude_patterns (array of URL path strings).
	$patterns = $request->get_param( 'exclude_patterns' );
	if ( null !== $patterns && is_array( $patterns ) ) {
		$config['exclude_patterns'] = array_map( 'sanitize_text_field', $patterns );
	}

	// business_facts (object).
	$facts = $request->get_param( 'business_facts' );
	if ( null !== $facts && is_array( $facts ) ) {
		$config['business_facts'] = $facts;
	}

	// Invalidate the canonical counts transient after config change.
	rr_invalidate_canonical_cache();

	// Write to the new canonical key; the legacy key is no longer updated.
	update_option( RR_LLMS_CONFIG_KEY, $config );

	return rest_ensure_response(
		array(
			'success' => true,
			'url'     => rtrim( get_bloginfo( 'url' ), '/' ) . '/llms.txt',
			'config'  => $config,
		)
	);
}


// ── Sitemap Preview Handler ───────────────────────────────────────────────────
/**
 * Handles GET /sitemap/preview — Canonical URL Set preview with inclusion/exclusion audit.
 *
 * Now acts as the authoritative Canonical URL Set preview endpoint: uses the same
 * rr_get_canonical_url_set() as all sitemap and llms.txt generators, guaranteeing
 * the preview reflects exactly what will be served.
 *
 * Also fixes a prior bug where lastmod used post_modified (local time) instead of
 * post_modified_gmt (UTC), and reported the legacy sitemap URL instead of the index.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_sitemap_preview( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$front_page = (int) get_option( 'page_on_front' );
	$site_url   = rtrim( get_bloginfo( 'url' ), '/' );

	$canonical = rr_get_canonical_url_set(
		array( 'post_types' => array( 'post', 'page' ) )
	);

	$entries = array();
	foreach ( $canonical['urls'] as $url_entry ) {
		if ( 'page' === $url_entry['post_type'] ) {
			$priority = ( $url_entry['post_id'] === $front_page ) ? '1.0' : '0.8';
		} else {
			$priority = '0.6';
		}
		$entries[] = array(
			'id'               => $url_entry['post_id'],
			'post_id'          => $url_entry['post_id'],
			'type'             => $url_entry['post_type'],
			'loc'              => $url_entry['url'],
			'lastmod'          => $url_entry['lastmod'],
			'priority'         => $priority,
			'noindex'          => false,
			'included'         => true,
			'exclusion_reason' => null,
			'warnings'         => $url_entry['warnings'],
		);
	}

	$excluded_entries = array();
	foreach ( $canonical['excluded'] as $excl ) {
		$excluded_entries[] = array(
			'id'               => $excl['post_id'],
			'post_id'          => $excl['post_id'],
			'type'             => $excl['post_type'],
			'loc'              => $excl['url'],
			'included'         => false,
			'exclusion_reason' => $excl['reason'],
		);
	}

	return rest_ensure_response(
		array(
			'sitemap_url'    => $site_url . '/sitemap_index.xml',
			'total'          => count( $entries ) + count( $excluded_entries ),
			'included_count' => count( $entries ),
			'excluded_count' => count( $excluded_entries ),
			'entries'        => array_values( $entries ),
			'excluded_urls'  => array_values( $excluded_entries ),
			'warnings'       => array_values( $canonical['warnings'] ),
		)
	);
}


// ── Snippet Handlers ──────────────────────────────────────────────────────────
/**
 * Handles GET /snippets — returns all managed snippets.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_snippets_list( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$snippets = get_option( RMB_SNIPPETS_KEY, array() );
	return rest_ensure_response(
		array(
			'count'    => count( $snippets ),
			'snippets' => array_values( $snippets ),
		)
	);
}

/**
 * Handles POST /snippets — creates a new managed snippet.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_snippets_create( WP_REST_Request $request ) {
	$snippets = get_option( RMB_SNIPPETS_KEY, array() );

	$title = sanitize_text_field( $request->get_param( 'title' ) );
	$id    = sanitize_title( $title );

	if ( isset( $snippets[ $id ] ) ) {
		$id = $id . '_' . time();
	}

	$snippet = array(
		'id'         => $id,
		'title'      => $title,
		'content'    => $request->get_param( 'content' ),
		'location'   => sanitize_text_field( $request->get_param( 'location' ) ),
		'display_on' => sanitize_text_field( $request->get_param( 'display_on' ) ),
		'status'     => sanitize_text_field( $request->get_param( 'status' ) ),
		'created_at' => current_time( 'mysql' ),
		'updated_at' => current_time( 'mysql' ),
	);

	$snippets[ $id ] = $snippet;
	update_option( RMB_SNIPPETS_KEY, $snippets );

	return rest_ensure_response(
		array(
			'success' => true,
			'snippet' => $snippet,
		)
	);
}

/**
 * Handles POST /snippets/{id} — updates an existing snippet.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_snippets_update( WP_REST_Request $request ) {
	$snippets = get_option( RMB_SNIPPETS_KEY, array() );
	$id       = $request->get_param( 'id' );

	if ( ! isset( $snippets[ $id ] ) ) {
		return new WP_Error( 'not_found', "Snippet '{$id}' not found", array( 'status' => 404 ) );
	}

	foreach ( array( 'title', 'location', 'display_on', 'status' ) as $field ) {
		$val = $request->get_param( $field );
		if ( null !== $val ) {
			$snippets[ $id ][ $field ] = sanitize_text_field( $val );
		}
	}

	$content = $request->get_param( 'content' );
	if ( null !== $content ) {
		$snippets[ $id ]['content'] = $content;
	}

	$snippets[ $id ]['updated_at'] = current_time( 'mysql' );

	update_option( RMB_SNIPPETS_KEY, $snippets );
	return rest_ensure_response(
		array(
			'success' => true,
			'snippet' => $snippets[ $id ],
		)
	);
}

/**
 * Handles POST /snippets/replace-all — atomically replaces the entire snippet store.
 *
 * @deprecated 2.3.1 Use per-snippet CRUD endpoints. Removal target: v3.0.0.
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_snippets_replace_all( WP_REST_Request $request ) {
	// Destructive: replaces the entire snippet store atomically.
	// Caller must pass {"confirm": true}. Prefer per-snippet create/update/delete
	// for routine operations; reserve this endpoint for full sync scenarios.
	if ( true !== $request->get_param( 'confirm' ) ) {
		return new WP_Error(
			'confirmation_required',
			'replace-all overwrites the entire snippet store. Send {"confirm": true} to proceed. '
			. 'For routine changes, use the per-snippet POST /snippets, POST /snippets/{id}, or DELETE /snippets/{id} endpoints.',
			array( 'status' => 400 )
		);
	}

	$incoming = $request->get_param( 'snippets' );
	if ( ! is_array( $incoming ) ) {
		return new WP_Error( 'invalid_data', 'snippets must be an array', array( 'status' => 400 ) );
	}

	$before      = get_option( RMB_SNIPPETS_KEY, array() );
	$clean_store = array();

	foreach ( $incoming as $snippet ) {
		$id = sanitize_title( $snippet['title'] ?? '' );
		if ( ! $id ) {
			continue;
		}
		if ( ! empty( $snippet['id'] ) ) {
			$id = sanitize_text_field( $snippet['id'] );
		}
		$clean_store[ $id ] = array(
			'id'         => $id,
			'title'      => sanitize_text_field( $snippet['title'] ?? '' ),
			'content'    => $snippet['content'] ?? '',
			'location'   => sanitize_text_field( $snippet['location'] ?? 'footer' ),
			'display_on' => sanitize_text_field( $snippet['display_on'] ?? 'entire_website' ),
			'status'     => sanitize_text_field( $snippet['status'] ?? 'active' ),
			'created_at' => sanitize_text_field( $snippet['created_at'] ?? current_time( 'mysql' ) ),
			'updated_at' => current_time( 'mysql' ),
		);
	}

	update_option( RMB_SNIPPETS_KEY, $clean_store );

	$deprecation_notice = 'This endpoint will be removed in v3.0.0.'
		. ' Use POST /snippets, POST /snippets/{id}, or DELETE /snippets/{id} for routine changes.';

	return rest_ensure_response(
		array(
			'success'       => true,
			'count'         => count( $clean_store ),
			'ids'           => array_keys( $clean_store ),
			'removed_count' => count( array_diff_key( $before, $clean_store ) ),
			'added_count'   => count( array_diff_key( $clean_store, $before ) ),
			'deprecated'    => $deprecation_notice,
		)
	);
}

/**
 * Handles DELETE /snippets/{id} — removes a snippet.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_snippets_delete( WP_REST_Request $request ) {
	$snippets = get_option( RMB_SNIPPETS_KEY, array() );
	$id       = $request->get_param( 'id' );

	if ( ! isset( $snippets[ $id ] ) ) {
		return new WP_Error( 'not_found', "Snippet '{$id}' not found", array( 'status' => 404 ) );
	}

	$deleted = $snippets[ $id ];
	unset( $snippets[ $id ] );
	update_option( RMB_SNIPPETS_KEY, $snippets );

	return rest_ensure_response(
		array(
			'success'    => true,
			'deleted_id' => $id,
			'deleted'    => $deleted,
		)
	);
}


// ── Cache Purge ───────────────────────────────────────────────────────────────
/**
 * Handles POST /cache/purge — clears all known cache layers.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_cache_purge( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$purged = array();
	$errors = array();

	if ( class_exists( '\LiteSpeed\Purge' ) ) {
		do_action( 'litespeed_purge_all' );
		$purged[] = 'LiteSpeed';
	}

	if ( class_exists( 'Breeze_Admin' ) || function_exists( 'breeze_flush_cache' ) ) {
		do_action( 'breeze_clear_all_cache' );
		$purged[] = 'Breeze';
	}

	$site_url        = get_site_url();
	$host            = wp_parse_url( $site_url, PHP_URL_HOST );
	$varnish_targets = array( 'http://localhost/.*', 'http://127.0.0.1/.*' );
	foreach ( $varnish_targets as $target ) {
		$response = wp_remote_request(
			$target,
			array(
				'method'    => 'PURGE',
				'timeout'   => 5,
				'headers'   => array(
					'Host'           => $host,
					'X-Purge-Method' => 'regex',
				),
				'sslverify' => false,
			)
		);
		if ( ! is_wp_error( $response ) ) {
			$code = wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 200, 201, 204, 404 ), true ) ) {
				if ( ! in_array( 'Varnish', $purged, true ) ) {
					$purged[] = 'Varnish';
				}
				break;
			} else {
				$errors[] = 'Varnish HTTP ' . $code;
			}
		} else {
			$errors[] = 'Varnish: ' . $response->get_error_message();
		}
	}

	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
		$purged[] = 'SiteGround'; }
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
		$purged[] = 'WP Rocket';  }
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
		$purged[] = 'W3TC';       }

	$msg_parts = array();
	if ( ! empty( $purged ) ) {
		$msg_parts[] = 'Purged: ' . implode( ', ', $purged );
	}
	if ( ! empty( $errors ) ) {
		$msg_parts[] = 'Errors: ' . implode( '; ', $errors );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'purged'  => $purged,
			'errors'  => $errors,
			'message' => empty( $msg_parts ) ? 'No supported cache plugin detected' : implode( ' | ', $msg_parts ),
		)
	);
}


// ── Status ────────────────────────────────────────────────────────────────────
/**
 * Handles GET /status — returns plugin health and configuration summary.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_status( WP_REST_Request $request ) {
	$snippets    = get_option( RMB_SNIPPETS_KEY, array() );
	$rankmath_on = class_exists( 'RankMath' );
	$site_url    = rtrim( get_bloginfo( 'url' ), '/' );
	$robots_path = ABSPATH . 'robots.txt';
	clearstatcache( true, $robots_path );
	$physical_robots_file = file_exists( $robots_path );
	$robots_managed       = '' !== (string) get_option( 'rrseo_robots_txt', '' ) && $physical_robots_file;

	$status_warnings = array();
	if ( $physical_robots_file && ! $robots_managed ) {
		$status_warnings[] = array(
			'code'    => 'physical_robots_txt_bypass',
			'message' => 'A physical robots.txt file exists and may bypass RankRocket robots.txt output.',
		);
	}

	$response = array(
		'plugin'                     => 'RankRocket SEO Control Layer',
		'version'                    => RMB_VERSION,
		'namespace'                  => 'rankrocket-seo/v1',
		'rankmath_active'            => $rankmath_on,
		'sitemap_index_url'          => $site_url . '/sitemap_index.xml',
		'legacy_sitemap_url'         => $site_url . '/rmb-sitemap.xml',
		'llms_url'                   => $site_url . '/llms.txt',
		'robots_txt_url'             => $site_url . '/robots.txt',
		'physical_robots_txt_exists' => $physical_robots_file,
		'robots_txt_auto_sync'       => (bool) get_option( 'rrseo_robots_txt_auto_sync', true ),
		'consolidate_wp_robots'      => (bool) get_option( 'rrseo_consolidate_wp_robots', true ),
		'snippet_count'              => count( $snippets ),
		'snippet_ids'                => array_keys( $snippets ),
		'update_url'                 => RMB_UPDATE_URL,
		'php_version'                => PHP_VERSION,
		'wp_version'                 => get_bloginfo( 'version' ),
		'allowed_post_types'         => apply_filters( 'rrseo_allowed_post_types', RR_ALLOWED_POST_TYPES ),
		'allowed_schema_types'       => apply_filters( 'rrseo_allowed_schema_types', RR_ALLOWED_SCHEMA_TYPES ),
		'warnings'                   => $status_warnings,
	);

	// Optional canonical URL counts — transient-cached; computed on demand only.
	if ( $request->get_param( 'include_counts' ) ) {
		$counts = get_transient( 'rrseo_canonical_counts' );
		if ( false === $counts ) {
			$canonical = rr_get_canonical_url_set();
			$counts    = array(
				'canonical_url_count' => count( $canonical['urls'] ),
				'excluded_url_count'  => count( $canonical['excluded'] ),
				'llms_url_count'      => count( $canonical['urls'] ),
			);
			set_transient( 'rrseo_canonical_counts', $counts, 12 * HOUR_IN_SECONDS );
		}
		$response = array_merge( $response, $counts );

		if ( $counts['canonical_url_count'] !== $counts['llms_url_count'] ) {
			$response['warnings'][] = array(
				'code'    => 'canonical_llms_mismatch',
				'message' => 'Canonical URL count and llms URL count differ.',
			);
		}
	}

	return rest_ensure_response( $response );
}

/**
 * Handles POST /check-updates — clears the WordPress and PUC update-check caches.
 *
 * WordPress caches the update-check result in the update_plugins site transient for
 * 12 hours. PUC stores its own last-check timestamp in a site option. Both must be
 * cleared before WordPress will fetch the manifest and detect new versions.
 *
 * This endpoint is the server-side equivalent of clicking "Check Again" on the
 * WordPress Dashboard > Updates page, with the addition of clearing PUC's own state.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_check_updates( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	// Clear PUC's cached state so the next check fetches a fresh manifest.
	// We do NOT touch update_plugins site transient — that would trigger
	// WordPress.org communication for all plugins, which is wrong for a private plugin.
	delete_site_option( 'external_updates-rankmath-rest-bridge' );

	// Trigger an immediate fetch of our private GitHub manifest via PUC.
	// This contacts only our own manifest URL, never WordPress.org.
	$message = 'You are running the latest version.';
	if ( isset( $GLOBALS['rrseo_puc_checker'] ) ) {
		$update = $GLOBALS['rrseo_puc_checker']->checkForUpdates();
		if ( $update && ! empty( $update->version ) ) {
			$message = 'Version ' . sanitize_text_field( $update->version ) . ' is available. Visit Dashboard > Updates to install.';
		}
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'message' => $message,
		)
	);
}


// ── Legacy Migration Handler ──────────────────────────────────────────────────
/**
 * Handles POST /migrate-legacy — copies rank_math_* meta into rr_seo_* keys.
 *
 * Skips fields where the native key is already populated. Supports dry_run:true
 * to preview migrations without writing. All writes are recorded in the audit log.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_migrate_legacy( WP_REST_Request $request ) {
	$post_ids   = array_map( 'intval', (array) $request->get_param( 'post_ids' ) );
	$dry_run    = (bool) $request->get_param( 'dry_run' );
	$request_id = rr_request_id( $request );

	if ( count( $post_ids ) > RR_BATCH_MAX ) {
		return new WP_Error(
			'batch_too_large',
			'Batch size exceeds maximum of ' . RR_BATCH_MAX,
			array( 'status' => 422 )
		);
	}

	$results = array();

	foreach ( $post_ids as $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			$results[] = array(
				'post_id'  => $post_id,
				'status'   => 'skipped',
				'reason'   => 'post not found',
				'migrated' => array(),
				'skipped'  => array(),
			);
			continue;
		}

		$allowed_types = apply_filters( 'rrseo_allowed_post_types', RR_ALLOWED_POST_TYPES );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			$results[] = array(
				'post_id'  => $post_id,
				'status'   => 'skipped',
				'reason'   => "post type '{$post->post_type}' not in allowed list",
				'migrated' => array(),
				'skipped'  => array(),
			);
			continue;
		}

		$migrated       = array();
		$skipped_fields = array();
		$audit_changes  = array();

		foreach ( RR_SEO_META_KEYS as $field => $native_key ) {
			$legacy_key   = RR_SEO_LEGACY_META_KEYS[ $field ];
			$native_value = get_post_meta( $post_id, $native_key, true );
			$legacy_value = get_post_meta( $post_id, $legacy_key, true );

			if ( '' !== $native_value && false !== $native_value ) {
				$skipped_fields[ $field ] = array(
					'reason' => 'native key already set',
					'value'  => $native_value,
				);
				continue;
			}

			if ( '' === $legacy_value || false === $legacy_value ) {
				$skipped_fields[ $field ] = array( 'reason' => 'no legacy value found' );
				continue;
			}

			if ( ! $dry_run ) {
				update_post_meta( $post_id, $native_key, $legacy_value );
			}

			$migrated[ $field ]      = array(
				'from_key' => $legacy_key,
				'value'    => $legacy_value,
			);
			$audit_changes[ $field ] = array(
				'before' => '',
				'after'  => $legacy_value,
			);
		}

		if ( ! $dry_run && ! empty( $audit_changes ) ) {
			rr_audit_log( $post_id, '/migrate-legacy', $audit_changes, $request_id, 'migrated' );
			wp_cache_delete( $post_id, 'post_meta' );
			clean_post_cache( $post_id );
		}

		$post_status = 'skipped';
		if ( ! empty( $migrated ) ) {
			$post_status = $dry_run ? 'would_migrate' : 'migrated';
		}

		$results[] = array(
			'post_id'  => $post_id,
			'status'   => $post_status,
			'migrated' => $migrated,
			'skipped'  => $skipped_fields,
		);
	}

	$migrated_count      = count( array_filter( $results, fn( $r ) => 'migrated' === $r['status'] ) );
	$would_migrate_count = count( array_filter( $results, fn( $r ) => 'would_migrate' === $r['status'] ) );
	$skipped_count       = count( array_filter( $results, fn( $r ) => 'skipped' === $r['status'] ) );

	return rest_ensure_response(
		array(
			'dry_run'        => $dry_run,
			'post_count'     => count( $results ),
			'migrated_count' => $dry_run ? $would_migrate_count : $migrated_count,
			'skipped_count'  => $skipped_count,
			'results'        => $results,
		)
	);
}


// ── Self-Update ───────────────────────────────────────────────────────────────
// POST /wp-json/rankrocket-seo/v1/self-update — zip_url defaults to the GitHub manifest.
add_action(
	'rest_api_init',
	function () {
		$admin_only = function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			'rankrocket-seo/v1',
			'/self-update',
			array(
				'methods'             => 'POST',
				'callback'            => 'rmb_self_update',
				'permission_callback' => $admin_only,
				'args'                => array(
					'zip_url' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}
);

/**
 * Handles POST /self-update — downloads and installs a new plugin zip.
 *
 * Uses the download_url from update-manifest.json when no explicit zip_url is provided.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_self_update( WP_REST_Request $request ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$zip_url = $request->get_param( 'zip_url' );
	if ( ! $zip_url ) {
		$manifest = wp_remote_get( RMB_UPDATE_URL, array( 'timeout' => 15 ) );
		if ( is_wp_error( $manifest ) ) {
			return new WP_Error( 'manifest_fetch_failed', $manifest->get_error_message(), array( 'status' => 500 ) );
		}
		$manifest_data = json_decode( wp_remote_retrieve_body( $manifest ), true );
		$zip_url       = $manifest_data['download_url'] ?? null;
		$remote_ver    = $manifest_data['version'] ?? 'unknown';
	} else {
		$remote_ver = 'provided';
	}

	if ( ! $zip_url ) {
		return new WP_Error( 'no_zip_url', 'Could not determine zip URL from manifest', array( 'status' => 500 ) );
	}

	$current_ver = RMB_VERSION;

	$plugins_dir = WP_PLUGIN_DIR;
	$old_copies  = array( 'rankmath-rest-bridge', 'rankrocket-seo' );
	foreach ( $old_copies as $folder ) {
		$path            = "{$plugins_dir}/{$folder}";
		$plugin_dir_path = WP_PLUGIN_DIR . '/' . dirname( RMB_PLUGIN_FILE );
		if ( is_dir( $path ) && $plugin_dir_path !== $path ) {
			$active = WP_PLUGIN_DIR . '/' . plugin_basename( RMB_PLUGIN_FILE );
			if ( realpath( $path ) !== realpath( dirname( $active ) ) ) {
				WP_Filesystem();
				global $wp_filesystem;
				if ( $wp_filesystem ) {
					$wp_filesystem->delete( $path, true );
				}
			}
		}
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->install( esc_url_raw( $zip_url ), array( 'overwrite_package' => true ) );

	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'upgrade_failed', $result->get_error_message(), array( 'status' => 500 ) );
	}

	if ( false === $result ) {
		return new WP_Error( 'upgrade_failed', 'Upgrader returned false — check filesystem permissions', array( 'status' => 500 ) );
	}

	// GitHub repo folder name is the active disk slug.
	$plugin_file = 'rankmath-rest-bridge/rankmath-rest-bridge.php';
	if ( ! is_plugin_active( $plugin_file ) ) {
		activate_plugin( $plugin_file );
	}

	return rest_ensure_response(
		array(
			'success'      => true,
			'from_version' => $current_ver,
			'to_version'   => $remote_ver,
			'zip_url'      => $zip_url,
			'message'      => "Updated from {$current_ver} to {$remote_ver}. Plugin re-activated.",
		)
	);
}
