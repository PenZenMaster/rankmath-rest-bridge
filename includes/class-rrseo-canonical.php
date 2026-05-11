<?php
/**
 * Module/Script Name: RankRocket SEO — Canonical URL Set
 * Path: includes/class-rrseo-canonical.php
 *
 * Description:
 * Shared Canonical URL Set helper consumed by all discovery-file generators:
 * sitemaps, llms.txt, sitemap preview REST endpoint, and llms preview.
 * Centralises inclusion/exclusion logic so every output uses identical filtering.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-04-29
 * Last Modified Date: 2026-04-29
 *
 * Comments:
 * v1.00 - Initial release. P0 implementation: canonical set, discovery metadata,
 *         noindex/utility/numeric-suffix exclusion logic, description fallback chain.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Internal helpers ──────────────────────────────────────────────────────────

/**
 * Returns true when a robots meta value contains 'noindex'.
 *
 * Accepts the stored value from rr_get_seo_meta() which can be either a
 * comma-separated string or an array of directive tokens.
 *
 * @param mixed $robots Value from rr_get_seo_meta( $post_id, 'robots' ).
 * @return bool
 */
function rr_has_noindex( $robots ): bool {
	if ( empty( $robots ) ) {
		return false;
	}
	if ( is_array( $robots ) ) {
		return in_array( 'noindex', $robots, true );
	}
	return false !== strpos( (string) $robots, 'noindex' );
}

/**
 * Normalizes a URL path for prefix and exact-path comparison.
 *
 * Adds a leading slash, adds a trailing slash, and lowercases the result.
 * Applied to URL paths before comparison; exclusion patterns are also
 * normalized to lowercase so both sides of strpos() share the same case.
 *
 * @param string $path Raw URL path.
 * @return string Normalized path.
 */
function rr_normalize_url_path( string $path ): string {
	$path = strtolower( $path );
	if ( '' === $path || '/' !== $path[0] ) {
		$path = '/' . $path;
	}
	if ( '/' !== $path && '/' !== $path[ strlen( $path ) - 1 ] ) {
		$path .= '/';
	}
	return $path;
}

/**
 * Returns true when a URL path matches an exclusion pattern.
 *
 * The default utility pattern list is filterable via rrseo_utility_exclusion_patterns.
 * Custom patterns from the llms config option (exclude_patterns) are always additive.
 * When exclude_utility_pages is true (or not set, defaulting to true), the built-in
 * list is also applied.
 *
 * Pattern strings are compared via strpos (contains match) against the normalized path.
 *
 * @param string $url Full post permalink.
 * @return bool
 */
function rr_is_utility_url( string $url ): bool {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	if ( ! $path ) {
		return false;
	}
	$path = rr_normalize_url_path( $path );

	// Default built-in utility patterns (filterable).
	$default_patterns = apply_filters(
		'rrseo_utility_exclusion_patterns',
		array(
			'/thank-you/',
			'/privacy-policy/',
			'/opt-out-preferences/',
			'/cart/',
			'/checkout/',
			'/my-account/',
			'/account/',
			'/search/',
			'/feed/',
		)
	);

	// Custom patterns from llms config (P1 will expand this; for P0 check both option keys).
	$config          = get_option( 'rrseo_llms_config', get_option( 'rmb_llms_config', array() ) );
	$custom_patterns = ( isset( $config['exclude_patterns'] ) && is_array( $config['exclude_patterns'] ) )
		? $config['exclude_patterns']
		: array();

	// When exclude_utility_pages is true (or absent — default true), apply built-in list.
	$use_defaults = ! isset( $config['exclude_utility_pages'] ) || ! empty( $config['exclude_utility_pages'] );
	$raw_active   = $use_defaults ? array_merge( $default_patterns, $custom_patterns ) : $custom_patterns;

	// Normalize patterns to lowercase once so strpos() compares like-cased strings.
	// $path is already lowercased by rr_normalize_url_path().
	$active = array_map( 'strtolower', array_map( 'strval', $raw_active ) );

	foreach ( $active as $pattern ) {
		if ( '' !== $pattern && false !== strpos( $path, $pattern ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Detects whether a post's slug is a WordPress numeric-suffix duplicate.
 *
 * Detection requires two conditions (per v4 §4):
 * 1. The slug matches {base}-{integer}.
 * 2. A published post with slug {base} exists in the same post type.
 *
 * Returns:
 *   'duplicate' — base URL exists; this post should be excluded.
 *   'orphan'    — slug matches pattern but no base URL found; include with warning.
 *   'ok'        — slug does not match the numeric-suffix pattern.
 *
 * @param WP_Post $post Post object.
 * @return string 'duplicate' | 'orphan' | 'ok'
 */
function rr_check_numeric_suffix( WP_Post $post ): string {
	$slug = $post->post_name;

	// Match {base}-{integer} — integer must be at the very end.
	if ( ! preg_match( '/^(.+)-(\d+)$/', $slug, $matches ) ) {
		return 'ok';
	}

	$base = $matches[1];

	$base_posts = get_posts(
		array(
			'name'        => $base,
			'post_type'   => $post->post_type,
			'post_status' => 'publish',
			'numberposts' => 1,
		)
	);

	return empty( $base_posts ) ? 'orphan' : 'duplicate';
}

/**
 * Normalizes a raw text string for use as a discovery description.
 *
 * Applies the pipeline from v4 §15.8:
 * HTML strip → entity decode → CRLF/CR → LF → LF → space → collapse whitespace → trim.
 *
 * @param string $text Raw text from post meta, excerpt, or content.
 * @return string Normalized single-line string.
 */
function rr_normalize_description_text( string $text ): string {
	$text = wp_strip_all_tags( $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/\r\n|\r/', "\n", $text );
	$text = preg_replace( '/\n/', ' ', $text );
	$text = preg_replace( '/\s+/', ' ', $text );
	return trim( $text );
}

/**
 * Truncates a normalized description at the nearest word boundary.
 *
 * Uses ASCII '...' (not a Unicode ellipsis). Applied after rr_normalize_description_text().
 *
 * @param string $text Normalized description text.
 * @param int    $max  Maximum character count before truncation. Default 240.
 * @return string
 */
function rr_truncate_description( string $text, int $max = 240 ): string {
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$truncated  = mb_substr( $text, 0, $max );
	$last_space = mb_strrpos( $truncated, ' ' );
	if ( false !== $last_space && $last_space > 0 ) {
		$truncated = mb_substr( $truncated, 0, $last_space );
	}
	return rtrim( $truncated ) . '...';
}

/**
 * Resolves the best available description for a post using the fallback chain.
 *
 * Fallback order (v4 §15.8 / spec P0.3):
 * 1. RankRocket SEO description (rr_seo_description post meta).
 * 2. WordPress excerpt.
 * 3. First non-empty line of stripped post_content.
 * 4. Post title only (adds 'thin_description' warning code).
 *
 * @param WP_Post $post   Post object.
 * @param int     $max    Max description chars after normalization. Default 240.
 * @return array{ description: string, source: string, warning: string|null }
 */
function rr_get_discovery_description( WP_Post $post, int $max = 240 ): array {
	// 1. RankRocket SEO description.
	$seo_desc = rr_get_seo_meta( $post->ID, 'description' );
	if ( '' !== $seo_desc && false !== $seo_desc ) {
		return array(
			'description' => rr_truncate_description( rr_normalize_description_text( (string) $seo_desc ), $max ),
			'source'      => 'rrseo_description',
			'warning'     => null,
		);
	}

	// 2. WordPress excerpt.
	if ( '' !== $post->post_excerpt ) {
		return array(
			'description' => rr_truncate_description( rr_normalize_description_text( $post->post_excerpt ), $max ),
			'source'      => 'excerpt',
			'warning'     => null,
		);
	}

	// 3. First meaningful paragraph from content.
	// Split on paragraph boundaries in the RAW content before any normalization —
	// rr_normalize_description_text() collapses all whitespace including newlines to
	// single spaces, so splitting after normalization always yields one element.
	if ( '' !== $post->post_content ) {
		$raw_paras = preg_split(
			'/\r?\n\s*\r?\n|<\/p\s*>|<br\s*\/?>\s*<br\s*\/?>/',
			$post->post_content
		);
		$first     = '';
		foreach ( $raw_paras as $raw_para ) {
			$cleaned = rr_normalize_description_text( $raw_para );
			if ( strlen( $cleaned ) >= 20 ) {
				$first = $cleaned;
				break;
			}
		}
		if ( '' !== $first ) {
			return array(
				'description' => rr_truncate_description( $first, $max ),
				'source'      => 'first_paragraph',
				'warning'     => null,
			);
		}
	}

	// 4. Title-only fallback with thin_description warning.
	return array(
		'description' => rr_normalize_description_text( get_the_title( $post ) ),
		'source'      => 'title',
		'warning'     => 'thin_description',
	);
}

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Determines whether a post is eligible for inclusion in discovery files.
 *
 * Returns an array so the caller can collect both exclusion reasons and
 * warnings (e.g. orphan_numeric_suffix) without reference parameters.
 *
 * @param WP_Post $post Post object to evaluate.
 * @return array{
 *   allowed:  bool,
 *   reason:   string|null,
 *   warnings: array<int, array{code: string, url: string, message: string}>
 * }
 */
function rr_is_url_allowed_for_discovery( WP_Post $post ): array {
	$ok = array(
		'allowed'  => true,
		'reason'   => null,
		'warnings' => array(),
	);

	// Must be published.
	if ( 'publish' !== $post->post_status ) {
		return array(
			'allowed'  => false,
			'reason'   => 'not_published',
			'warnings' => array(),
		);
	}

	// Not password-protected.
	if ( '' !== $post->post_password ) {
		return array(
			'allowed'  => false,
			'reason'   => 'password_protected',
			'warnings' => array(),
		);
	}

	// Not noindex.
	$robots = rr_get_seo_meta( $post->ID, 'robots' );
	if ( rr_has_noindex( $robots ) ) {
		return array(
			'allowed'  => false,
			'reason'   => 'noindex',
			'warnings' => array(),
		);
	}

	// Not a test placeholder.
	if ( str_starts_with( $post->post_name, 'please-do-not-delete-this-' ) ) {
		return array(
			'allowed'  => false,
			'reason'   => 'test_placeholder',
			'warnings' => array(),
		);
	}

	// Not a utility URL.
	$permalink = get_permalink( $post->ID );
	if ( rr_is_utility_url( (string) $permalink ) ) {
		return array(
			'allowed'  => false,
			'reason'   => 'utility_page',
			'warnings' => array(),
		);
	}

	// Numeric suffix duplicate check (operates on slug, not full URL).
	$suffix = rr_check_numeric_suffix( $post );
	if ( 'duplicate' === $suffix ) {
		return array(
			'allowed'  => false,
			'reason'   => 'duplicate_numeric_suffix',
			'warnings' => array(),
		);
	}
	if ( 'orphan' === $suffix ) {
		$ok['warnings'][] = array(
			'code'    => 'orphan_numeric_suffix',
			'url'     => (string) $permalink,
			'message' => 'URL has a numeric suffix but no canonical base URL was found.',
		);
	}

	return $ok;
}

/**
 * Builds the per-URL metadata entry for the Canonical URL Set.
 *
 * The 'description' field is populated separately via rr_get_discovery_description()
 * and merged by rr_get_canonical_url_set(). The 'group' field is populated by the
 * section classifier in P1.
 *
 * @param WP_Post $post Post object.
 * @return array
 */
function rr_get_post_discovery_metadata( WP_Post $post ): array {
	$url = (string) get_permalink( $post->ID );
	return array(
		'post_id'       => $post->ID,
		'post_type'     => $post->post_type,
		'title'         => get_the_title( $post ),
		'url'           => $url,
		'canonical_url' => $url,
		'description'   => '',
		'lastmod'       => mysql2date( 'Y-m-d\TH:i:s+00:00', $post->post_modified_gmt ),
		'robots'        => rr_get_seo_meta( $post->ID, 'robots' ),
		'group'         => '',
		'warnings'      => array(),
	);
}

/**
 * Returns the shared Canonical URL Set consumed by all discovery-file generators.
 *
 * Every sitemap, llms.txt, and preview endpoint must call this function rather than
 * maintaining its own URL-enumeration loop. This guarantees consistent filtering
 * across all discovery outputs.
 *
 * Return shape:
 * array(
 *   'urls'     => array<int, array>  — included URLs with metadata
 *   'excluded' => array<int, array>  — excluded URLs with reason codes
 *   'warnings' => array<int, array>  — site-level warnings (orphan suffixes, etc.)
 * )
 *
 * @param array $args {
 *   Optional arguments.
 *   @type string[] $post_types     Post types to include. Default: rrseo_allowed_post_types filter.
 *   @type bool     $include_utility Include utility URLs. Default false.
 *   @type int      $max_description Max description character count. Default 240.
 * }
 * @return array{ urls: array, excluded: array, warnings: array }
 */
function rr_get_canonical_url_set( array $args = array() ): array {
	$defaults = array(
		'post_types'      => apply_filters( 'rrseo_allowed_post_types', RR_ALLOWED_POST_TYPES ),
		'include_utility' => false,
		'max_description' => 240,
	);
	$args     = array_merge( $defaults, $args );

	$urls     = array();
	$excluded = array();
	$warnings = array();

	foreach ( $args['post_types'] as $post_type ) {
		if ( 'page' === $post_type ) {
			$items = get_pages( array( 'post_status' => 'publish' ) );
		} else {
			$items = get_posts(
				array(
					'post_type'   => $post_type,
					'numberposts' => -1,
					'post_status' => 'publish',
				)
			);
		}

		if ( empty( $items ) ) {
			continue;
		}

		foreach ( $items as $post ) {
			$check = rr_is_url_allowed_for_discovery( $post );

			if ( ! $check['allowed'] ) {
				$excluded[] = array(
					'url'       => (string) get_permalink( $post->ID ),
					'post_id'   => $post->ID,
					'post_type' => $post->post_type,
					'reason'    => $check['reason'],
				);
				continue;
			}

			// Collect site-level warnings (e.g. orphan_numeric_suffix).
			if ( ! empty( $check['warnings'] ) ) {
				foreach ( $check['warnings'] as $w ) {
					$warnings[] = $w;
				}
			}

			$meta = rr_get_post_discovery_metadata( $post );

			// Resolve description via fallback chain.
			$desc_result         = rr_get_discovery_description( $post, (int) $args['max_description'] );
			$meta['description'] = $desc_result['description'];
			if ( null !== $desc_result['warning'] ) {
				$meta['warnings'][] = array(
					'code'    => $desc_result['warning'],
					'url'     => $meta['url'],
					'message' => 'No SEO description, excerpt, or first paragraph found.',
				);
			}

			// Per-URL warnings from inclusion check (orphan suffix).
			if ( ! empty( $check['warnings'] ) ) {
				foreach ( $check['warnings'] as $w ) {
					$meta['warnings'][] = $w;
				}
			}

			$urls[] = $meta;
		}
	}

	return array(
		'urls'     => $urls,
		'excluded' => $excluded,
		'warnings' => $warnings,
	);
}
