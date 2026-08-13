<?php
/**
 * Module/Script Name: RankRocket SEO -- FAQ Schema (issue #22 Stage 1)
 * Path: includes/class-rrseo-faq.php
 *
 * Description:
 * REST-managed FAQPage schema: GET/POST/DELETE /faq/{post_id} merges (or
 * removes) an FAQPage node onto the post's existing _rrseo_schema_graph
 * meta without disturbing other nodes already registered there (e.g.
 * LocalBusiness, Service) -- rmb_schema_set() itself has no such merge; it
 * always replaces the whole stored value wholesale. Stage 1 scope: schema
 * only. Visible Q&A HTML emission (the after_content/before_content
 * positioning from the original issue) is deferred to a later stage --
 * this plugin has no the_content filter precedent to build on yet, and it
 * was judged lower-risk to ship the schema half alone first.
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

if ( ! defined( 'RR_FAQ_QUESTION_MAX' ) ) {
	define( 'RR_FAQ_QUESTION_MAX', 500 );
}
if ( ! defined( 'RR_FAQ_ANSWER_MAX' ) ) {
	define( 'RR_FAQ_ANSWER_MAX', 2000 );
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
 * existing schema graph. Replaces any previously-stored FAQPage node;
 * every other node (LocalBusiness, Service, ...) is left untouched.
 *
 * @param int   $post_id     Post ID.
 * @param mixed $items_raw   Raw items[] payload.
 * @param bool  $dry_run     True to validate and return the would-be
 *                            result without writing.
 * @return array{status: string, errors?: string[], warnings?: string[],
 *               items?: array, node?: array}
 */
function rr_faq_set( int $post_id, $items_raw, $dry_run = false ): array {
	$validation = rr_validate_faq_items( $items_raw );
	if ( ! empty( $validation['errors'] ) ) {
		return array(
			'status' => 'invalid',
			'errors' => $validation['errors'],
		);
	}

	$node           = rr_faq_build_node( (string) get_permalink( $post_id ), $validation['normalized'] );
	$existing_graph = get_post_meta( $post_id, RR_SCHEMA_META_KEY, true );
	$merged_graph   = rr_schema_merge_node( $existing_graph, $node, 'FAQPage' );

	if ( ! $dry_run ) {
		update_post_meta( $post_id, RR_SCHEMA_META_KEY, $merged_graph );
	}

	return array(
		'status'   => $dry_run ? 'simulated' : 'saved',
		'warnings' => $validation['warnings'],
		'items'    => $validation['normalized'],
		'node'     => $node,
	);
}

/**
 * Removes the FAQPage node from the post's schema graph, if present.
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

	return array( 'status' => 'deleted' );
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

	return rest_ensure_response(
		array(
			'post_id' => $post_id,
			'items'   => rr_faq_extract_items( rr_faq_get( $post_id ) ),
		)
	);
}

/**
 * Handles POST /faq/{post_id} -- validates and merges an FAQPage node onto
 * the post's schema graph.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_faq_set( WP_REST_Request $request ) {
	$post_id = intval( $request->get_param( 'post_id' ) );
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'invalid_post', 'Post not found', array( 'status' => 404 ) );
	}

	$dry_run = (bool) $request->get_param( 'dry_run' );
	$result  = rr_faq_set( $post_id, $request->get_param( 'items' ), $dry_run );

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
