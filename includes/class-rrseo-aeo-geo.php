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
 * Last Modified Date: 2026-05-01
 *
 * Comments:
 * v1.00 - Initial: five REST endpoints — /canonical-urls/preview,
 *         /aeo-geo/readiness, /aeo-geo/entity, /aeo-geo/schema-audit,
 *         /aeo-geo/source-sync.
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

	foreach ( $canonical['urls'] as $entry ) {
		$post_id = (int) $entry['post_id'];

		// Only post and page types appear in the XML sitemap.
		$in_sitemap = in_array( $entry['post_type'], RR_AEO_SITEMAP_POST_TYPES, true );

		// All canonical URLs are in llms.txt: rr_is_utility_url() already applies
		// llms exclude_patterns during canonical set construction, so no URL in
		// canonical['urls'] can match an exclude_pattern.
		$in_llms = true;

		// Schema membership.
		$schema       = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
		$has_schema   = is_array( $schema ) && ! empty( $schema['@type'] );
		$schema_types = array();
		if ( $has_schema ) {
			$schema_types = is_array( $schema['@type'] ) ? $schema['@type'] : array( $schema['@type'] );
		}

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
		$hp_schema = get_post_meta( $homepage_id, RR_SCHEMA_META_KEY, true );
		if ( is_array( $hp_schema ) && ! empty( $hp_schema['@type'] ) ) {
			$homepage_schema_types = is_array( $hp_schema['@type'] )
				? $hp_schema['@type']
				: array( $hp_schema['@type'] );
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
 * @param array      $args             Optional args forwarded to rr_get_canonical_url_set().
 * @param array|null $canonical_result Pre-fetched rr_get_canonical_url_set() result, or null to fetch.
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
function rr_aeo_compute_schema_audit( array $args = array(), ?array $canonical_result = null ): array {
	$canonical   = $canonical_result ?? rr_get_canonical_url_set( $args );
	$site_base   = home_url( '/' );
	$urls_out    = array();
	$type_counts = array();
	$with_schema = 0;

	$global_has_local_entity = false;
	$global_has_faqpage      = false;
	$global_has_breadcrumb   = false;

	foreach ( $canonical['urls'] as $entry ) {
		$post_id  = (int) $entry['post_id'];
		$url      = (string) $entry['url'];
		$raw_path = wp_parse_url( $url, PHP_URL_PATH );
		$norm     = rr_normalize_url_path( is_string( $raw_path ) ? $raw_path : '/' );
		$is_home  = ( '/' === $norm ) || ( $url === $site_base );

		$schema     = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
		$has_schema = is_array( $schema ) && ! empty( $schema['@type'] );

		$schema_types = array();
		if ( $has_schema ) {
			++$with_schema;
			$schema_types = is_array( $schema['@type'] )
				? $schema['@type']
				: array( $schema['@type'] );

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

	return array(
		'urls'            => $urls_out,
		'summary'         => array(
			'total'          => $total,
			'with_schema'    => $with_schema,
			'without_schema' => $total - $with_schema,
			'types'          => $type_counts,
			'coverage_pct'   => $coverage_pct,
		),
		'global_warnings' => $global_warnings,
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
 *     +20 each for non-empty intro, business_facts, >=1 section, exclude_patterns,
 *     max_description_chars.
 *   overall: simple average of the four scores.
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

	// llms completeness score.
	$llms_score       = 0;
	$has_sections_cfg = ! empty( $config['sections'] ) && is_array( $config['sections'] );
	if ( ! empty( $config['intro'] ) ) {
		$llms_score += 20;
	}
	if ( ! empty( $config['business_facts'] ) && is_array( $config['business_facts'] ) ) {
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
			'has_business_facts'                => ( ! empty( $config['business_facts'] ) && is_array( $config['business_facts'] ) ),
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
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_aeo_geo_schema_audit( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	return new WP_REST_Response( rr_aeo_compute_schema_audit(), 200 );
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
