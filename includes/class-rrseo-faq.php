<?php
/**
 * Module/Script Name: RankRocket SEO -- FAQ Schema + Content (issue #22)
 * Path: includes/class-rrseo-faq.php
 *
 * Description:
 * REST-managed FAQPage schema: GET/POST/DELETE /faq/{post_id} merges (or
 * removes) an FAQPage node onto the post's existing _rrseo_schema_graph
 * meta without disturbing other nodes already registered there (e.g.
 * LocalBusiness, Service) -- rmb_schema_set() itself has no such merge; it
 * always replaces the whole stored value wholesale. Stage 2 adds the
 * visible Q&A HTML emission the original issue also asked for: a
 * the_content filter (priority 20 -- confirmed clear of Elementor's own
 * the_content replacement at priority 9, see class-rrseo-actions.php-
 * adjacent research in issue #26) appends or prepends a rendered FAQ
 * block matching the stored schema, so Google never sees a content/schema
 * mismatch. Existing Stage-1-only FAQ entries (no display config ever
 * written) stay schema-only after upgrading -- visible emission only
 * activates once a display config is explicitly written.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-08-13
 * Last Modified Date: 2026-08-13
 *
 * Comments:
 * v1.00 - Initial release (Stage 1): schema only.
 * v2.00 - Stage 2 (issue #26): position/heading fields, the_content
 *         emitter, rr_faq_render_html().
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'RR_FAQ_QUESTION_MAX' ) ) {
	define( 'RR_FAQ_QUESTION_MAX', 500 );
}
if ( ! defined( 'RR_FAQ_ANSWER_MAX' ) ) {
	define( 'RR_FAQ_ANSWER_MAX', 2000 );
}

// Post meta key for visible-content display config ({position, heading}),
// deliberately separate from the schema graph itself -- same pattern as
// issue #23's strip_third_party config living apart from the schema it
// affects.
if ( ! defined( 'RR_FAQ_DISPLAY_KEY' ) ) {
	define( 'RR_FAQ_DISPLAY_KEY', '_rrseo_faq_display' );
}

if ( ! defined( 'RR_FAQ_POSITIONS' ) ) {
	define( 'RR_FAQ_POSITIONS', array( 'after_content', 'before_content', 'disabled' ) );
}

if ( ! defined( 'RR_FAQ_DEFAULT_HEADING' ) ) {
	define( 'RR_FAQ_DEFAULT_HEADING', 'Frequently Asked Questions' );
}


// ── Schema graph merge helpers (pure, unit-testable) ───────────────────────────
// Generic enough to reuse for any future single-purpose node (not FAQ-specific),
// but the only caller today is this module -- promote to the main file's schema
// section if a second consumer needs them.

/**
 * Normalizes any stored schema value (single node, bare array of nodes, or
 * a { "@graph": [...] } envelope) into a flat list of node objects.
 *
 * @param mixed $graph Value from get_post_meta( $post_id, RR_SCHEMA_META_KEY, true ).
 * @return array<int, array>
 */
function rr_schema_graph_nodes( $graph ): array {
	if ( empty( $graph ) || ! is_array( $graph ) ) {
		return array();
	}
	if ( isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
		return array_values( array_filter( $graph['@graph'], 'is_array' ) );
	}
	if ( isset( $graph['@type'] ) ) {
		return array( $graph );
	}
	if ( wp_is_numeric_array( $graph ) ) {
		return array_values( array_filter( $graph, 'is_array' ) );
	}
	return array();
}

/**
 * Replaces any existing node(s) of $replace_type with $new_node and
 * re-wraps the result as a canonical @graph envelope, leaving every other
 * node untouched. Re-posting the same type is idempotent (no duplicate
 * nodes accumulate).
 *
 * @param mixed  $existing_graph Current stored value (any shape).
 * @param array  $new_node       Node to merge in. Must include '@type'.
 * @param string $replace_type   @type value whose existing node(s) are replaced.
 * @return array{'@context': string, '@graph': array}
 */
function rr_schema_merge_node( $existing_graph, array $new_node, string $replace_type ): array {
	$kept   = rr_schema_nodes_excluding_type( $existing_graph, $replace_type );
	$kept[] = $new_node;

	return array(
		'@context' => 'https://schema.org',
		'@graph'   => $kept,
	);
}

/**
 * Removes all nodes of $remove_type from a stored graph.
 *
 * @param mixed  $existing_graph Current stored value (any shape).
 * @param string $remove_type    @type value to remove.
 * @return array{'@context': string, '@graph': array}|null Canonical
 *              envelope, or null when no nodes remain (caller should
 *              delete_post_meta() in that case rather than store an
 *              empty graph).
 */
function rr_schema_remove_node_type( $existing_graph, string $remove_type ) {
	$kept = rr_schema_nodes_excluding_type( $existing_graph, $remove_type );
	if ( empty( $kept ) ) {
		return null;
	}
	return array(
		'@context' => 'https://schema.org',
		'@graph'   => $kept,
	);
}

/**
 * Shared filter: every node from $existing_graph whose @type does NOT
 * include $type.
 *
 * @param mixed  $existing_graph Current stored value (any shape).
 * @param string $type           @type value to exclude.
 * @return array<int, array>
 */
function rr_schema_nodes_excluding_type( $existing_graph, string $type ): array {
	$nodes = rr_schema_graph_nodes( $existing_graph );
	return array_values(
		array_filter(
			$nodes,
			function ( $node ) use ( $type ) {
				$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
				return ! in_array( $type, $types, true );
			}
		)
	);
}


// ── FAQ-specific helpers (pure, unit-testable) ──────────────────────────────────

/**
 * Validates and normalizes a raw items[] payload for POST /faq/{post_id}.
 *
 * Character caps and a missing "?" are soft warnings, not rejections, per
 * the issue's own spec -- only missing/empty question or answer rejects.
 *
 * @param mixed $items Raw request value.
 * @return array{errors: string[], warnings: string[], normalized: array}
 */
function rr_validate_faq_items( $items ): array {
	if ( ! is_array( $items ) || empty( $items ) ) {
		return array(
			'errors'     => array( 'items must be a non-empty array of {question, answer} objects' ),
			'warnings'   => array(),
			'normalized' => array(),
		);
	}

	$errors     = array();
	$warnings   = array();
	$normalized = array();

	foreach ( $items as $index => $item ) {
		if ( ! is_array( $item ) ) {
			$errors[] = "items[{$index}] must be an object";
			continue;
		}

		$question = isset( $item['question'] ) ? sanitize_text_field( (string) $item['question'] ) : '';
		$answer   = isset( $item['answer'] ) ? wp_kses_post( (string) $item['answer'] ) : '';

		if ( '' === $question ) {
			$errors[] = "items[{$index}].question is required";
		}
		if ( '' === $answer ) {
			$errors[] = "items[{$index}].answer is required";
		}
		if ( '' === $question || '' === $answer ) {
			continue;
		}

		if ( strlen( $question ) > RR_FAQ_QUESTION_MAX ) {
			$warnings[] = "items[{$index}].question exceeds " . RR_FAQ_QUESTION_MAX . ' characters';
		}
		if ( strlen( $answer ) > RR_FAQ_ANSWER_MAX ) {
			$warnings[] = "items[{$index}].answer exceeds " . RR_FAQ_ANSWER_MAX . ' characters';
		}
		if ( '?' !== substr( rtrim( $question ), -1 ) ) {
			$warnings[] = "items[{$index}].question does not end with '?'";
		}

		$normalized[] = array(
			'question' => $question,
			'answer'   => $answer,
		);
	}

	if ( ! empty( $errors ) ) {
		return array(
			'errors'     => $errors,
			'warnings'   => $warnings,
			'normalized' => array(),
		);
	}

	return array(
		'errors'     => array(),
		'warnings'   => $warnings,
		'normalized' => $normalized,
	);
}

/**
 * Builds a schema.org FAQPage node from normalized {question, answer} items.
 *
 * @param string $faq_id_base Stable base for the node's @id (typically the
 *                             post permalink) -- '#faq' is appended.
 * @param array  $items       Normalized items from rr_validate_faq_items().
 * @return array
 */
function rr_faq_build_node( string $faq_id_base, array $items ): array {
	$main_entity = array();
	foreach ( $items as $item ) {
		$main_entity[] = array(
			'@type'          => 'Question',
			'name'           => $item['question'],
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $item['answer'],
			),
		);
	}

	return array(
		'@type'      => 'FAQPage',
		'@id'        => $faq_id_base . '#faq',
		'mainEntity' => $main_entity,
	);
}

/**
 * Extracts {question, answer} items back out of a stored FAQPage node.
 *
 * @param array|null $faq_node Node with @type FAQPage, or null.
 * @return array<int, array{question: string, answer: string}>
 */
function rr_faq_extract_items( $faq_node ): array {
	if ( ! is_array( $faq_node ) || empty( $faq_node['mainEntity'] ) || ! is_array( $faq_node['mainEntity'] ) ) {
		return array();
	}

	$items = array();
	foreach ( $faq_node['mainEntity'] as $entity ) {
		if ( ! is_array( $entity ) ) {
			continue;
		}
		$answer  = isset( $entity['acceptedAnswer']['text'] ) ? (string) $entity['acceptedAnswer']['text'] : '';
		$items[] = array(
			'question' => isset( $entity['name'] ) ? (string) $entity['name'] : '',
			'answer'   => $answer,
		);
	}
	return $items;
}


/**
 * Validates and normalizes the optional position/heading display fields
 * for POST /faq/{post_id} (issue #26).
 *
 * @param array $fields Raw input: position, heading.
 * @return array{errors: string[], normalized: array}
 */
function rr_validate_faq_display( array $fields ): array {
	$errors = array();

	$position = 'after_content';
	if ( array_key_exists( 'position', $fields ) && null !== $fields['position'] && '' !== $fields['position'] ) {
		$position = sanitize_text_field( (string) $fields['position'] );
	}
	if ( ! in_array( $position, RR_FAQ_POSITIONS, true ) ) {
		$errors[] = 'position must be one of: ' . implode( ', ', RR_FAQ_POSITIONS );
	}

	$heading = RR_FAQ_DEFAULT_HEADING;
	if ( array_key_exists( 'heading', $fields ) && null !== $fields['heading'] && '' !== $fields['heading'] ) {
		$heading = sanitize_text_field( (string) $fields['heading'] );
	}

	if ( ! empty( $errors ) ) {
		return array(
			'errors'     => $errors,
			'normalized' => array(),
		);
	}

	return array(
		'errors'     => array(),
		'normalized' => array(
			'position' => $position,
			'heading'  => $heading,
		),
	);
}

/**
 * Renders the visible Q&A HTML block for a set of FAQ items. Pure --
 * takes already-validated items (answer already wp_kses_post()-sanitized
 * at write time in rr_validate_faq_items(), so it's echoed verbatim here,
 * matching how this plugin's other pre-sanitized stored content -- schema,
 * snippets -- is emitted without re-escaping).
 *
 * @param string $heading Section heading text (escaped here).
 * @param array  $items   Items from rr_faq_extract_items() /
 *                        rr_validate_faq_items()'s normalized output.
 * @return string Empty string when $items is empty.
 */
function rr_faq_render_html( string $heading, array $items ): string {
	if ( empty( $items ) ) {
		return '';
	}

	$html  = '<section class="rrseo-faq">';
	$html .= '<h2 class="rrseo-faq-heading">' . esc_html( $heading ) . '</h2>';
	foreach ( $items as $item ) {
		$html .= '<div class="rrseo-faq-item">';
		$html .= '<h3 class="rrseo-faq-question">' . esc_html( $item['question'] ) . '</h3>';
		$html .= '<div class="rrseo-faq-answer">' . $item['answer'] . '</div>';
		$html .= '</div>';
	}
	$html .= '</section>';

	return $html;
}


// ── Pipeline (WordPress-bound: reads/writes post meta) ──────────────────────────

/**
 * Finds the currently-stored FAQPage node for a post, if any.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function rr_faq_get( int $post_id ) {
	$graph = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
	foreach ( rr_schema_graph_nodes( $graph ) as $node ) {
		$types = isset( $node['@type'] ) ? (array) $node['@type'] : array();
		if ( in_array( 'FAQPage', $types, true ) ) {
			return $node;
		}
	}
	return null;
}

/**
 * Validates and (unless dry-run) merges an FAQPage node onto the post's
 * existing schema graph, plus its visible-content display config.
 * Replaces any previously-stored FAQPage node; every other node
 * (LocalBusiness, Service, ...) is left untouched.
 *
 * @param int   $post_id     Post ID.
 * @param mixed $items_raw   Raw items[] payload.
 * @param array $display_raw Raw position/heading fields (issue #26).
 * @param bool  $dry_run     True to validate and return the would-be
 *                            result without writing.
 * @return array{status: string, errors?: string[], warnings?: string[],
 *               items?: array, node?: array, display?: array}
 */
function rr_faq_set( int $post_id, $items_raw, array $display_raw = array(), $dry_run = false ): array {
	$validation = rr_validate_faq_items( $items_raw );
	if ( ! empty( $validation['errors'] ) ) {
		return array(
			'status' => 'invalid',
			'errors' => $validation['errors'],
		);
	}

	$display_validation = rr_validate_faq_display( $display_raw );
	if ( ! empty( $display_validation['errors'] ) ) {
		return array(
			'status' => 'invalid',
			'errors' => $display_validation['errors'],
		);
	}
	$display = $display_validation['normalized'];

	$node           = rr_faq_build_node( (string) get_permalink( $post_id ), $validation['normalized'] );
	$existing_graph = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
	$merged_graph   = rr_schema_merge_node( $existing_graph, $node, 'FAQPage' );

	if ( ! $dry_run ) {
		update_post_meta( $post_id, RR_SCHEMA_META_KEY, $merged_graph );
		update_post_meta( $post_id, RR_FAQ_DISPLAY_KEY, $display );
	}

	return array(
		'status'   => $dry_run ? 'simulated' : 'saved',
		'warnings' => $validation['warnings'],
		'items'    => $validation['normalized'],
		'node'     => $node,
		'display'  => $display,
	);
}

/**
 * Removes the FAQPage node from the post's schema graph (and its display
 * config), if present.
 *
 * @param int $post_id Post ID.
 * @return array{status: string}
 */
function rr_faq_delete( int $post_id ): array {
	if ( null === rr_faq_get( $post_id ) ) {
		return array( 'status' => 'not_found' );
	}

	$existing_graph = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
	$remaining      = rr_schema_remove_node_type( $existing_graph, 'FAQPage' );

	if ( null === $remaining ) {
		delete_post_meta( $post_id, RR_SCHEMA_META_KEY );
	} else {
		update_post_meta( $post_id, RR_SCHEMA_META_KEY, $remaining );
	}
	delete_post_meta( $post_id, RR_FAQ_DISPLAY_KEY );

	return array( 'status' => 'deleted' );
}


// ── Front-end content emission (issue #26) ──────────────────────────────────────

// Priority 20: Elementor's own the_content replacement runs at priority 9
// (Frontend::THE_CONTENT_FILTER_PRIORITY, confirmed from Elementor's source
// while scoping issue #26) and only strips three hardcoded WordPress core
// filters afterward (wpautop, shortcode_unautop, wptexturize) -- never
// third-party plugin filters. Priority 20 always receives Elementor's
// already-rendered output as $content on Elementor pages, and behaves
// normally (raw post_content) on non-Elementor pages.
add_filter( 'the_content', 'rrseo_faq_append_to_content', 20 );

/**
 * Appends (or prepends) the rendered FAQ block to a singular post's
 * content, per its stored display config. Guarded to the main singular
 * Loop so it never fires on widgets, secondary queries, or feeds -- and,
 * deliberately, not on the direct apply_filters('the_content', ...) calls
 * class-rrseo-observe.php's diagnostic endpoints make (those aren't a
 * real Loop pass, so in_the_loop() is false there), so this plugin's own
 * appended content never pollutes its own observation tooling.
 *
 * Backward compatible with Stage-1-only FAQ entries: a post with a stored
 * FAQPage node but no display config (RR_FAQ_DISPLAY_KEY never written)
 * stays schema-only -- visible emission only activates once a display
 * config is explicitly written via POST /faq/{post_id}.
 *
 * @param string $content Post content so far (possibly already
 *                        transformed by earlier the_content filters).
 * @return string
 */
function rrseo_faq_append_to_content( $content ) {
	if ( is_feed() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return $content;
	}

	$display = get_post_meta( $post_id, RR_FAQ_DISPLAY_KEY, true );
	if ( ! is_array( $display ) || empty( $display ) ) {
		return $content;
	}

	$position = isset( $display['position'] ) ? $display['position'] : 'after_content';
	if ( 'disabled' === $position ) {
		return $content;
	}

	$items = rr_faq_extract_items( rr_faq_get( $post_id ) );
	if ( empty( $items ) ) {
		return $content;
	}

	$heading  = ! empty( $display['heading'] ) ? $display['heading'] : RR_FAQ_DEFAULT_HEADING;
	$faq_html = rr_faq_render_html( $heading, $items );

	if ( 'before_content' === $position ) {
		return $faq_html . $content;
	}
	return $content . $faq_html;
}


// ── REST handlers ─────────────────────────────────────────────────────────────

/**
 * Handles GET /faq/{post_id} -- returns the currently-stored FAQ items.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_faq_get( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'post_id' ) );
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
	}

	$display = get_post_meta( $post_id, RR_FAQ_DISPLAY_KEY, true );

	return rest_ensure_response(
		array(
			'post_id'  => $post_id,
			'items'    => rr_faq_extract_items( rr_faq_get( $post_id ) ),
			'position' => ( is_array( $display ) && isset( $display['position'] ) ) ? $display['position'] : null,
			'heading'  => ( is_array( $display ) && isset( $display['heading'] ) ) ? $display['heading'] : null,
		)
	);
}

/**
 * Handles POST /faq/{post_id} -- validates and merges an FAQPage node onto
 * the post's schema graph, plus its visible-content display config
 * (issue #26).
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_faq_set( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'post_id' ) );
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
	}

	$dry_run     = (bool) $request->get_param( 'dry_run' );
	$display_raw = array(
		'position' => $request->get_param( 'position' ),
		'heading'  => $request->get_param( 'heading' ),
	);
	$result      = rr_faq_set( $post_id, $request->get_param( 'items' ), $display_raw, $dry_run );

	if ( 'invalid' === $result['status'] ) {
		return new WP_Error(
			'validation_failed',
			'FAQ validation failed',
			array(
				'status' => 422,
				'errors' => $result['errors'],
			)
		);
	}

	if ( ! $dry_run ) {
		rr_audit_log(
			$post_id,
			'/faq',
			array(
				'faq' => array(
					'item_count' => count( $result['items'] ),
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
			'success'  => true,
			'warnings' => $result['warnings'],
			'items'    => $result['items'],
			'position' => $result['display']['position'],
			'heading'  => $result['display']['heading'],
		)
	);
}

/**
 * Handles DELETE /faq/{post_id} -- removes the FAQPage node, if any.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_faq_delete( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'post_id' ) );
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
	}

	$result = rr_faq_delete( $post_id );
	if ( 'not_found' === $result['status'] ) {
		return new WP_Error( 'not_found', 'No FAQ is registered for this post.', array( 'status' => 404 ) );
	}

	rr_audit_log(
		$post_id,
		'/faq',
		array( 'faq' => array( 'action' => 'deleted' ) ),
		rr_request_id( $request ),
		'written'
	);

	return rest_ensure_response(
		array(
			'post_id' => $post_id,
			'success' => true,
		)
	);
}
