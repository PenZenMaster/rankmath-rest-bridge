<?php
/**
 * Module/Script Name: RankRocket SEO — llms.txt Generator
 * Path: includes/class-rrseo-llms.php
 *
 * Description:
 * Section classifier, business_facts resolver, and full llms.txt renderer for P1.
 * All functions consume rr_get_canonical_url_set() from class-rrseo-canonical.php
 * so that llms.txt, its preview endpoint, and the sitemaps always reflect the same
 * canonical URL set.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-04-29
 * Last Modified Date: 2026-04-29
 *
 * Comments:
 * v1.00 - P1 implementation: section classifier, business_facts, rr_render_llms_txt,
 *         rr_validate_llms_section, rmb_llms_preview REST handler.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Pattern matching helpers ──────────────────────────────────────────────────

/**
 * Prefix-matches a normalised URL path against a pattern string.
 *
 * Pattern strings are NOT trailing-slash normalised (v4 §16.2) because some
 * patterns are partial slug stems (e.g. '/house-painter-near-me-in-') rather
 * than directory prefixes. Only the URL path is normalised before comparison.
 *
 * @param string $normalized_path Lowercased path with leading and trailing slash.
 * @param string $pattern         Pattern string used as-is (lowercased for comparison only).
 * @return bool
 */
function rr_path_matches_pattern( string $normalized_path, string $pattern ): bool {
	return str_starts_with( strtolower( $normalized_path ), strtolower( $pattern ) );
}

/**
 * Exact-matches a normalised URL path against a configured exact_path value.
 *
 * Both sides are normalised (lowercase + leading + trailing slash) before comparison.
 *
 * @param string $normalized_path Normalised URL path.
 * @param string $exact_path      Configured exact path string.
 * @return bool
 */
function rr_path_is_exact( string $normalized_path, string $exact_path ): bool {
	return strtolower( $normalized_path ) === strtolower( rr_normalize_url_path( $exact_path ) );
}

// ── Section classifier ────────────────────────────────────────────────────────

/**
 * Applies automatic classification rules to a post (no meta-override check).
 *
 * @param WP_Post $post             Post object.
 * @param string  $normalized_path  Normalised URL path.
 * @param array   $sections_config  The 'sections' value from the llms config option.
 * @return array{ section: string, effective_section: string, method: string, warnings: array }
 */
function rr_auto_classify_section( WP_Post $post, string $normalized_path, array $sections_config ): array {
	if ( empty( $sections_config ) ) {
		return rr_fallback_section( $post );
	}

	// Build a flat list of matches: [ section_key, order, specificity_rank ].
	// Specificity rank: 1 = exact_paths (most specific), 2 = url_patterns, 3 = post_types.
	$matches = array();

	foreach ( $sections_config as $key => $section ) {
		$order = isset( $section['order'] ) ? (int) $section['order'] : 99;

		// exact_paths check.
		if ( ! empty( $section['exact_paths'] ) && is_array( $section['exact_paths'] ) ) {
			foreach ( $section['exact_paths'] as $exact ) {
				if ( rr_path_is_exact( $normalized_path, (string) $exact ) ) {
					$matches[] = array(
						'section' => $key,
						'order'   => $order,
						'spec'    => 1,
					);
					break;
				}
			}
		}

		// url_patterns prefix check.
		if ( ! empty( $section['url_patterns'] ) && is_array( $section['url_patterns'] ) ) {
			foreach ( $section['url_patterns'] as $pattern ) {
				if ( rr_path_matches_pattern( $normalized_path, (string) $pattern ) ) {
					$matches[] = array(
						'section' => $key,
						'order'   => $order,
						'spec'    => 2,
					);
					break;
				}
			}
		}

		// post_types check.
		if ( ! empty( $section['post_types'] ) && is_array( $section['post_types'] ) ) {
			if ( in_array( $post->post_type, $section['post_types'], true ) ) {
				$matches[] = array(
					'section' => $key,
					'order'   => $order,
					'spec'    => 3,
				);
			}
		}
	}

	if ( empty( $matches ) ) {
		return rr_fallback_section( $post );
	}

	// Sort: lowest order wins; on tie, lowest specificity rank (most specific) wins.
	usort(
		$matches,
		function ( $a, $b ) {
			if ( $a['order'] !== $b['order'] ) {
				return $a['order'] <=> $b['order'];
			}
			return $a['spec'] <=> $b['spec'];
		}
	);

	$winner   = $matches[0];
	$warnings = array();

	// Detect genuine ties (same order AND same specificity in different sections).
	$tied = array_filter(
		$matches,
		function ( $m ) use ( $winner ) {
			return $m['order'] === $winner['order'] && $m['spec'] === $winner['spec'] && $m['section'] !== $winner['section'];
		}
	);
	if ( ! empty( $tied ) ) {
		$tied_keys  = array_merge( array( $winner['section'] ), array_column( array_values( $tied ), 'section' ) );
		$warnings[] = array(
			'code'             => 'section_match_tie',
			'matched_sections' => $tied_keys,
			'selected_section' => $winner['section'],
			'message'          => 'URL matched multiple sections with equal precedence; first match used.',
		);
	}

	return array(
		'section'           => $winner['section'],
		'effective_section' => $winner['section'],
		'method'            => 1 === $winner['spec'] ? 'exact_paths' : ( 2 === $winner['spec'] ? 'url_patterns' : 'post_types' ),
		'warnings'          => $warnings,
	);
}

/**
 * Default fallback classification when no configured section matches.
 *
 * Posts → educational_articles, everything else → core_business_pages.
 *
 * @param WP_Post $post Post object.
 * @return array
 */
function rr_fallback_section( WP_Post $post ): array {
	$section = 'post' === $post->post_type ? 'educational_articles' : 'core_business_pages';
	return array(
		'section'           => $section,
		'effective_section' => $section,
		'method'            => 'fallback',
		'warnings'          => array(),
	);
}

/**
 * Classifies a post into an llms.txt section.
 *
 * Priority (v4 §1):
 * 1. Explicit per-post meta (_rrseo_llms_section).
 *    - If the stored key is valid → use it.
 *    - If the stored key is stale (section removed from config) → fall back to
 *      automatic classification; return both stored and effective values plus a
 *      stale_section_key warning.
 * 2. Automatic classification via exact_paths, url_patterns, post_types, fallback.
 *
 * @param WP_Post $post             Post object.
 * @param string  $normalized_path  Normalised URL path from rr_normalize_url_path().
 * @param array   $sections_config  The 'sections' value from the llms config option (object form).
 * @return array{ section: string, effective_section: string, method: string, warnings: array }
 */
function rr_classify_url_section( WP_Post $post, string $normalized_path, array $sections_config ): array {
	$stored = get_post_meta( $post->ID, META_LLMS_SECTION, true );

	if ( '' !== $stored && is_string( $stored ) ) {
		if ( empty( $sections_config ) || isset( $sections_config[ $stored ] ) ) {
			// Valid stored value.
			return array(
				'section'           => $stored,
				'effective_section' => $stored,
				'method'            => 'meta',
				'warnings'          => array(),
			);
		}

		// Stale — fall back to automatic classification for output.
		$auto = rr_auto_classify_section( $post, $normalized_path, $sections_config );
		return array(
			'section'           => $stored,
			'effective_section' => $auto['section'],
			'method'            => 'meta_stale',
			'warnings'          => array_merge(
				array(
					array(
						'code'              => 'stale_section_key',
						'stored_section'    => $stored,
						'effective_section' => $auto['section'],
						'message'           => 'Stored llms_section key is not in current config. Automatic classification was used for output.',
					),
				),
				$auto['warnings']
			),
		);
	}

	return rr_auto_classify_section( $post, $normalized_path, $sections_config );
}

// ── Section validation ────────────────────────────────────────────────────────

/**
 * Validates that a section key exists in the current llms config.
 *
 * Returns true when valid, WP_Error when invalid.
 * An empty sections config means any value is accepted (will use fallback classification).
 *
 * @param string $section Section key to validate.
 * @return true|WP_Error
 */
function rr_validate_llms_section( string $section ) {
	$config          = get_option( RR_LLMS_CONFIG_KEY, get_option( 'rmb_llms_config', array() ) );
	$sections_config = ( isset( $config['sections'] ) && is_array( $config['sections'] ) && ! isset( $config['sections'][0] ) )
		? $config['sections']
		: array();

	// No sections configured — any value is accepted at write time.
	if ( empty( $sections_config ) ) {
		return true;
	}

	if ( isset( $sections_config[ $section ] ) ) {
		return true;
	}

	return new WP_Error(
		'invalid_llms_section',
		'The llms_section value is not a configured section key. Allowed: ' . implode( ', ', array_keys( $sections_config ) ),
		array(
			'status'           => 422,
			'allowed_sections' => array_keys( $sections_config ),
		)
	);
}

// ── business_facts validation ─────────────────────────────────────────────────

/**
 * Validates a business_facts payload before it is persisted (v3.4.0, issue #9).
 *
 * An empty array is always valid (clears manual business_facts, falling back
 * to schema/bloginfo resolution). A non-empty payload must include
 * business_name and description; every other key is optional but is type-
 * and size-checked when present.
 *
 * @param array $facts Proposed business_facts value.
 * @return true|WP_Error
 */
function rr_validate_llms_business_facts( array $facts ) {
	if ( empty( $facts ) ) {
		return true;
	}

	$errors = array();

	if ( empty( $facts['business_name'] ) || ! is_string( $facts['business_name'] ) ) {
		$errors[] = 'business_name is required and must be a non-empty string.';
	}
	if ( empty( $facts['description'] ) || ! is_string( $facts['description'] ) ) {
		$errors[] = 'description is required and must be a non-empty string.';
	}

	$string_fields = array(
		'business_name',
		'description',
		'tagline',
		'website',
		'phone',
		'address',
		'hours',
		'years_in_business',
		'schema_type',
		'entity_id',
	);
	foreach ( $string_fields as $field ) {
		if ( ! isset( $facts[ $field ] ) ) {
			continue;
		}
		if ( ! is_string( $facts[ $field ] ) ) {
			$errors[] = "{$field} must be a string.";
		} elseif ( strlen( $facts[ $field ] ) > 5000 ) {
			$errors[] = "{$field} must not exceed 5000 characters.";
		}
	}

	$list_fields = array( 'primary_services', 'service_area', 'key_differentiators' );
	foreach ( $list_fields as $field ) {
		if ( ! isset( $facts[ $field ] ) ) {
			continue;
		}
		if ( ! is_array( $facts[ $field ] ) ) {
			$errors[] = "{$field} must be an array of strings.";
			continue;
		}
		if ( count( $facts[ $field ] ) > 50 ) {
			$errors[] = "{$field} must not exceed 50 items.";
			continue;
		}
		foreach ( $facts[ $field ] as $item ) {
			if ( ! is_string( $item ) ) {
				$errors[] = "{$field} items must be strings.";
				break;
			}
		}
	}

	if ( isset( $facts['common_questions'] ) ) {
		if ( ! is_array( $facts['common_questions'] ) ) {
			$errors[] = 'common_questions must be an array of {question, answer} objects.';
		} elseif ( count( $facts['common_questions'] ) > 50 ) {
			$errors[] = 'common_questions must not exceed 50 items.';
		} else {
			foreach ( $facts['common_questions'] as $qa ) {
				$valid_qa = is_array( $qa )
					&& ! empty( $qa['question'] ) && is_string( $qa['question'] )
					&& ! empty( $qa['answer'] ) && is_string( $qa['answer'] );
				if ( ! $valid_qa ) {
					$errors[] = 'Each common_questions item must have non-empty string question and answer fields.';
					break;
				}
			}
		}
	}

	if ( ! empty( $errors ) ) {
		return new WP_Error(
			'invalid_business_facts',
			'One or more business_facts fields failed validation.',
			array(
				'status' => 422,
				'errors' => $errors,
			)
		);
	}

	return true;
}

// ── business_facts resolver ───────────────────────────────────────────────────

/**
 * Extracts LocalBusiness/Organization data from a post's _rrseo_schema_graph.
 *
 * Returns an empty array when no usable business schema is found.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function rr_extract_business_facts_from_schema( int $post_id ): array {
	$schema = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
	if ( ! $schema || ! is_array( $schema ) ) {
		return array();
	}

	$usable_types = array( 'LocalBusiness', 'Organization', 'ProfessionalService', 'Store', 'MedicalBusiness' );
	$type         = $schema['@type'] ?? '';
	if ( ! in_array( $type, $usable_types, true ) ) {
		return array();
	}

	$facts = array();
	if ( ! empty( $schema['name'] ) ) {
		$facts['business_name'] = $schema['name'];
	}
	if ( ! empty( $schema['url'] ) ) {
		$facts['website'] = $schema['url'];
	}
	if ( ! empty( $schema['telephone'] ) ) {
		$facts['phone'] = $schema['telephone'];
	}
	if ( ! empty( $schema['address'] ) ) {
		$addr = $schema['address'];
		if ( is_array( $addr ) ) {
			$parts            = array_filter(
				array(
					$addr['streetAddress'] ?? '',
					$addr['addressLocality'] ?? '',
					$addr['addressRegion'] ?? '',
					$addr['postalCode'] ?? '',
				)
			);
			$facts['address'] = implode( ', ', $parts );
		} elseif ( is_string( $addr ) ) {
			$facts['address'] = $addr;
		}
	}
	$facts['schema_type'] = $type;
	if ( ! empty( $schema['@id'] ) ) {
		$facts['entity_id'] = $schema['@id'];
	}

	return $facts;
}

/**
 * Resolves business facts using the priority chain (v4 §16.1).
 *
 * 1. Manual business_facts config.
 * 2. Schema from schema_source_post_id (if configured).
 * 3. Schema from homepage.
 * 4. WordPress site name / URL fallback.
 *
 * @param array $config llms config option value.
 * @return array{ business_name?: string, website?: string, phone?: string, address?: string, schema_type?: string, entity_id?: string, warnings?: array }
 */
function rr_resolve_business_facts( array $config ): array {
	$warnings = array();

	// 1. Manual config.
	if ( ! empty( $config['business_facts'] ) && is_array( $config['business_facts'] ) ) {
		return $config['business_facts'];
	}

	// 2. Explicit schema source post.
	if ( ! empty( $config['schema_source_post_id'] ) ) {
		$facts = rr_extract_business_facts_from_schema( (int) $config['schema_source_post_id'] );
		if ( ! empty( $facts ) ) {
			return $facts;
		}
		$warnings[] = array(
			'code'    => 'schema_source_missing_business_facts',
			'post_id' => (int) $config['schema_source_post_id'],
			'message' => 'Configured schema_source_post_id does not contain usable LocalBusiness or Organization schema.',
		);
	}

	// 3. Homepage schema.
	$homepage_id = (int) get_option( 'page_on_front' );
	if ( $homepage_id > 0 ) {
		$facts = rr_extract_business_facts_from_schema( $homepage_id );
		if ( ! empty( $facts ) ) {
			return $facts;
		}
	}

	// 4. WordPress fallback.
	return array(
		'business_name' => get_bloginfo( 'name' ),
		'website'       => rtrim( get_bloginfo( 'url' ), '/' ),
		'warnings'      => $warnings,
	);
}

// ── llms.txt renderer ─────────────────────────────────────────────────────────

/**
 * Renders the Business Facts block as an array of llms.txt lines.
 *
 * Returns an empty array when rr_resolve_business_facts() yields nothing
 * (only possible if the bloginfo fallback itself is empty). Covers the full
 * business_facts field set: identity, tagline/description, services, area,
 * differentiators, hours/years, contact, schema linkage, and a Common
 * Questions Q&A block (v3.4.0, issues #9/#10).
 *
 * @param array  $config llms config option value.
 * @param string $label  Section heading label.
 * @return string[]
 */
function rr_render_business_facts_lines( array $config, string $label = 'Business Facts' ): array {
	$facts = rr_resolve_business_facts( $config );
	if ( empty( $facts ) ) {
		return array();
	}

	$lines   = array();
	$lines[] = '## ' . $label;

	if ( ! empty( $facts['website'] ) ) {
		$lines[] = 'Website: ' . $facts['website'];
	}
	if ( ! empty( $facts['business_name'] ) ) {
		$lines[] = 'Business: ' . $facts['business_name'];
	}
	if ( ! empty( $facts['tagline'] ) ) {
		$lines[] = 'Tagline: ' . $facts['tagline'];
	}
	if ( ! empty( $facts['description'] ) ) {
		$lines[] = 'Description: ' . $facts['description'];
	}
	if ( ! empty( $facts['primary_services'] ) ) {
		$lines[] = 'Primary services: ' . implode( ', ', (array) $facts['primary_services'] );
	}
	if ( ! empty( $facts['service_area'] ) ) {
		$lines[] = 'Service area: ' . implode( ', ', (array) $facts['service_area'] );
	}
	if ( ! empty( $facts['key_differentiators'] ) ) {
		$lines[] = 'Key differentiators: ' . implode( ', ', (array) $facts['key_differentiators'] );
	}
	if ( ! empty( $facts['years_in_business'] ) ) {
		$lines[] = 'Years in business: ' . $facts['years_in_business'];
	}
	if ( ! empty( $facts['hours'] ) ) {
		$lines[] = 'Hours: ' . $facts['hours'];
	}
	if ( ! empty( $facts['phone'] ) ) {
		$lines[] = 'Phone: ' . $facts['phone'];
	}
	if ( ! empty( $facts['address'] ) ) {
		$lines[] = 'Address: ' . $facts['address'];
	}
	if ( ! empty( $facts['schema_type'] ) ) {
		$lines[] = 'Schema type: ' . $facts['schema_type'];
	}
	if ( ! empty( $facts['entity_id'] ) ) {
		$lines[] = 'Entity ID: ' . $facts['entity_id'];
	}
	$lines[] = '';

	if ( ! empty( $facts['common_questions'] ) && is_array( $facts['common_questions'] ) ) {
		$lines[] = '## Common Questions';
		foreach ( $facts['common_questions'] as $qa ) {
			if ( ! is_array( $qa ) || empty( $qa['question'] ) || empty( $qa['answer'] ) ) {
				continue;
			}
			$lines[] = '### ' . $qa['question'];
			$lines[] = (string) $qa['answer'];
			$lines[] = '';
		}
	}

	return $lines;
}

/**
 * Renders the full llms.txt content from the current config and canonical URL set.
 *
 * When sections config is provided (object form with 'label','order','url_patterns' etc.)
 * each canonical URL is classified into a section and grouped accordingly. Without a
 * sections config the renderer falls back to the simple Pages / Blog Posts structure.
 *
 * @param array $config llms config option value (from RR_LLMS_CONFIG_KEY).
 * @return array{
 *   content:   string,
 *   url_count: int,
 *   sections:  array<string, int>,
 *   excluded:  array,
 *   warnings:  array
 * }
 */
function rr_render_llms_txt( array $config ): array {
	$site_url  = rtrim( get_bloginfo( 'url' ), '/' );
	$site_name = get_bloginfo( 'name' );
	$tagline   = get_bloginfo( 'description' );
	$max_desc  = isset( $config['max_description_chars'] ) ? (int) $config['max_description_chars'] : 240;

	// Determine if we have a full sections config (object, not a legacy array).
	$sections_config = array();
	if ( ! empty( $config['sections'] ) && is_array( $config['sections'] ) && ! isset( $config['sections'][0] ) ) {
		$sections_config = $config['sections'];
	}

	// Fetch canonical URL set.
	$canonical = rr_get_canonical_url_set(
		array(
			'post_types'      => apply_filters( 'rrseo_allowed_post_types', RR_ALLOWED_POST_TYPES ),
			'max_description' => $max_desc,
		)
	);

	$site_warnings = $canonical['warnings'];
	$excluded      = $canonical['excluded'];

	// ── Header ─────────────────────────────────────────────────────────────────
	$lines   = array();
	$lines[] = "# {$site_name}";
	if ( $tagline ) {
		$lines[] = "> {$tagline}";
	}
	$lines[] = '';
	$lines[] = 'Generated by RankRocket SEO Control Layer to help AI assistants understand the business,'
		. ' services, service area, and preferred canonical pages.';
	$lines[] = '';

	if ( ! empty( $config['intro'] ) ) {
		$lines[] = (string) $config['intro'];
		$lines[] = '';
	}

	// Business Facts (v3.4.0, issues #9/#10): rendered here by default so
	// business identity appears in llms.txt even when no sections config
	// exists. Sites that explicitly place a 'business_facts' section in
	// their sections config keep it there instead (rendered once, below).
	if ( empty( $sections_config ) || ! isset( $sections_config['business_facts'] ) ) {
		$lines = array_merge( $lines, rr_render_business_facts_lines( $config ) );
	}

	// ── Section-based rendering (P1) ───────────────────────────────────────────
	$section_counts = array();

	if ( ! empty( $sections_config ) ) {
		// Classify each canonical URL into a section.
		$grouped = array();
		foreach ( $canonical['urls'] as $entry ) {
			$post = get_post( $entry['post_id'] );
			if ( ! $post ) {
				continue;
			}
			$path        = wp_parse_url( $entry['url'], PHP_URL_PATH );
			$norm_path   = rr_normalize_url_path( $path ? (string) $path : '/' );
			$classified  = rr_classify_url_section( $post, $norm_path, $sections_config );
			$section_key = $classified['effective_section'];

			if ( ! isset( $grouped[ $section_key ] ) ) {
				$grouped[ $section_key ] = array();
			}
			$grouped[ $section_key ][] = array_merge( $entry, array( 'classification' => $classified ) );

			if ( ! empty( $classified['warnings'] ) ) {
				foreach ( $classified['warnings'] as $w ) {
					$site_warnings[] = $w;
				}
			}
		}

		// Sort sections by order.
		$sorted_sections = $sections_config;
		uasort(
			$sorted_sections,
			function ( $a, $b ) {
				return ( isset( $a['order'] ) ? (int) $a['order'] : 99 ) <=> ( isset( $b['order'] ) ? (int) $b['order'] : 99 );
			}
		);
		$output_order = array_keys( $sorted_sections );

		// Render each section.
		foreach ( $output_order as $section_key ) {
			// ── Special sections ───────────────────────────────────────────────
			if ( 'business_facts' === $section_key ) {
				$label = $sections_config[ $section_key ]['label'] ?? 'Business Facts';
				$lines = array_merge( $lines, rr_render_business_facts_lines( $config, $label ) );
				continue;
			}

			if ( 'sitemaps' === $section_key ) {
				if ( ! isset( $config['include_sitemaps'] ) || ! empty( $config['include_sitemaps'] ) ) {
					$label   = $sections_config[ $section_key ]['label'] ?? 'Sitemaps';
					$lines[] = '## ' . $label;
					$lines[] = '- [XML Sitemap](' . $site_url . '/sitemap_index.xml): Includes canonical, crawlable, indexable pages.';
					$lines[] = '';
				}
				continue;
			}

			if ( 'ai_guidance' === $section_key ) {
				$label   = $sections_config[ $section_key ]['label'] ?? 'Preferred AI Source Guidance';
				$lines[] = '## ' . $label;
				$lines[] = '- Use the homepage, About page, Contact page, and service pages for business facts.';
				$lines[] = '- Use service pages for service descriptions.';
				$lines[] = '- Use location pages for city-specific relevance.';
				$lines[] = '- Ignore duplicate, redirected, numeric suffix, utility, or noindex URLs.';
				$lines[] = '- Do not infer services, locations, guarantees, credentials, or prices not stated on the website.';
				$lines[] = '';
				continue;
			}

			// ── URL sections ───────────────────────────────────────────────────
			$section_urls                   = isset( $grouped[ $section_key ] ) ? $grouped[ $section_key ] : array();
			$section_counts[ $section_key ] = count( $section_urls );

			if ( empty( $section_urls ) ) {
				continue;
			}

			$label   = $sections_config[ $section_key ]['label'] ?? ucwords( str_replace( '_', ' ', $section_key ) );
			$lines[] = '## ' . $label;
			foreach ( $section_urls as $entry ) {
				$desc    = '' !== $entry['description'] ? ': ' . $entry['description'] : '';
				$lines[] = '- [' . $entry['title'] . '](' . $entry['url'] . ')' . $desc;
			}
			$lines[] = '';
		}
	} else {
		// ── Simple fallback (P0 structure) ─────────────────────────────────────
		$pages_set = rr_get_canonical_url_set(
			array(
				'post_types'      => array( 'page' ),
				'max_description' => $max_desc,
			)
		);
		$posts_set = rr_get_canonical_url_set(
			array(
				'post_types'      => array( 'post' ),
				'max_description' => $max_desc,
			)
		);

		// Merge excluded and warnings from these sub-calls (canonical was already computed).
		foreach ( $pages_set['excluded'] as $e ) {
			$excluded[] = $e;
		}
		foreach ( $posts_set['excluded'] as $e ) {
			$excluded[] = $e;
		}

		if ( ! empty( $pages_set['urls'] ) ) {
			$lines[] = '## Pages';
			foreach ( $pages_set['urls'] as $entry ) {
				$desc    = '' !== $entry['description'] ? ': ' . $entry['description'] : '';
				$lines[] = '- [' . $entry['title'] . '](' . $entry['url'] . ')' . $desc;
			}
			$lines[]                 = '';
			$section_counts['pages'] = count( $pages_set['urls'] );
		}

		if ( ! empty( $posts_set['urls'] ) ) {
			$lines[] = '## Blog Posts';
			foreach ( $posts_set['urls'] as $entry ) {
				$desc    = '' !== $entry['description'] ? ': ' . $entry['description'] : '';
				$lines[] = '- [' . $entry['title'] . '](' . $entry['url'] . ')' . $desc;
			}
			$lines[]                      = '';
			$section_counts['blog_posts'] = count( $posts_set['urls'] );
		}
	}

	// ── Custom sections (legacy text blocks) ───────────────────────────────────
	// These are the old array-of-{heading, items} sections, now stored as 'custom_sections'.
	$custom_sections = isset( $config['custom_sections'] ) && is_array( $config['custom_sections'] )
		? $config['custom_sections']
		: array();
	// Legacy compat: if 'sections' is a numeric array, treat it as custom_sections.
	if ( empty( $custom_sections ) && ! empty( $config['sections'] ) && isset( $config['sections'][0] ) ) {
		$custom_sections = $config['sections'];
	}
	foreach ( $custom_sections as $section ) {
		if ( ! is_array( $section ) ) {
			continue;
		}
		$lines[] = '## ' . ( isset( $section['heading'] ) ? $section['heading'] : 'More' );
		$items   = isset( $section['items'] ) && is_array( $section['items'] ) ? $section['items'] : array();
		foreach ( $items as $item ) {
			$lines[] = '- ' . $item;
		}
		$lines[] = '';
	}

	$total_urls = count( $canonical['urls'] );

	return array(
		'content'   => implode( "\n", $lines ),
		'url_count' => $total_urls,
		'sections'  => $section_counts,
		'excluded'  => $excluded,
		'warnings'  => $site_warnings,
	);
}

// ── llms.txt preview REST handler ─────────────────────────────────────────────

/**
 * Handles GET /llms/preview — returns generated llms.txt content and diagnostics.
 *
 * No site state is changed (read-only). Supports ?format=text|json.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_llms_preview( WP_REST_Request $request ) {
	$config = get_option( RR_LLMS_CONFIG_KEY, get_option( 'rmb_llms_config', array() ) );
	$format = $request->get_param( 'format' ) ? sanitize_key( $request->get_param( 'format' ) ) : 'json';

	$result = rr_render_llms_txt( $config );

	if ( 'text' === $format ) {
		return new WP_REST_Response(
			$result['content'],
			200,
			array( 'Content-Type' => 'text/plain; charset=UTF-8' )
		);
	}

	return rest_ensure_response(
		array(
			'content'   => $result['content'],
			'url_count' => $result['url_count'],
			'sections'  => $result['sections'],
			'excluded'  => $result['excluded'],
			'warnings'  => $result['warnings'],
		)
	);
}
