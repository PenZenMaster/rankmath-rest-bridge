<?php
/**
 * Plugin Name:  RankRocket SEO Control Layer
 * Description:  Native SEO control layer for the RankRocket remediation pipeline.
 *               Manages title/meta, schema injection, image ALT text, llms.txt,
 *               XML sitemap, cache purge, and self-updates. Reads legacy rank_math_*
 *               post-meta as a migration fallback; RankMath is not required.
 * Version:      2.5.0
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

define( 'RMB_VERSION', '2.5.0' );
define( 'RMB_PLUGIN_FILE', __FILE__ );
define( 'RMB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RMB_SNIPPETS_KEY', 'rmb_managed_snippets' );
define( 'RMB_UPDATE_URL', 'https://raw.githubusercontent.com/PenZenMaster/rankmath-rest-bridge/main/update-manifest.json' );

// Native post-meta keys written by this plugin.
define(
	'RR_SEO_META_KEYS',
	array(
		'title'          => 'rr_seo_title',
		'description'    => 'rr_seo_description',
		'focus_keyword'  => 'rr_seo_focus_keyword',
		'robots'         => 'rr_seo_robots',
		'og_title'       => 'rr_seo_og_title',
		'og_description' => 'rr_seo_og_description',
		'og_image'       => 'rr_seo_og_image',
	)
);

// Legacy RankMath keys — read-only migration fallback when native key is absent.
define(
	'RR_SEO_LEGACY_META_KEYS',
	array(
		'title'          => 'rank_math_title',
		'description'    => 'rank_math_description',
		'focus_keyword'  => 'rank_math_focus_keyword',
		'robots'         => 'rank_math_robots',
		'og_title'       => 'rank_math_og_title',
		'og_description' => 'rank_math_og_description',
		'og_image'       => 'rank_math_og_image',
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


// ── Admin UI (loaded only in the WordPress admin; zero front-end cost) ─────────
if ( is_admin() ) {
	require_once RMB_PLUGIN_DIR . 'includes/class-rrseo-admin.php';
	require_once RMB_PLUGIN_DIR . 'includes/class-rrseo-metabox.php';
	new RRSEO_Admin();
	new RRSEO_MetaBox();
}


// ── Auto-update via plugin-update-checker ─────────────────────────────────────
add_action(
	'init',
	function () {
		$puc_loader = RMB_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( file_exists( $puc_loader ) ) {
			require_once $puc_loader;
			$checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				RMB_UPDATE_URL,
				RMB_PLUGIN_FILE,
				'rankmath-rest-bridge' // GitHub repo slug; must match repo folder name.
			);
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
			echo "\n" . wp_kses_post( $snippet['content'] ) . "\n";
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
		if ( $robots && ! empty( $robots ) ) {
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

	$site_name = get_bloginfo( 'name' );
	$tagline   = get_bloginfo( 'description' );
	$stored    = get_option( 'rmb_llms_config', array() );

	$lines   = array();
	$lines[] = "# {$site_name}";
	if ( $tagline ) {
		$lines[] = "> {$tagline}";
	}
	$lines[] = '';

	if ( ! empty( $stored['intro'] ) ) {
		$lines[] = $stored['intro'];
		$lines[] = '';
	}

	$pages = get_pages(
		array(
			'post_status' => 'publish',
			'sort_column' => 'menu_order',
		)
	);
	if ( $pages ) {
		$lines[] = '## Pages';
		foreach ( $pages as $page ) {
			$noindex = rr_get_seo_meta( $page->ID, 'robots' );
			if ( $noindex && ( ( is_array( $noindex ) && in_array( 'noindex', $noindex, true ) ) || false !== strpos( (string) $noindex, 'noindex' ) ) ) {
				continue;
			}
			$desc    = rr_get_seo_meta( $page->ID, 'description' );
			$desc    = $desc ? rmb_resolve_tokens( (string) $desc, $page->ID ) : '';
			$lines[] = '- [' . get_the_title( $page ) . '](' . get_permalink( $page->ID ) . ')' . ( $desc ? ': ' . $desc : '' );
		}
		$lines[] = '';
	}

	$posts = get_posts(
		array(
			'numberposts' => 10,
			'post_status' => 'publish',
		)
	);
	if ( $posts ) {
		$lines[] = '## Blog Posts';
		foreach ( $posts as $post ) {
			$noindex = rr_get_seo_meta( $post->ID, 'robots' );
			if ( $noindex && ( ( is_array( $noindex ) && in_array( 'noindex', $noindex, true ) ) || false !== strpos( (string) $noindex, 'noindex' ) ) ) {
				continue;
			}
			$desc        = rr_get_seo_meta( $post->ID, 'description' );
			$raw_excerpt = $post->post_excerpt ? $post->post_excerpt : $post->post_content;
			$desc        = $desc ? rmb_resolve_tokens( (string) $desc, $post->ID ) : wp_trim_words( $raw_excerpt, 20 );
			$lines[]     = '- [' . get_the_title( $post ) . '](' . get_permalink( $post->ID ) . ')' . ( $desc ? ': ' . $desc : '' );
		}
		$lines[] = '';
	}

	if ( ! empty( $stored['sections'] ) && is_array( $stored['sections'] ) ) {
		foreach ( $stored['sections'] as $section ) {
			$lines[] = '## ' . ( $section['heading'] ?? 'More' );
			foreach ( ( $section['items'] ?? array() ) as $item ) {
				$lines[] = '- ' . $item;
			}
			$lines[] = '';
		}
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text llms.txt; content is site-admin-controlled.
	echo implode( "\n", $lines );
}


// ── XML Sitemap ───────────────────────────────────────────────────────────────
add_action(
	'wp',
	function () {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$uri = strtok( $_SERVER['REQUEST_URI'], '?' );
		if ( rtrim( $uri, '/' ) === '/rmb-sitemap.xml' || rtrim( $uri, '/' ) === '/sitemap.xml' ) {
			rmb_serve_sitemap();
			exit;
		}
		if ( rtrim( $uri, '/' ) === '/rmb-sitemap-index.xml' || rtrim( $uri, '/' ) === '/sitemap_index.xml' ) {
			rmb_serve_sitemap_index();
			exit;
		}
	}
);

/**
 * Outputs the XML sitemap index document.
 */
function rmb_serve_sitemap_index() {
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	status_header( 200 );
	$site_url = rtrim( get_bloginfo( 'url' ), '/' );
	$now      = gmdate( 'Y-m-d\TH:i:s+00:00' );

	header( 'Content-Type: application/xml; charset=UTF-8' );
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML body; $site_url from get_bloginfo, $now from gmdate.
	echo "  <sitemap><loc>{$site_url}/rmb-sitemap.xml</loc><lastmod>{$now}</lastmod></sitemap>\n";
	echo '</sitemapindex>' . "\n";
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML comment; RMB_VERSION is a plugin constant.
	echo '<!-- XML Sitemap generated by RankRocket SEO Control Layer v' . RMB_VERSION . " -->\n";
}

/**
 * Outputs the XML sitemap document.
 */
function rmb_serve_sitemap() {
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	status_header( 200 );
	$entries = array();

	$front_page = (int) get_option( 'page_on_front' );
	$pages      = get_pages( array( 'post_status' => 'publish' ) );
	foreach ( $pages as $page ) {
		$noindex = rr_get_seo_meta( $page->ID, 'robots' );
		if ( ! empty( $noindex ) && (
			( is_array( $noindex ) && in_array( 'noindex', $noindex, true ) ) ||
			( is_string( $noindex ) && false !== strpos( $noindex, 'noindex' ) )
		) ) {
			continue;
		}
		$entries[] = array(
			'loc'     => get_permalink( $page->ID ),
			'lastmod' => mysql2date( 'Y-m-d\TH:i:s+00:00', $page->post_modified ),
			'pri'     => ( $page->ID === $front_page ) ? '1.0' : '0.8',
		);
	}

	$posts = get_posts(
		array(
			'numberposts' => -1,
			'post_status' => 'publish',
		)
	);
	foreach ( $posts as $post ) {
		$noindex = rr_get_seo_meta( $post->ID, 'robots' );
		if ( ! empty( $noindex ) && (
			( is_array( $noindex ) && in_array( 'noindex', $noindex, true ) ) ||
			( is_string( $noindex ) && false !== strpos( $noindex, 'noindex' ) )
		) ) {
			continue;
		}
		$entries[] = array(
			'loc'     => get_permalink( $post->ID ),
			'lastmod' => mysql2date( 'Y-m-d\TH:i:s+00:00', $post->post_modified ),
			'pri'     => '0.6',
		);
	}

	header( 'Content-Type: application/xml; charset=UTF-8' );
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $entries as $e ) {
		echo "  <url>\n";
		echo '    <loc>' . esc_url( $e['loc'] ) . "</loc>\n";
		echo '    <lastmod>' . esc_html( $e['lastmod'] ) . "</lastmod>\n";
		echo '    <priority>' . esc_html( $e['pri'] ) . "</priority>\n";
		echo "  </url>\n";
	}
	echo '</urlset>' . "\n";
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML comment; RMB_VERSION is a plugin constant.
	echo '<!-- XML Sitemap generated by RankRocket SEO Control Layer v' . RMB_VERSION . " -->\n";
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
						'intro'    => array(
							'required' => false,
							'type'     => 'string',
						),
						'sections' => array(
							'required' => false,
							'type'     => 'array',
						),
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
	$url_fields = array( 'og_image' );
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

	$thumb_id                    = get_post_thumbnail_id( $post_id );
	$meta['_featured_image_url'] = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';

	return rest_ensure_response(
		array(
			'post_id' => $post_id,
			'meta'    => $meta,
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
		$meta['rr_seo_score'] = get_post_meta( $pid, 'rank_math_seo_score', true );
		$results[]            = array(
			'post_id' => $pid,
			'slug'    => $post->post_name,
			'title'   => get_the_title( $post ),
			'meta'    => $meta,
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
	$url_fields = array( 'og_image' );
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
function rmb_images_bulk_alt( WP_REST_Request $request ) {
	$updates = $request->get_param( 'updates' );
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


// ── llms.txt Handlers ─────────────────────────────────────────────────────────
/**
 * Handles GET /llms — returns the current llms.txt configuration.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_llms_get_config( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$config = get_option( 'rmb_llms_config', array() );
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
	$config = get_option( 'rmb_llms_config', array() );

	$intro = $request->get_param( 'intro' );
	if ( null !== $intro ) {
		$config['intro'] = sanitize_textarea_field( $intro );
	}

	$sections = $request->get_param( 'sections' );
	if ( null !== $sections && is_array( $sections ) ) {
		$config['sections'] = $sections;
	}

	update_option( 'rmb_llms_config', $config );

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
 * Handles GET /sitemap/preview — returns all URLs with noindex status for review.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_sitemap_preview( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$entries    = array();
	$front_page = (int) get_option( 'page_on_front' );

	$pages = get_pages( array( 'post_status' => 'publish' ) );
	foreach ( $pages as $page ) {
		$noindex    = rr_get_seo_meta( $page->ID, 'robots' );
		$is_noindex = ! empty( $noindex ) && (
			( is_array( $noindex ) && in_array( 'noindex', $noindex, true ) ) ||
			( is_string( $noindex ) && false !== strpos( $noindex, 'noindex' ) )
		);
		$entries[]  = array(
			'id'       => $page->ID,
			'type'     => 'page',
			'loc'      => get_permalink( $page->ID ),
			'lastmod'  => mysql2date( 'Y-m-d', $page->post_modified ),
			'priority' => $page->ID === $front_page ? '1.0' : '0.8',
			'noindex'  => $is_noindex,
			'included' => ! $is_noindex,
		);
	}

	$posts = get_posts(
		array(
			'numberposts' => -1,
			'post_status' => 'publish',
		)
	);
	foreach ( $posts as $post ) {
		$noindex    = rr_get_seo_meta( $post->ID, 'robots' );
		$is_noindex = ! empty( $noindex ) && (
			( is_array( $noindex ) && in_array( 'noindex', $noindex, true ) ) ||
			( is_string( $noindex ) && false !== strpos( $noindex, 'noindex' ) )
		);
		$entries[]  = array(
			'id'       => $post->ID,
			'type'     => 'post',
			'loc'      => get_permalink( $post->ID ),
			'lastmod'  => mysql2date( 'Y-m-d', $post->post_modified ),
			'priority' => '0.6',
			'noindex'  => $is_noindex,
			'included' => ! $is_noindex,
		);
	}

	return rest_ensure_response(
		array(
			'sitemap_url'    => rtrim( get_bloginfo( 'url' ), '/' ) . '/rmb-sitemap.xml',
			'total'          => count( $entries ),
			'included_count' => count( array_filter( $entries, fn( $e ) => $e['included'] ) ),
			'excluded_count' => count( array_filter( $entries, fn( $e ) => ! $e['included'] ) ),
			'entries'        => array_values( $entries ),
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
function rmb_status( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$snippets    = get_option( RMB_SNIPPETS_KEY, array() );
	$rankmath_on = class_exists( 'RankMath' );
	return rest_ensure_response(
		array(
			'plugin'               => 'RankRocket SEO Control Layer',
			'version'              => RMB_VERSION,
			'rankmath_active'      => $rankmath_on,
			'snippet_count'        => count( $snippets ),
			'snippet_ids'          => array_keys( $snippets ),
			'sitemap_url'          => rtrim( get_bloginfo( 'url' ), '/' ) . '/rmb-sitemap.xml',
			'llms_url'             => rtrim( get_bloginfo( 'url' ), '/' ) . '/llms.txt',
			'update_url'           => RMB_UPDATE_URL,
			'php_version'          => PHP_VERSION,
			'wp_version'           => get_bloginfo( 'version' ),
			'allowed_post_types'   => apply_filters( 'rrseo_allowed_post_types', RR_ALLOWED_POST_TYPES ),
			'allowed_schema_types' => apply_filters( 'rrseo_allowed_schema_types', RR_ALLOWED_SCHEMA_TYPES ),
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
