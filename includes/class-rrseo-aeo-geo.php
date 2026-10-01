<?php
/**
 * Module/Script Name: RankRocket SEO — AEO/GEO Audit Data Layer
 * Path: includes/class-rrseo-aeo-geo.php
 *
 * Description:
 * AEO/GEO audit readiness helpers and REST endpoint handlers. Exposes the
 * canonical URL set and on-site readiness signals consumed by the external
 * RankRocket audit engine. The plugin is strictly a read-only data provider;
 * no Google API calls, OAuth, or audit data storage occur here.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-05-01
 *
 * Last Modified Date: 2026-09-30
 *
 * Comments:
 * v1.00 - Initial: five REST endpoints — /canonical-urls/preview,
 *         /aeo-geo/readiness, /aeo-geo/entity, /aeo-geo/schema-audit,
 *         /aeo-geo/source-sync.
 * v1.01 - Graph-aware schema inventory (issue #30): shared walker for single
 *         objects, arrays, @graph and nested entities; applicable snippet
 *         JSON-LD and optional public-frontend evidence; per-source reporting.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Internal constants ────────────────────────────────────────────────────────

/**
 * Post types that appear in the XML sitemap (mirrors rmb_sitemap_preview()).
 */
define( 'RR_AEO_SITEMAP_POST_TYPES', array( 'post', 'page' ) );

/**
 * Schema @type values recognised as a LocalBusiness/Organization entity.
 */
define(
	'RR_AEO_LOCAL_ENTITY_TYPES',
	array( 'LocalBusiness', 'Organization', 'ProfessionalService', 'Store', 'MedicalBusiness' )
);

// ── Core helpers (tested directly) ───────────────────────────────────────────

/**
 * Returns the canonical URL set enriched with per-URL AEO membership flags.
 *
 * Adds to each URL entry:
 *   in_sitemap   — bool: post_type is one of the XML sitemap post types.
 *   in_llms      — bool: always true for canonical URLs; rr_is_utility_url() already
 *                  applies llms exclude_patterns during canonical set construction.
 *   has_schema   — bool: post has a stored _rrseo_schema_graph meta entry.
 *   schema_types — string[]: @type values from the stored schema.
 *
 * @param array $args Optional args forwarded to rr_get_canonical_url_set().
 * @return array{
 *   urls: array,
 *   excluded: array,
 *   warnings: array,
 *   generated_at: string
 * }
 */
function rr_aeo_compute_canonical_preview( array $args = array() ): array {
	$canonical = rr_get_canonical_url_set( $args );
	$urls_out  = array();
	$snippets  = get_option( RMB_SNIPPETS_KEY, array() );
	$snippets  = is_array( $snippets ) ? $snippets : array();
	$emit_on   = (bool) get_option( 'rrseo_emit_snippets', true );

	foreach ( $canonical['urls'] as $entry ) {
		$post_id = (int) $entry['post_id'];

		// Only post and page types appear in the XML sitemap.
		$in_sitemap = in_array( $entry['post_type'], RR_AEO_SITEMAP_POST_TYPES, true );

		// All canonical URLs are in llms.txt: rr_is_utility_url() already applies
		// llms exclude_patterns during canonical set construction, so no URL in
		// canonical['urls'] can match an exclude_pattern.
		$in_llms = true;

		// Schema membership: stored schema plus applicable snippet JSON-LD.
		$schema_types = array();
		$ctx          = rr_schema_post_context( $post_id, (string) $entry['post_type'], (string) $entry['url'] );
		foreach ( rr_schema_collect_post_sources( $post_id, $ctx, $snippets, $emit_on ) as $src ) {
			$schema_types = array_values( array_unique( array_merge( $schema_types, $src['types'] ) ) );
		}
		$has_schema = ! empty( $schema_types );

		$urls_out[] = array_merge(
			$entry,
			array(
				'in_sitemap'   => $in_sitemap,
				'in_llms'      => $in_llms,
				'has_schema'   => $has_schema,
				'schema_types' => $schema_types,
			)
		);
	}

	return array(
		'urls'         => $urls_out,
		'excluded'     => $canonical['excluded'],
		'warnings'     => $canonical['warnings'],
		'generated_at' => gmdate( 'Y-m-d\TH:i:s+00:00' ),
	);
}

/**
 * Returns structured entity signals derived from the llms config and homepage schema.
 *
 * Priority chain mirrors rr_resolve_business_facts():
 *   1. Manual business_facts in llms config.
 *   2. Schema from schema_source_post_id.
 *   3. Homepage schema (page_on_front).
 *   4. WordPress bloginfo fallback.
 *
 * @return array{
 *   business_name: string,
 *   website: string,
 *   phone: string,
 *   address: string,
 *   schema_type: string,
 *   entity_id: string,
 *   primary_services: array,
 *   service_area: array,
 *   homepage_schema_types: string[],
 *   source: string,
 *   warnings: array
 * }
 */
function rr_aeo_compute_entity_signals(): array {
	$config = get_option( RR_LLMS_CONFIG_KEY, array() );
	$config = is_array( $config ) ? $config : array();

	$facts = rr_resolve_business_facts( $config );

	// Determine homepage schema types independently of the facts resolver.
	$homepage_id           = (int) get_option( 'page_on_front', 0 );
	$homepage_schema_types = array();
	if ( $homepage_id > 0 ) {
		$hp_ctx   = rr_schema_post_context( $homepage_id, 'page', (string) get_permalink( $homepage_id ) );
		$hp_snips = get_option( RMB_SNIPPETS_KEY, array() );
		$hp_snips = is_array( $hp_snips ) ? $hp_snips : array();
		$hp_emit  = (bool) get_option( 'rrseo_emit_snippets', true );
		foreach ( rr_schema_collect_post_sources( $homepage_id, $hp_ctx, $hp_snips, $hp_emit ) as $src ) {
			$homepage_schema_types = array_values( array_unique( array_merge( $homepage_schema_types, $src['types'] ) ) );
		}
	}

	// Determine source label using the same priority chain as rr_resolve_business_facts().
	$source = 'bloginfo_fallback';
	if ( ! empty( $config['business_facts'] ) && is_array( $config['business_facts'] ) ) {
		$source = 'manual_business_facts';
	} elseif ( ! empty( $config['schema_source_post_id'] ) ) {
		$src_facts = rr_extract_business_facts_from_schema( (int) $config['schema_source_post_id'] );
		if ( ! empty( $src_facts ) ) {
			$source = 'schema_source_post';
		}
	} elseif ( $homepage_id > 0 ) {
		$hp_facts = rr_extract_business_facts_from_schema( $homepage_id );
		if ( ! empty( $hp_facts ) ) {
			$source = 'homepage_schema';
		}
	}

	return array(
		'business_name'         => isset( $facts['business_name'] ) ? (string) $facts['business_name'] : '',
		'website'               => isset( $facts['website'] ) ? (string) $facts['website'] : '',
		'phone'                 => isset( $facts['phone'] ) ? (string) $facts['phone'] : '',
		'address'               => isset( $facts['address'] ) ? (string) $facts['address'] : '',
		'schema_type'           => isset( $facts['schema_type'] ) ? (string) $facts['schema_type'] : '',
		'entity_id'             => isset( $facts['entity_id'] ) ? (string) $facts['entity_id'] : '',
		'primary_services'      => isset( $facts['primary_services'] ) ? (array) $facts['primary_services'] : array(),
		'service_area'          => isset( $facts['service_area'] ) ? (array) $facts['service_area'] : array(),
		'homepage_schema_types' => $homepage_schema_types,
		'source'                => $source,
		'warnings'              => isset( $facts['warnings'] ) ? (array) $facts['warnings'] : array(),
	);
}

// ── Schema inventory (issue #30) ─────────────────────────────────────────────

// Max recursion depth when walking JSON-LD; deeper structures are ignored.
define( 'RR_SCHEMA_WALK_MAX_DEPTH', 12 );

// Max URLs fetched from the public frontend in one schema-audit request.
define( 'RR_AEO_PUBLIC_INSPECT_MAX', 25 );

/**
 * Normalizes a JSON-LD @type value: strips schema.org namespace prefixes.
 *
 * @param string $type Raw @type string.
 * @return string
 */
function rr_schema_normalize_type( string $type ): string {
	return (string) preg_replace( '#^(https?://schema\.org/|schema:)#i', '', trim( $type ) );
}

/**
 * Walks any supported JSON-LD shape and inventories its entities.
 *
 * Handles a single object, a bare array of objects, an @graph envelope,
 * array-valued @type, and typed entities nested inside property values (for
 * example a Service whose mainEntityOfPage is a WebPage). A node that carries
 * only an @id is a reference, not a definition, so it never counts as a type
 * and never counts toward duplicate-entity detection.
 *
 * @param mixed $data Decoded JSON-LD (or stored schema meta).
 * @return array{
 *   types: string[],
 *   entities: array<int, array{types: string[], id: string, path: string, node: array}>,
 *   references: string[],
 *   duplicate_ids: string[]
 * }
 */
function rr_schema_inventory( $data ): array {
	$entities   = array();
	$references = array();
	$walk       = function ( $node, string $path, int $depth ) use ( &$walk, &$entities, &$references ) {
		if ( ! is_array( $node ) || $depth > RR_SCHEMA_WALK_MAX_DEPTH ) {
			return;
		}
		if ( wp_is_numeric_array( $node ) ) {
			foreach ( $node as $i => $child ) {
				$walk( $child, $path . '[' . $i . ']', $depth + 1 );
			}
			return;
		}

		$id    = isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : '';
		$types = array();
		if ( isset( $node['@type'] ) ) {
			foreach ( (array) $node['@type'] as $t ) {
				if ( is_string( $t ) && '' !== trim( $t ) ) {
					$types[] = rr_schema_normalize_type( $t );
				}
			}
		}

		if ( ! empty( $types ) ) {
			$entities[] = array(
				'types' => array_values( array_unique( $types ) ),
				'id'    => $id,
				'path'  => $path,
				'node'  => $node,
			);
		} elseif ( '' !== $id && array( '@id' ) === array_keys( $node ) ) {
			$references[] = $id;
			return;
		}

		foreach ( $node as $key => $value ) {
			if ( is_string( $key ) && '@' === substr( $key, 0, 1 ) && '@graph' !== $key ) {
				continue;
			}
			$walk( $value, $path . '.' . $key, $depth + 1 );
		}
	};
	$walk( $data, '$', 0 );

	$types  = array();
	$id_cnt = array();
	foreach ( $entities as $e ) {
		foreach ( $e['types'] as $t ) {
			$types[ $t ] = true;
		}
		if ( '' !== $e['id'] ) {
			$id_cnt[ $e['id'] ] = ( $id_cnt[ $e['id'] ] ?? 0 ) + 1;
		}
	}

	return array(
		'types'         => array_keys( $types ),
		'entities'      => $entities,
		'references'    => array_values( array_unique( $references ) ),
		'duplicate_ids' => array_keys( array_filter( $id_cnt, fn( $n ) => $n > 1 ) ),
	);
}

/**
 * Extracts JSON-LD blocks from HTML, reporting invalid blocks individually.
 *
 * @param string $html HTML containing <script type="application/ld+json"> blocks.
 * @return array<int, array{valid: bool, data: mixed, error: string|null}>
 */
function rr_schema_extract_jsonld_blocks( string $html ): array {
	$blocks = array();
	if ( ! preg_match_all( '/<script\b[^>]*\btype\s*=\s*["\']application\/ld\+json["\'][^>]*>(.*?)<\/script\s*>/is', $html, $m ) ) {
		return $blocks;
	}
	foreach ( $m[1] as $raw ) {
		$raw  = trim( $raw );
		$data = json_decode( $raw, true );
		if ( '' === $raw || JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			$blocks[] = array(
				'valid' => false,
				'data'  => null,
				'error' => 'invalid_json',
			);
			continue;
		}
		$blocks[] = array(
			'valid' => true,
			'data'  => $data,
			'error' => null,
		);
	}
	return $blocks;
}

/**
 * Returns true when a managed snippet would emit on the given post's page.
 *
 * Static mirror of rmb_output_snippets() + rmb_snippet_matches_display() for
 * an anonymous public visitor on a singular page. Archive-only targeting
 * (term/tax) never applies to a post. Unknown display_on values do not apply,
 * as the emitter skips them.
 *
 * @param array $snippet Snippet record.
 * @param array $ctx     array{post_id: int, post_type: string, is_front: bool, path: string}.
 * @return bool
 */
function rr_snippet_applies_to_post( array $snippet, array $ctx ): bool {
	if ( ( $snippet['status'] ?? 'active' ) !== 'active' ) {
		return false;
	}
	if ( '' === (string) ( $snippet['content'] ?? '' ) ) {
		return false;
	}
	if ( ! isset( RR_SNIPPET_LOCATION_HOOKS[ (string) ( $snippet['location'] ?? 'footer' ) ] ) ) {
		return false;
	}
	if ( 'logged_in' === (string) ( $snippet['display_on_user'] ?? 'all' ) ) {
		return false;
	}

	$display_on = trim( (string) ( $snippet['display_on'] ?? 'sitewide' ) );
	switch ( $display_on ) {
		case 'sitewide':
		case 'all':
		case 'entire_website':
		case 'singular':
			return true;
		case 'home':
		case 'homepage':
		case 'front_page':
			return (bool) $ctx['is_front'];
		case 'all_pages':
			return 'page' === $ctx['post_type'];
		case 'all_posts':
			return 'page' !== $ctx['post_type'];
	}

	if ( 0 === strpos( $display_on, 'page_id:' ) || 0 === strpos( $display_on, 'post_id:' ) ) {
		$id = (int) substr( $display_on, 8 );
		return $id > 0 && $id === (int) $ctx['post_id'];
	}
	if ( 0 === strpos( $display_on, 'post_type:' ) ) {
		$slug = trim( substr( $display_on, 10 ) );
		return '' !== $slug && $slug === $ctx['post_type'];
	}
	if ( 0 === strpos( $display_on, 'url:' ) ) {
		$pattern = substr( $display_on, 4 );
		return '' !== $pattern && rtrim( $pattern, '/' ) === rtrim( (string) $ctx['path'], '/' );
	}
	if ( is_numeric( $display_on ) ) {
		$id = (int) $display_on;
		return $id > 0 && $id === (int) $ctx['post_id'];
	}
	return false;
}

/**
 * Collects per-source schema inventories for one post (stored + snippets).
 *
 * Public frontend evidence is added by the caller; it is not collected here.
 *
 * @param int   $post_id   Post ID.
 * @param array $ctx       Context for rr_snippet_applies_to_post().
 * @param array $snippets  Managed snippets option value.
 * @param bool  $emit_on   Whether snippet emission is globally enabled.
 * @return array<int, array> Source records: source, snippet_id (snippets only), types,
 *                           entities, references, duplicate_ids, invalid_blocks.
 */
function rr_schema_collect_post_sources( int $post_id, array $ctx, array $snippets, bool $emit_on ): array {
	$sources = array();

	$stored = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
	$inv    = rr_schema_inventory( is_array( $stored ) ? $stored : array() );
	if ( ! empty( $inv['types'] ) ) {
		$sources[] = array_merge(
			array( 'source' => 'native' ),
			$inv,
			array( 'invalid_blocks' => 0 )
		);
	}

	if ( $emit_on ) {
		foreach ( $snippets as $id => $snippet ) {
			if ( ! is_array( $snippet ) || ! rr_snippet_applies_to_post( $snippet, $ctx ) ) {
				continue;
			}
			$invalid = 0;
			$merged  = array(
				'types'         => array(),
				'entities'      => array(),
				'references'    => array(),
				'duplicate_ids' => array(),
			);
			foreach ( rr_schema_extract_jsonld_blocks( (string) $snippet['content'] ) as $block ) {
				if ( ! $block['valid'] ) {
					++$invalid;
					continue;
				}
				$b                       = rr_schema_inventory( $block['data'] );
				$merged['types']         = array_values( array_unique( array_merge( $merged['types'], $b['types'] ) ) );
				$merged['entities']      = array_merge( $merged['entities'], $b['entities'] );
				$merged['references']    = array_values( array_unique( array_merge( $merged['references'], $b['references'] ) ) );
				$merged['duplicate_ids'] = array_values( array_unique( array_merge( $merged['duplicate_ids'], $b['duplicate_ids'] ) ) );
			}
			if ( empty( $merged['types'] ) && 0 === $invalid ) {
				continue;
			}
			$sources[] = array_merge(
				array(
					'source'     => 'snippet',
					'snippet_id' => (string) $id,
				),
				$merged,
				array( 'invalid_blocks' => $invalid )
			);
		}
	}

	return $sources;
}

/**
 * Builds the schema-source context for a post (type, front-page flag, path).
 *
 * @param int    $post_id   Post ID.
 * @param string $post_type Post type.
 * @param string $url       Canonical public URL.
 * @return array{post_id: int, post_type: string, is_front: bool, path: string}
 */
function rr_schema_post_context( int $post_id, string $post_type, string $url ): array {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	return array(
		'post_id'   => $post_id,
		'post_type' => $post_type,
		'is_front'  => in_array( $post_id, array( (int) get_option( 'page_on_front', 0 ), (int) get_option( 'page_for_posts', 0 ) ), true ),
		'path'      => is_string( $path ) ? $path : '/',
	);
}

/**
 * Returns the first typed entity whose types intersect $wanted, or null.
 *
 * @param mixed    $data   Stored schema meta or decoded JSON-LD.
 * @param string[] $wanted Type names to look for.
 * @return array|null Entity node array, or null.
 */
function rr_schema_find_node( $data, array $wanted ): ?array {
	foreach ( rr_schema_inventory( $data )['entities'] as $e ) {
		if ( array_intersect( $e['types'], $wanted ) ) {
			return $e['node'];
		}
	}
	return null;
}

/**
 * Returns a per-URL schema type inventory across all canonical URLs.
 *
 * Missing-opportunity rules applied per URL:
 *   - Homepage or contact page without LocalBusiness or Organization schema.
 *   - URL path containing /service without Service schema.
 *   - URL path containing /faq or /questions without FAQPage schema.
 *   - Any non-homepage URL without BreadcrumbList schema.
 *
 * Global warnings are added when no URL across the full set has:
 *   - LocalBusiness or Organization schema (no_localbusiness_schema).
 *   - FAQPage schema (no_faqpage_anywhere).
 *   - BreadcrumbList schema (no_breadcrumblist_anywhere).
 *
 * Evidence sources (issue #30): stored schema (any supported JSON-LD shape,
 * walked recursively), active managed snippets that apply to the page, and --
 * only when options['inspect_public'] is set -- the public frontend HTML of a
 * bounded window of URLs. A URL is never reported as lacking schema merely
 * because a source was not inspected: each URL carries `public_schema`
 * ('not_inspected' | 'inspected' | 'unavailable') and the summary carries
 * `sources_inspected` and `complete`.
 *
 * @param array      $args             Optional args forwarded to rr_get_canonical_url_set().
 * @param array|null $canonical_result Pre-fetched rr_get_canonical_url_set() result, or null to fetch.
 * @param array      $options          Optional: inspect_public (bool), public_offset (int), public_limit (int).
 * @return array{
 *   urls: array,
 *   summary: array{
 *     total: int,
 *     with_schema: int,
 *     without_schema: int,
 *     types: array,
 *     coverage_pct: float
 *   },
 *   global_warnings: string[]
 * }
 */
function rr_aeo_compute_schema_audit( array $args = array(), ?array $canonical_result = null, array $options = array() ): array {
	$canonical   = $canonical_result ?? rr_get_canonical_url_set( $args );
	$site_base   = home_url( '/' );
	$urls_out    = array();
	$type_counts = array();
	$with_schema = 0;

	$global_has_local_entity = false;
	$global_has_faqpage      = false;
	$global_has_breadcrumb   = false;

	$snippets = get_option( RMB_SNIPPETS_KEY, array() );
	$snippets = is_array( $snippets ) ? $snippets : array();
	$emit_on  = (bool) get_option( 'rrseo_emit_snippets', true );

	$inspect_public = ! empty( $options['inspect_public'] );
	$public_offset  = max( 0, (int) ( $options['public_offset'] ?? 0 ) );
	$public_limit   = min( RR_AEO_PUBLIC_INSPECT_MAX, max( 1, (int) ( $options['public_limit'] ?? 20 ) ) );
	$public_ok      = 0;
	$public_tried   = 0;

	foreach ( $canonical['urls'] as $index => $entry ) {
		$post_id  = (int) $entry['post_id'];
		$url      = (string) $entry['url'];
		$raw_path = wp_parse_url( $url, PHP_URL_PATH );
		$norm     = rr_normalize_url_path( is_string( $raw_path ) ? $raw_path : '/' );
		$is_home  = ( '/' === $norm ) || ( $url === $site_base );

		$sources = rr_schema_collect_post_sources( $post_id, rr_schema_post_context( $post_id, (string) $entry['post_type'], $url ), $snippets, $emit_on );

		// Public frontend evidence for a bounded window of URLs.
		$public_status = 'not_inspected';
		$public_error  = null;
		if ( $inspect_public && $index >= $public_offset && $public_tried < $public_limit ) {
			++$public_tried;
			$post    = get_post( $post_id );
			$fetched = $post instanceof WP_Post ? rr_observe_fetch_frontend_html( $post ) : array(
				'html'  => null,
				'error' => 'post_not_found',
			);
			if ( null === $fetched['html'] ) {
				// A failed fetch never erases stored/snippet evidence.
				$public_status = 'unavailable';
				$public_error  = $fetched['error'];
			} else {
				$public_status = 'inspected';
				++$public_ok;
				$pub = array(
					'types'          => array(),
					'entities'       => array(),
					'references'     => array(),
					'duplicate_ids'  => array(),
					'invalid_blocks' => 0,
				);
				foreach ( rr_schema_extract_jsonld_blocks( $fetched['html'] ) as $block ) {
					if ( ! $block['valid'] ) {
						++$pub['invalid_blocks'];
						continue;
					}
					$b                    = rr_schema_inventory( $block['data'] );
					$pub['types']         = array_values( array_unique( array_merge( $pub['types'], $b['types'] ) ) );
					$pub['entities']      = array_merge( $pub['entities'], $b['entities'] );
					$pub['references']    = array_values( array_unique( array_merge( $pub['references'], $b['references'] ) ) );
					$pub['duplicate_ids'] = array_values( array_unique( array_merge( $pub['duplicate_ids'], $b['duplicate_ids'] ) ) );
				}
				if ( ! empty( $pub['types'] ) || $pub['invalid_blocks'] > 0 ) {
					$sources[] = array_merge( array( 'source' => 'public' ), $pub );
				}
			}
		}

		// Merge types across sources; typed @id definitions repeated across
		// sources are duplicate entities, references never are.
		$schema_types = array();
		$defined_ids  = array();
		$invalid      = 0;
		foreach ( $sources as $src ) {
			$schema_types = array_values( array_unique( array_merge( $schema_types, $src['types'] ) ) );
			$invalid     += (int) $src['invalid_blocks'];
			foreach ( $src['entities'] as $e ) {
				if ( '' !== $e['id'] ) {
					$defined_ids[ $e['id'] ] = ( $defined_ids[ $e['id'] ] ?? 0 ) + 1;
				}
			}
		}
		$has_schema = ! empty( $schema_types );
		if ( $has_schema ) {
			++$with_schema;
		}
		foreach ( $schema_types as $t ) {
			$type_counts[ $t ] = ( $type_counts[ $t ] ?? 0 ) + 1;
			if ( in_array( $t, RR_AEO_LOCAL_ENTITY_TYPES, true ) ) {
				$global_has_local_entity = true;
			}
			if ( 'FAQPage' === $t ) {
				$global_has_faqpage = true;
			}
			if ( 'BreadcrumbList' === $t ) {
				$global_has_breadcrumb = true;
			}
		}

		// Per-URL missing opportunity detection.
		$missing     = array();
		$is_contact  = false !== strpos( $norm, '/contact' );
		$is_service  = false !== strpos( $norm, '/service' );
		$is_faq_page = false !== strpos( $norm, '/faq' ) || false !== strpos( $norm, '/questions' );

		if ( ( $is_home || $is_contact ) && ! array_intersect( $schema_types, RR_AEO_LOCAL_ENTITY_TYPES ) ) {
			$missing[] = 'LocalBusiness';
		}
		if ( $is_service && ! in_array( 'Service', $schema_types, true ) ) {
			$missing[] = 'Service';
		}
		if ( $is_faq_page && ! in_array( 'FAQPage', $schema_types, true ) ) {
			$missing[] = 'FAQPage';
		}
		if ( ! $is_home && ! in_array( 'BreadcrumbList', $schema_types, true ) ) {
			$missing[] = 'BreadcrumbList';
		}

		$urls_out[] = array(
			'post_id'               => $post_id,
			'url'                   => $url,
			'type'                  => $entry['post_type'],
			'schema_types'          => $schema_types,
			'has_schema'            => $has_schema,
			'missing_opportunities' => $missing,
			'schema_sources'        => array_map(
				function ( $src ) {
					$row = array(
						'source'         => $src['source'],
						'types'          => $src['types'],
						'invalid_blocks' => $src['invalid_blocks'],
					);
					if ( isset( $src['snippet_id'] ) ) {
						$row['snippet_id'] = $src['snippet_id'];
					}
					return $row;
				},
				$sources
			),
			'duplicate_entity_ids'  => array_keys( array_filter( $defined_ids, fn( $n ) => $n > 1 ) ),
			'invalid_jsonld_blocks' => $invalid,
			'public_schema'         => $public_status,
			'public_schema_error'   => $public_error,
		);
	}

	$total        = count( $canonical['urls'] );
	$coverage_pct = $total > 0 ? round( 100.0 * $with_schema / $total, 1 ) : 100.0;

	$global_warnings = array();
	if ( ! $global_has_local_entity ) {
		$global_warnings[] = 'no_localbusiness_schema';
	}
	if ( ! $global_has_faqpage ) {
		$global_warnings[] = 'no_faqpage_anywhere';
	}
	if ( ! $global_has_breadcrumb ) {
		$global_warnings[] = 'no_breadcrumblist_anywhere';
	}

	$sources_inspected = array( 'native', 'snippets' );
	if ( $public_tried > 0 ) {
		$sources_inspected[] = 'public';
	}

	return array(
		'urls'            => $urls_out,
		'summary'         => array(
			'total'                  => $total,
			'with_schema'            => $with_schema,
			'without_schema'         => $total - $with_schema,
			'types'                  => $type_counts,
			'coverage_pct'           => $coverage_pct,
			'sources_inspected'      => $sources_inspected,
			'public_inspected_count' => $public_ok,
			'public_requested_count' => $public_tried,
			'complete'               => $total > 0 && $public_ok === $total,
		),
		'global_warnings' => $global_warnings,
		'note'            => 'without_schema counts URLs with no schema in the inspected sources only (stored schema and applicable snippets' .
			( $public_tried > 0 ? ', plus public HTML for the inspected window' : '; public HTML was not inspected' ) .
			'). It is not proof that a public page emits no JSON-LD unless summary.complete is true.',
	);
}

/**
 * Returns a sync comparison: canonical URL set vs sitemap vs llms.txt.
 *
 * Because rr_is_utility_url() applies the llms exclude_patterns during canonical
 * set construction, llms.txt always contains exactly the canonical URL set. The
 * meaningful distinction is therefore canonical+llms vs the XML sitemap, which
 * only indexes 'post' and 'page' post types (RR_AEO_SITEMAP_POST_TYPES).
 *
 * Sync score: 100 * (in_all_three count) / (canonical count). 100 when empty.
 * Sync status: 'synced' (score = 100), 'partial' (score >= 70), 'mismatch' (< 70).
 *
 * @param array|null $canonical_result Pre-fetched rr_get_canonical_url_set() result, or null to fetch.
 * @return array{
 *   canonical_url_count: int,
 *   sitemap_url_count: int,
 *   llms_url_count: int,
 *   in_all_three: string[],
 *   canonical_and_llms_not_sitemap: string[],
 *   sync_status: string,
 *   sync_score: int,
 *   warnings: string[]
 * }
 */
function rr_aeo_compute_source_sync( ?array $canonical_result = null ): array {
	$canonical_result = $canonical_result ?? rr_get_canonical_url_set();

	$canonical_urls = array();
	$sitemap_urls   = array();

	foreach ( $canonical_result['urls'] as $entry ) {
		$url              = (string) $entry['url'];
		$canonical_urls[] = $url;
		if ( in_array( $entry['post_type'], RR_AEO_SITEMAP_POST_TYPES, true ) ) {
			$sitemap_urls[] = $url;
		}
	}

	// Partition canonical into in-sitemap vs not-in-sitemap.
	// llms = canonical always, so every canonical URL is also in llms.txt.
	$sitemap_set                    = array_flip( $sitemap_urls );
	$in_all_three                   = array();
	$canonical_and_llms_not_sitemap = array();

	foreach ( $canonical_urls as $url ) {
		if ( isset( $sitemap_set[ $url ] ) ) {
			$in_all_three[] = $url;
		} else {
			$canonical_and_llms_not_sitemap[] = $url;
		}
	}

	$canonical_count = count( $canonical_urls );
	$in_all_count    = count( $in_all_three );
	$sync_score      = ( $canonical_count > 0 ) ? (int) round( 100.0 * $in_all_count / $canonical_count ) : 100;

	if ( 100 === $sync_score || 0 === $canonical_count ) {
		$sync_status = 'synced';
	} elseif ( $sync_score >= 70 ) {
		$sync_status = 'partial';
	} else {
		$sync_status = 'mismatch';
	}

	return array(
		'canonical_url_count'            => $canonical_count,
		'sitemap_url_count'              => count( $sitemap_urls ),
		'llms_url_count'                 => $canonical_count,
		'in_all_three'                   => $in_all_three,
		'canonical_and_llms_not_sitemap' => $canonical_and_llms_not_sitemap,
		'sync_status'                    => $sync_status,
		'sync_score'                     => $sync_score,
		'warnings'                       => array(),
	);
}

/**
 * Returns the top-level AEO/GEO readiness snapshot for this site.
 *
 * Scoring rubrics:
 *   entity_clarity (0-100):
 *     +25 each for non-empty business_name, phone, address, schema_type.
 *     +10 if entity_id is set. -10 per warning. Floor: 0. Ceiling: 100.
 *   canonical_source_guidance (0-100): equals source_sync.sync_score.
 *   schema_depth (0-100): coverage_pct minus 10 per global_warning. Floor: 0.
 *   llms_completeness (0-100):
 *     +20 each for non-empty intro, has_business_facts (v3.4.0: business_name
 *     plus >=2 of primary_services/service_area/common_questions/
 *     key_differentiators — see has_business_facts below), >=1 section,
 *     exclude_patterns, max_description_chars.
 *   overall: simple average of the four scores.
 *
 * signals.has_business_facts (v3.4.0, issue #10): business_facts.business_name
 * is non-empty AND at least two of primary_services, service_area,
 * common_questions, key_differentiators are populated. A bare business_name
 * is identity, not AEO-ready business facts.
 *
 * The data_depth_badge is always 'public-only' — the plugin has no knowledge of
 * whether GSC/GA4/GBP connectors are active in the external audit engine.
 *
 * @return array{
 *   generated_at: string,
 *   data_depth_badge: string,
 *   scores: array,
 *   signals: array,
 *   warnings: array
 * }
 */
function rr_aeo_compute_readiness(): array {
	// Fetch the canonical URL set once; pass to sub-callers to avoid repeated DB queries.
	$canonical_result = rr_get_canonical_url_set();

	$entity = rr_aeo_compute_entity_signals();
	$sync   = rr_aeo_compute_source_sync( $canonical_result );
	$schema = rr_aeo_compute_schema_audit( array(), $canonical_result );

	$config = get_option( RR_LLMS_CONFIG_KEY, array() );
	$config = is_array( $config ) ? $config : array();

	// Entity clarity score.
	$entity_score = 0;
	if ( '' !== $entity['business_name'] ) {
		$entity_score += 25;
	}
	if ( '' !== $entity['phone'] ) {
		$entity_score += 25;
	}
	if ( '' !== $entity['address'] ) {
		$entity_score += 25;
	}
	if ( '' !== $entity['schema_type'] ) {
		$entity_score += 25;
	}
	if ( '' !== $entity['entity_id'] ) {
		$entity_score += 10;
	}
	$warning_count = count( $entity['warnings'] );
	$entity_score  = max( 0, min( 100, $entity_score - ( $warning_count * 10 ) ) );

	// Schema depth score.
	$schema_score = max( 0.0, $schema['summary']['coverage_pct'] - ( count( $schema['global_warnings'] ) * 10 ) );

	// has_business_facts (v3.4.0, issue #10): a business_name alone is not
	// "AEO-ready" — at least two of the enrichment fields an AI assistant
	// would actually surface (services, area, FAQ, differentiators) must
	// also be populated.
	$business_facts    = ( ! empty( $config['business_facts'] ) && is_array( $config['business_facts'] ) ) ? $config['business_facts'] : array();
	$enrichment_fields = array( 'primary_services', 'service_area', 'common_questions', 'key_differentiators' );
	$enrichment_count  = 0;
	foreach ( $enrichment_fields as $ef ) {
		if ( ! empty( $business_facts[ $ef ] ) && is_array( $business_facts[ $ef ] ) ) {
			++$enrichment_count;
		}
	}
	$has_business_facts = ! empty( $business_facts['business_name'] ) && $enrichment_count >= 2;

	// llms completeness score.
	$llms_score       = 0;
	$has_sections_cfg = ! empty( $config['sections'] ) && is_array( $config['sections'] );
	if ( ! empty( $config['intro'] ) ) {
		$llms_score += 20;
	}
	if ( $has_business_facts ) {
		$llms_score += 20;
	}
	if ( $has_sections_cfg ) {
		$llms_score += 20;
	}
	if ( ! empty( $config['exclude_patterns'] ) ) {
		$llms_score += 20;
	}
	if ( isset( $config['max_description_chars'] ) && ( (int) $config['max_description_chars'] ) > 0 ) {
		$llms_score += 20;
	}

	$overall = (int) round( ( $entity_score + $sync['sync_score'] + $schema_score + $llms_score ) / 4 );

	// Signals.
	$has_homepage_lb = false;
	foreach ( $entity['homepage_schema_types'] as $t ) {
		if ( in_array( $t, RR_AEO_LOCAL_ENTITY_TYPES, true ) ) {
			$has_homepage_lb = true;
			break;
		}
	}

	// Aggregate warnings.
	$all_warnings = $entity['warnings'];
	foreach ( $schema['global_warnings'] as $gw ) {
		$all_warnings[] = $gw;
	}
	if ( 'mismatch' === $sync['sync_status'] ) {
		$all_warnings[] = 'source_sync_mismatch';
	}

	return array(
		'generated_at'     => gmdate( 'Y-m-d\TH:i:s+00:00' ),
		'data_depth_badge' => 'public-only',
		'scores'           => array(
			'entity_clarity'            => $entity_score,
			'canonical_source_guidance' => $sync['sync_score'],
			'schema_depth'              => (int) round( $schema_score ),
			'llms_completeness'         => $llms_score,
			'overall'                   => $overall,
		),
		'signals'          => array(
			'has_business_facts'                => $has_business_facts,
			'business_facts_source'             => $entity['source'],
			'has_homepage_localbusiness_schema' => $has_homepage_lb,
			'has_llms_config'                   => ! empty( $config ),
			'canonical_url_count'               => $sync['canonical_url_count'],
			'sitemap_llms_in_sync'              => ( 'synced' === $sync['sync_status'] ),
			'schema_coverage_pct'               => $schema['summary']['coverage_pct'],
		),
		'warnings'         => $all_warnings,
	);
}

// ── REST callbacks ─────────────────────────────────────────────────────────────

/**
 * Handles GET /canonical-urls/preview — machine-readable canonical URL set.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_canonical_urls_preview( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	return new WP_REST_Response( rr_aeo_compute_canonical_preview(), 200 );
}

/**
 * Handles GET /aeo-geo/readiness — top-level AEO/GEO readiness snapshot.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_aeo_geo_readiness( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	return new WP_REST_Response( rr_aeo_compute_readiness(), 200 );
}

/**
 * Handles GET /aeo-geo/entity — structured entity signals.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_aeo_geo_entity( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	return new WP_REST_Response( rr_aeo_compute_entity_signals(), 200 );
}

/**
 * Handles GET /aeo-geo/schema-audit — per-canonical-URL schema type inventory.
 *
 * Query params: inspect_public (bool, default false) fetches the public HTML
 * of up to public_limit URLs (max RR_AEO_PUBLIC_INSPECT_MAX) starting at
 * public_offset; page through the set with successive offsets.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_aeo_geo_schema_audit( WP_REST_Request $request ): WP_REST_Response {
	return new WP_REST_Response(
		rr_aeo_compute_schema_audit(
			array(),
			null,
			array(
				'inspect_public' => (bool) $request->get_param( 'inspect_public' ),
				'public_offset'  => (int) $request->get_param( 'public_offset' ),
				'public_limit'   => (int) $request->get_param( 'public_limit' ),
			)
		),
		200
	);
}

/**
 * Handles GET /aeo-geo/source-sync — three-way URL set sync check.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_aeo_geo_source_sync( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	return new WP_REST_Response( rr_aeo_compute_source_sync(), 200 );
}
