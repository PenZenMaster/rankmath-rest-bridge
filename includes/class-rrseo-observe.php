<?php
/**
 * Module/Script Name: RankRocket SEO — Observation Endpoints (v3.0 Bite 1)
 * Path: includes/class-rrseo-observe.php
 *
 * Description:
 * Read-only observation endpoints that export structured WordPress-resident
 * signals for the external Audit Engine: heading hierarchy, link inventory,
 * image ALT coverage, per-post schema graph, and llms.txt drift. None of these
 * endpoints mutate state and none perform external HTTP calls — external link
 * verification is the Audit Engine's job (see docs/plugin-v3-executor-spec.md).
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-07-06
 * Last Modified Date: 2026-07-06
 *
 * Comments:
 * v1.00 - Initial release. Five GET /observe/* endpoints per the Shape B spec.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Pure helpers (unit-testable without WordPress) ────────────────────────────

/**
 * Extracts H1-H6 headings from rendered HTML as a flat ordered list.
 *
 * @param string $html Rendered post content.
 * @return array<int, array{level: int, text: string}> Headings in document order.
 */
function rr_observe_parse_headings( string $html ): array {
	$headings = array();
	if ( '' === trim( $html ) ) {
		return $headings;
	}
	if ( ! preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1\s*>/is', $html, $matches, PREG_SET_ORDER ) ) {
		return $headings;
	}
	foreach ( $matches as $m ) {
		$text = html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		$headings[] = array(
			'level' => (int) $m[1],
			'text'  => $text,
		);
	}
	return $headings;
}

/**
 * Builds a nested heading tree from a flat heading list.
 *
 * Each node has the shape { tag, text, depth, children } per the v3.0 spec.
 * A heading becomes a child of the nearest preceding heading with a lower
 * level; equal or higher levels pop back up the stack.
 *
 * @param array<int, array{level: int, text: string}> $flat Flat heading list.
 * @return array<int, array> Nested tree of heading nodes.
 */
function rr_observe_build_heading_tree( array $flat ): array {
	$tree  = array();
	$stack = array(); // Each entry: array{ level: int, path: int[] } — path walks children arrays.

	foreach ( $flat as $h ) {
		$top = end( $stack );
		while ( false !== $top && $top['level'] >= $h['level'] ) {
			array_pop( $stack );
			$top = end( $stack );
		}

		$node = array(
			'tag'      => 'h' . $h['level'],
			'text'     => $h['text'],
			'depth'    => $h['level'],
			'children' => array(),
		);

		if ( empty( $stack ) ) {
			$tree[] = $node;
			$path   = array( count( $tree ) - 1 );
		} else {
			$parent_path = $stack[ count( $stack ) - 1 ]['path'];
			$ref         = &$tree;
			foreach ( $parent_path as $idx ) {
				$ref = &$ref[ $idx ]['children'];
			}
			$ref[] = $node;
			$path  = array_merge( $parent_path, array( count( $ref ) - 1 ) );
			unset( $ref );
		}

		$stack[] = array(
			'level' => $h['level'],
			'path'  => $path,
		);
	}

	return $tree;
}

/**
 * Derives structural warnings from a flat heading list.
 *
 * Warning codes: no_h1, multiple_h1, skipped_level, empty_heading.
 *
 * @param array<int, array{level: int, text: string}> $flat Flat heading list.
 * @return string[] Warning codes (deduplicated, ordered by first occurrence).
 */
function rr_observe_heading_warnings( array $flat ): array {
	$warnings = array();
	$h1_count = 0;
	$prev     = 0;

	foreach ( $flat as $h ) {
		if ( 1 === $h['level'] ) {
			++$h1_count;
		}
		if ( $prev > 0 && $h['level'] > $prev + 1 ) {
			$warnings[] = 'skipped_level';
		}
		if ( '' === $h['text'] ) {
			$warnings[] = 'empty_heading';
		}
		$prev = $h['level'];
	}

	if ( 0 === $h1_count && ! empty( $flat ) ) {
		$warnings[] = 'no_h1';
	}
	if ( $h1_count > 1 ) {
		$warnings[] = 'multiple_h1';
	}

	return array_values( array_unique( $warnings ) );
}

/**
 * Extracts anchor links from rendered HTML.
 *
 * Skips non-navigational schemes (mailto, tel, javascript, data) and
 * fragment-only hrefs. Anchor text is stripped and whitespace-collapsed.
 *
 * @param string $html Rendered post content.
 * @return array<int, array{url: string, anchor_text: string}>
 */
function rr_observe_extract_links( string $html ): array {
	$links = array();
	if ( '' === trim( $html ) ) {
		return $links;
	}
	if ( ! preg_match_all( '/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a\s*>/is', $html, $matches, PREG_SET_ORDER ) ) {
		return $links;
	}
	foreach ( $matches as $m ) {
		$href = trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $href || '#' === $href[0] ) {
			continue;
		}
		if ( preg_match( '/^(mailto|tel|javascript|data):/i', $href ) ) {
			continue;
		}
		$text    = html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$links[] = array(
			'url'         => $href,
			'anchor_text' => trim( preg_replace( '/\s+/', ' ', $text ) ),
		);
	}
	return $links;
}

/**
 * Extracts absolute http(s) URLs from markdown link syntax in llms.txt content.
 *
 * @param string $content Rendered llms.txt markdown.
 * @return string[] URLs in document order (may contain duplicates).
 */
function rr_observe_extract_markdown_urls( string $content ): array {
	if ( ! preg_match_all( '/\]\((https?:\/\/[^)\s]+)\)/i', $content, $matches ) ) {
		return array();
	}
	return $matches[1];
}

/**
 * Normalizes a URL for set comparison: lowercased, trailing slash stripped.
 *
 * @param string $url Absolute URL.
 * @return string
 */
function rr_observe_normalize_compare_url( string $url ): string {
	return strtolower( rtrim( $url, '/' ) );
}

/**
 * Computes the drift between the llms.txt URL list and the canonical URL set.
 *
 * Comparison is normalized (case, trailing slash). Non-page discovery links
 * (the sitemap index) and off-host URLs on the llms side are ignored — llms.txt
 * may legitimately reference external resources the canonical set never contains.
 *
 * @param string[] $llms_urls      URLs extracted from llms.txt content.
 * @param string[] $canonical_urls URLs from rr_get_canonical_url_set().
 * @param string   $home_host      Host of the site URL (lowercased).
 * @return array{ in_both: string[], in_llms_not_canonical: string[], in_canonical_not_llms: string[] }
 */
function rr_observe_diff_url_sets( array $llms_urls, array $canonical_urls, string $home_host ): array {
	$llms_map = array();
	foreach ( $llms_urls as $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || strtolower( $host ) !== $home_host ) {
			continue;
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $path ) && false !== strpos( $path, 'sitemap_index.xml' ) ) {
			continue;
		}
		$llms_map[ rr_observe_normalize_compare_url( $url ) ] = $url;
	}

	$canonical_map = array();
	foreach ( $canonical_urls as $url ) {
		$canonical_map[ rr_observe_normalize_compare_url( $url ) ] = $url;
	}

	$in_both               = array();
	$in_llms_not_canonical = array();
	$in_canonical_not_llms = array();

	foreach ( $llms_map as $key => $url ) {
		if ( isset( $canonical_map[ $key ] ) ) {
			$in_both[] = $canonical_map[ $key ];
		} else {
			$in_llms_not_canonical[] = $url;
		}
	}
	foreach ( $canonical_map as $key => $url ) {
		if ( ! isset( $llms_map[ $key ] ) ) {
			$in_canonical_not_llms[] = $url;
		}
	}

	return array(
		'in_both'               => $in_both,
		'in_llms_not_canonical' => $in_llms_not_canonical,
		'in_canonical_not_llms' => $in_canonical_not_llms,
	);
}

// ── WordPress-bound helpers ───────────────────────────────────────────────────

/**
 * Renders a post's content through the_content filters for observation parsing.
 *
 * @param WP_Post $post Post object.
 * @return string Rendered HTML.
 */
function rr_observe_rendered_content( WP_Post $post ): string {
	return (string) apply_filters( 'the_content', $post->post_content );
}

/**
 * Resolves an internal URL against WordPress content without HTTP.
 *
 * Returns 'ok' when the URL maps to a published post/page, 'unverified' for
 * archive-shaped URLs this helper cannot resolve locally (term/author/date
 * archives), and 'not_found' otherwise.
 *
 * @param string $url Absolute internal URL.
 * @return string 'ok' | 'not_found' | 'unverified'
 */
function rr_observe_resolve_internal_url( string $url ): string {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	$path = is_string( $path ) ? $path : '/';

	// Site root always resolves.
	if ( '' === trim( $path, '/' ) ) {
		return 'ok';
	}

	$post_id = url_to_postid( $url );
	if ( $post_id > 0 ) {
		return 'ok';
	}

	// Hierarchical page paths that url_to_postid() sometimes misses.
	$page = get_page_by_path( trim( $path, '/' ), OBJECT, apply_filters( 'rrseo_allowed_post_types', RR_ALLOWED_POST_TYPES ) );
	if ( $page && 'publish' === $page->post_status ) {
		return 'ok';
	}

	// Archive-shaped URLs (term, author, date, feed) cannot be resolved to a
	// post ID locally — report them unverified rather than falsely broken.
	$category_base = (string) get_option( 'category_base' );
	$tag_base      = (string) get_option( 'tag_base' );
	$archive_bases = array_filter(
		array(
			'' !== $category_base ? $category_base : 'category',
			'' !== $tag_base ? $tag_base : 'tag',
			'author',
			'feed',
			'shop',
			'product-category',
			'product-tag',
		)
	);
	$first_segment = strtok( trim( $path, '/' ), '/' );
	if ( in_array( $first_segment, $archive_bases, true ) ) {
		return 'unverified';
	}
	if ( preg_match( '/^\/\d{4}(\/\d{2})?/', $path ) ) {
		return 'unverified';
	}

	return 'not_found';
}

// ── REST handlers ─────────────────────────────────────────────────────────────

/**
 * Handles GET /observe/heading-hierarchy/{post_id} — exports the H1-H6 structure.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_observe_heading_hierarchy( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'post_id' ) );
	$post    = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return new WP_Error( 'invalid_post', 'Published post not found', array( 'status' => 404 ) );
	}

	$flat = rr_observe_parse_headings( rr_observe_rendered_content( $post ) );

	return new WP_REST_Response(
		array(
			'post_id'       => $post_id,
			'post_title'    => get_the_title( $post ),
			'heading_count' => count( $flat ),
			'warnings'      => rr_observe_heading_warnings( $flat ),
			'tree'          => rr_observe_build_heading_tree( $flat ),
		),
		200
	);
}

/**
 * Handles GET /observe/broken-links — paginated internal/external link inventory.
 *
 * Internal links are resolved against WordPress content locally (no HTTP).
 * External links are returned with status_code null and checked=false — the
 * Audit Engine performs external verification; the plugin never calls out.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_observe_broken_links( WP_REST_Request $request ): WP_REST_Response {
	$page             = max( 1, intval( $request->get_param( 'page' ) ) );
	$per_page         = min( 100, max( 1, intval( $request->get_param( 'per_page' ) ) ) );
	$post_type        = (string) $request->get_param( 'post_type' );
	$include_external = (bool) $request->get_param( 'include_external' );

	$allowed_types = apply_filters( 'rrseo_allowed_post_types', RR_ALLOWED_POST_TYPES );
	$types         = ( '' !== $post_type && in_array( $post_type, $allowed_types, true ) )
		? array( $post_type )
		: $allowed_types;

	$query = new WP_Query(
		array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		)
	);

	$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$items     = array();

	foreach ( $query->posts as $source_id ) {
		$post = get_post( $source_id );
		if ( ! $post ) {
			continue;
		}
		$links = rr_observe_extract_links( rr_observe_rendered_content( $post ) );
		foreach ( $links as $link ) {
			$url  = $link['url'];
			$host = wp_parse_url( $url, PHP_URL_HOST );

			// Relative URLs are internal by definition.
			if ( ! is_string( $host ) || '' === $host ) {
				$url  = home_url( '/' === $url[0] ? $url : '/' . $url );
				$host = $home_host;
			}

			if ( strtolower( $host ) === $home_host ) {
				$resolution = rr_observe_resolve_internal_url( $url );
				if ( 'ok' === $resolution ) {
					continue; // Healthy internal link — not part of the problem inventory.
				}
				$items[] = array(
					'url'            => $url,
					'status_code'    => 'not_found' === $resolution ? 404 : null,
					'anchor_text'    => $link['anchor_text'],
					'source_post_id' => $source_id,
					'scope'          => 'internal',
					'resolution'     => $resolution,
					'checked'        => 'not_found' === $resolution,
				);
			} elseif ( $include_external ) {
				$items[] = array(
					'url'            => $url,
					'status_code'    => null,
					'anchor_text'    => $link['anchor_text'],
					'source_post_id' => $source_id,
					'scope'          => 'external',
					'resolution'     => 'external_unchecked',
					'checked'        => false,
				);
			}
		}
	}

	return new WP_REST_Response(
		array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total_posts' => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'note'        => 'External links are unchecked by design; the Audit Engine performs external verification.',
			'links'       => $items,
		),
		200
	);
}

/**
 * Handles GET /observe/alt-coverage — image ALT text rollup by parent post type.
 *
 * 'missing' counts image attachments with no _wp_attachment_image_alt row;
 * 'empty' counts rows that exist but hold an empty string. Results are cached
 * in a 5-minute transient — this is observational data, not a live counter.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_observe_alt_coverage( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$cached = get_transient( 'rrseo_observe_alt_coverage' );
	if ( is_array( $cached ) ) {
		return new WP_REST_Response( $cached, 200 );
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate rollup over all attachments; result cached in a transient above.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT COALESCE( parent.post_type, 'unattached' ) AS parent_type, m.meta_value AS alt_value
			 FROM {$wpdb->posts} a
			 LEFT JOIN {$wpdb->posts} parent ON parent.ID = a.post_parent
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = a.ID AND m.meta_key = %s
			 WHERE a.post_type = 'attachment' AND a.post_mime_type LIKE %s",
			'_wp_attachment_image_alt',
			$wpdb->esc_like( 'image/' ) . '%'
		)
	);

	$overall = array(
		'total'   => 0,
		'missing' => 0,
		'empty'   => 0,
	);
	$by_type = array();

	foreach ( (array) $rows as $row ) {
		$ptype = (string) $row->parent_type;
		if ( ! isset( $by_type[ $ptype ] ) ) {
			$by_type[ $ptype ] = array(
				'total'   => 0,
				'missing' => 0,
				'empty'   => 0,
			);
		}
		++$overall['total'];
		++$by_type[ $ptype ]['total'];

		if ( null === $row->alt_value ) {
			++$overall['missing'];
			++$by_type[ $ptype ]['missing'];
		} elseif ( '' === trim( (string) $row->alt_value ) ) {
			++$overall['empty'];
			++$by_type[ $ptype ]['empty'];
		}
	}

	$with_pct = function ( array $bucket ): array {
		$covered                = $bucket['total'] - $bucket['missing'] - $bucket['empty'];
		$bucket['coverage_pct'] = $bucket['total'] > 0 ? round( ( $covered / $bucket['total'] ) * 100, 1 ) : 100.0;
		return $bucket;
	};

	$overall = $with_pct( $overall );
	foreach ( $by_type as $ptype => $bucket ) {
		$by_type[ $ptype ] = $with_pct( $bucket );
	}

	$payload = array_merge(
		array( 'generated_at' => gmdate( 'Y-m-d\TH:i:s+00:00' ) ),
		$overall,
		array( 'by_parent_type' => $by_type )
	);

	set_transient( 'rrseo_observe_alt_coverage', $payload, 5 * MINUTE_IN_SECONDS );

	return new WP_REST_Response( $payload, 200 );
}

/**
 * Handles GET /observe/schema-graph/{post_id} — full stored JSON-LD graph.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_observe_schema_graph( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'post_id' ) );
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
	}

	$graph      = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
	$has_schema = ! empty( $graph ) && is_array( $graph );

	$types = array();
	if ( $has_schema ) {
		$nodes = isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ? $graph['@graph'] : array( $graph );
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) ) {
				foreach ( (array) $node['@type'] as $type ) {
					$types[] = (string) $type;
				}
			}
		}
		$types = array_values( array_unique( $types ) );
	}

	return new WP_REST_Response(
		array(
			'post_id'    => $post_id,
			'has_schema' => $has_schema,
			'types'      => $types,
			'graph'      => $has_schema ? $graph : null,
		),
		200
	);
}

/**
 * Handles GET /observe/llms-diff — drift between llms.txt and the canonical set.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_observe_llms_diff( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$config = get_option( RR_LLMS_CONFIG_KEY, get_option( 'rmb_llms_config', array() ) );
	$render = rr_render_llms_txt( is_array( $config ) ? $config : array() );

	$llms_urls = rr_observe_extract_markdown_urls( $render['content'] );

	$canonical      = rr_get_canonical_url_set();
	$canonical_urls = array();
	foreach ( $canonical['urls'] as $entry ) {
		$canonical_urls[] = (string) $entry['url'];
	}

	$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$diff      = rr_observe_diff_url_sets( $llms_urls, $canonical_urls, $home_host );

	return new WP_REST_Response(
		array(
			'llms_url_count'        => count( $llms_urls ),
			'canonical_url_count'   => count( $canonical_urls ),
			'in_both'               => $diff['in_both'],
			'in_llms_not_canonical' => $diff['in_llms_not_canonical'],
			'in_canonical_not_llms' => $diff['in_canonical_not_llms'],
			'in_sync'               => empty( $diff['in_llms_not_canonical'] ) && empty( $diff['in_canonical_not_llms'] ),
		),
		200
	);
}
