<?php
/**
 * Module/Script Name: RankRocket SEO -- Schema Hygiene (issue #23)
 * Path: includes/class-rrseo-schema-hygiene.php
 *
 * Description:
 * Strips third-party (non-plugin) JSON-LD blocks from a post's <head> when
 * their declared @type matches a per-post strip_third_party list (set via
 * POST /schema/{post_id}, see rr_validate_schema_strip_third_party() and
 * RR_SCHEMA_STRIP_KEY in rankmath-rest-bridge.php). Stage 1 scope: whole-
 * <script>-block removal only, wp_head only (no theme output outside
 * wp_head, no per-property surgery). This plugin's own schema block is
 * always protected via the RR_SCHEMA_HYGIENE_MARKER_START/END comments
 * that bracket it.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-08-13
 * Last Modified Date: 2026-08-13
 *
 * Comments:
 * v1.00 - Initial release.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Pure helpers (unit-testable without WordPress) ────────────────────────────

/**
 * Flattens a decoded JSON-LD payload into a deduplicated list of @type
 * values. Unlike rr_observe_extract_schema_types() (tuned for this
 * plugin's own {"@graph": [...]} storage shape), this also accepts a bare
 * top-level array of nodes, since third-party emitters are less
 * predictable than the plugin's own storage format.
 *
 * @param mixed $decoded json_decode( ..., true ) result.
 * @return string[]
 */
function rr_schema_hygiene_extract_json_types( $decoded ): array {
	if ( ! is_array( $decoded ) ) {
		return array();
	}

	if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
		$nodes = $decoded['@graph'];
	} elseif ( wp_is_numeric_array( $decoded ) ) {
		$nodes = $decoded;
	} else {
		$nodes = array( $decoded );
	}

	$types = array();
	foreach ( $nodes as $node ) {
		if ( is_array( $node ) && isset( $node['@type'] ) ) {
			foreach ( (array) $node['@type'] as $type ) {
				$types[] = (string) $type;
			}
		}
	}
	return array_values( array_unique( $types ) );
}

/**
 * Removes third-party <script type="application/ld+json"> blocks whose
 * JSON declares one of the given @type values from a buffered <head>
 * string, while always preserving this plugin's own schema block (the
 * one bracketed by RR_SCHEMA_HYGIENE_MARKER_START/END).
 *
 * Whole-block removal only (issue #23 Stage 1 scope): if a block's JSON
 * contains a @graph mixing a strip-listed type with a kept type, the
 * entire block is removed rather than surgically edited.
 *
 * @param string   $html        Buffered <head> HTML (e.g. from ob_get_clean()).
 * @param string[] $strip_types @type values to strip from non-plugin blocks.
 * @return string
 */
function rr_schema_hygiene_strip_html( string $html, array $strip_types ): string {
	if ( empty( $strip_types ) || '' === trim( $html ) ) {
		return $html;
	}

	$protect_start = strpos( $html, RR_SCHEMA_HYGIENE_MARKER_START );
	$protect_end   = false !== $protect_start
		? strpos( $html, RR_SCHEMA_HYGIENE_MARKER_END, $protect_start )
		: false;

	if ( ! preg_match_all(
		'/<script\b[^>]*type\s*=\s*["\']application\/ld\+json["\'][^>]*>(.*?)<\/script\s*>/is',
		$html,
		$matches,
		PREG_OFFSET_CAPTURE
	) ) {
		return $html;
	}

	// Reverse order so earlier byte offsets stay valid as later blocks are spliced out.
	$blocks = $matches[0];
	$bodies = $matches[1];
	for ( $i = count( $blocks ) - 1; $i >= 0; $i-- ) {
		$full_match  = $blocks[ $i ][0];
		$full_offset = $blocks[ $i ][1];
		$json_text   = $bodies[ $i ][0];

		if ( false !== $protect_start && $full_offset >= $protect_start && $full_offset <= $protect_end ) {
			continue; // This plugin's own block -- never stripped.
		}

		$decoded = json_decode( trim( $json_text ), true );
		if ( ! is_array( $decoded ) ) {
			continue;
		}

		$types = rr_schema_hygiene_extract_json_types( $decoded );
		if ( array_intersect( $types, $strip_types ) ) {
			$html = substr_replace( $html, '', $full_offset, strlen( $full_match ) );
		}
	}

	return $html;
}

// ── WordPress-bound application ───────────────────────────────────────────────

/**
 * Returns the active strip_third_party list for the currently-queried
 * singular post, or an empty array when none is configured (or the
 * current request isn't a singular post/page view).
 *
 * @return string[]
 */
function rrseo_schema_hygiene_active_strip_types(): array {
	if ( ! is_singular() ) {
		return array();
	}
	$post_id = get_queried_object_id();
	$types   = get_post_meta( $post_id, RR_SCHEMA_STRIP_KEY, true );
	return ( is_array( $types ) && ! empty( $types ) ) ? array_values( array_map( 'strval', $types ) ) : array();
}

add_action( 'wp_head', 'rrseo_schema_hygiene_buffer_start', 1 );
add_action( 'wp_head', 'rrseo_schema_hygiene_buffer_end', PHP_INT_MAX );

/**
 * Starts an output buffer around the rest of wp_head when this post has a
 * strip_third_party list configured. No-ops (no buffer, no overhead) on
 * every other page, matching how wp_head:5's own schema emitter already
 * bails early via is_singular().
 */
function rrseo_schema_hygiene_buffer_start(): void {
	if ( empty( rrseo_schema_hygiene_active_strip_types() ) ) {
		return;
	}
	ob_start();
}

/**
 * Closes the buffer opened by rrseo_schema_hygiene_buffer_start(), scrubs
 * third-party JSON-LD matching the configured strip list, and re-emits the
 * result. Runs at PHP_INT_MAX so every other wp_head callback (including
 * this plugin's own schema emitter at priority 5) has already fired.
 */
function rrseo_schema_hygiene_buffer_end(): void {
	$strip_types = rrseo_schema_hygiene_active_strip_types();
	if ( empty( $strip_types ) ) {
		return;
	}
	$html = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- re-emitting this same request's own wp_head output verbatim, minus stripped third-party <script> blocks; nothing new is introduced.
	echo rr_schema_hygiene_strip_html( (string) $html, $strip_types );
}
