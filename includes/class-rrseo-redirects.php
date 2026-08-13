<?php
/**
 * Module/Script Name: RankRocket SEO -- Redirects (v3.9.0, issue #21 Stage 1)
 * Path: includes/class-rrseo-redirects.php
 *
 * Description:
 * REST-managed 301/302/307/308 redirects so audit workflows can fix legacy
 * URL 404s (old sitemap paths, retired permalinks) without SFTP or a
 * third-party redirect plugin. Stage 1 scope: exact/prefix matching only,
 * relative targets only, single-hop loop rejection (source === target).
 * Regex matching, cross-domain targets, hit-count telemetry, and typed-
 * action/rollback integration are deferred to a follow-up issue. Rules are
 * stored in the rrseo_redirects option (no custom table, matching every
 * other option-backed module in this plugin) and applied on the front end
 * via an early template_redirect hook.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-08-13
 * Last Modified Date: 2026-08-13
 *
 * Comments:
 * v1.00 - Initial release. GET/POST /redirects, GET/POST/DELETE
 *         /redirects/{id}, POST /redirects/bulk, POST /redirects/preview.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Option key holding the full redirect rule set, keyed by id.
if ( ! defined( 'RR_REDIRECTS_KEY' ) ) {
	define( 'RR_REDIRECTS_KEY', 'rrseo_redirects' );
}

// Stage 1 match types. 'regex' is deferred to a follow-up issue.
if ( ! defined( 'RR_REDIRECT_MATCH_TYPES' ) ) {
	define( 'RR_REDIRECT_MATCH_TYPES', array( 'exact', 'prefix' ) );
}

if ( ! defined( 'RR_REDIRECT_STATUS_CODES' ) ) {
	define( 'RR_REDIRECT_STATUS_CODES', array( 301, 302, 307, 308 ) );
}

// WordPress core paths that must never be redirected away from.
if ( ! defined( 'RR_REDIRECT_BLOCKED_SOURCES' ) ) {
	define(
		'RR_REDIRECT_BLOCKED_SOURCES',
		array( '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php' )
	);
}


// ── Storage helpers ─────────────────────────────────────────────────────────

/**
 * Returns the full stored redirect set, keyed by id.
 *
 * @return array<string, array>
 */
function rr_redirect_list() {
	$redirects = get_option( RR_REDIRECTS_KEY, array() );
	return is_array( $redirects ) ? $redirects : array();
}

/**
 * Returns a single stored redirect by id, or null if absent.
 *
 * @param string $id Redirect id.
 * @return array|null
 */
function rr_redirect_get( $id ) {
	$redirects = rr_redirect_list();
	return isset( $redirects[ $id ] ) ? $redirects[ $id ] : null;
}

/**
 * Generates a unique, URL-safe id from a source path, avoiding collisions
 * with the supplied taken-id set. Mirrors the base/_1/_2 increment pattern
 * used by rmb_snippets_create() and rmb_snippets_bulk_create().
 *
 * @param string              $source    Validated, leading-slash source path.
 * @param array<string,mixed> $taken_ids Ids already in use (existing option
 *                                       entries plus any prepared-but-not-yet-
 *                                       persisted entries in the same batch).
 * @return string
 */
function rr_redirect_generate_id( $source, array $taken_ids ) {
	$base = sanitize_title( ltrim( $source, '/' ) );
	if ( '' === $base ) {
		$base = 'redirect';
	}

	$id      = $base;
	$attempt = 1;
	while ( isset( $taken_ids[ $id ] ) ) {
		$id = $base . '_' . $attempt;
		++$attempt;
	}
	return $id;
}


// ── Validation (pure, unit-testable) ──────────────────────────────────────────

/**
 * Validates and normalizes a redirect field set.
 *
 * @param array       $fields      Raw input: source, target, status_code,
 *                                 match_type, enabled.
 * @param string|null $existing_id When validating an update, the id of the
 *                                 redirect being edited (excluded from the
 *                                 source-collision check against itself).
 * @return array{errors: string[], warnings: string[], normalized: array}
 */
function rr_validate_redirect_fields( array $fields, $existing_id = null ) {
	$errors = array();

	$source_raw = isset( $fields['source'] ) ? sanitize_text_field( (string) $fields['source'] ) : '';
	if ( '' === $source_raw ) {
		$errors[] = 'source is required';
		$source   = '';
	} elseif ( 0 !== strpos( $source_raw, '/' ) ) {
		$errors[] = "source must start with '/': got '{$source_raw}'";
		$source   = '';
	} else {
		// Trailing slash is normalized away so matching is consistent
		// regardless of how the request URI arrives (same convention as
		// the /llms.txt route matcher's rtrim( $uri, '/' )).
		$source = ( '/' === $source_raw ) ? '/' : rtrim( $source_raw, '/' );
	}

	$target_raw = isset( $fields['target'] ) ? sanitize_text_field( (string) $fields['target'] ) : '';
	if ( '' === $target_raw ) {
		$errors[] = 'target is required';
		$target   = '';
	} elseif ( 0 !== strpos( $target_raw, '/' ) ) {
		$errors[] = "target must start with '/' (absolute cross-domain targets are not supported yet): got '{$target_raw}'";
		$target   = '';
	} else {
		$target = $target_raw;
	}

	if ( empty( $errors ) && $source === $target ) {
		$errors[] = 'source and target must not be identical (redirect loop)';
	}

	if ( empty( $errors ) ) {
		foreach ( RR_REDIRECT_BLOCKED_SOURCES as $blocked ) {
			if ( $source === $blocked || 0 === strpos( $source, rtrim( $blocked, '/' ) . '/' ) ) {
				$errors[] = "source '{$source}' targets a WordPress core path ('{$blocked}') and cannot be redirected";
				break;
			}
		}
	}

	$match_type = 'exact';
	if ( array_key_exists( 'match_type', $fields ) && null !== $fields['match_type'] && '' !== $fields['match_type'] ) {
		$match_type = sanitize_text_field( (string) $fields['match_type'] );
	}
	if ( ! in_array( $match_type, RR_REDIRECT_MATCH_TYPES, true ) ) {
		$errors[] = 'match_type must be one of: ' . implode( ', ', RR_REDIRECT_MATCH_TYPES );
	}

	$status_code = 301;
	if ( array_key_exists( 'status_code', $fields ) && null !== $fields['status_code'] && '' !== $fields['status_code'] ) {
		$status_code = absint( $fields['status_code'] );
	}
	if ( ! in_array( $status_code, RR_REDIRECT_STATUS_CODES, true ) ) {
		$errors[] = 'status_code must be one of: ' . implode( ', ', RR_REDIRECT_STATUS_CODES );
	}

	$enabled = true;
	if ( array_key_exists( 'enabled', $fields ) && null !== $fields['enabled'] ) {
		$enabled = (bool) $fields['enabled'];
	}

	if ( empty( $errors ) ) {
		foreach ( rr_redirect_list() as $existing_rule_id => $existing_rule ) {
			if ( $existing_rule_id === $existing_id ) {
				continue;
			}
			if ( ! empty( $existing_rule['enabled'] ) && isset( $existing_rule['source'] ) && $existing_rule['source'] === $source ) {
				$errors[] = "source '{$source}' is already used by redirect '{$existing_rule_id}'";
				break;
			}
		}
	}

	if ( ! empty( $errors ) ) {
		return array(
			'errors'     => $errors,
			'warnings'   => array(),
			'normalized' => array(),
		);
	}

	return array(
		'errors'     => array(),
		'warnings'   => array(),
		'normalized' => array(
			'source'      => $source,
			'target'      => $target,
			'match_type'  => $match_type,
			'status_code' => $status_code,
			'enabled'     => $enabled,
		),
	);
}


// ── Matching (pure, unit-testable) ────────────────────────────────────────────

/**
 * Finds the redirect rule that matches a request path, if any.
 *
 * Exact-type rules win outright. Among prefix-type rules, the longest
 * matching source wins (standard longest-prefix-match tie-break). Disabled
 * rules are never matched.
 *
 * @param string $request_path Leading-slash path, no query string.
 * @param array  $redirects    Full redirect set (as from rr_redirect_list()).
 * @return array|null The matched rule, or null.
 */
function rr_redirect_match( $request_path, array $redirects ) {
	$best     = null;
	$best_len = -1;

	foreach ( $redirects as $rule ) {
		if ( empty( $rule['enabled'] ) || empty( $rule['source'] ) ) {
			continue;
		}

		$source     = (string) $rule['source'];
		$match_type = isset( $rule['match_type'] ) ? (string) $rule['match_type'] : 'exact';

		if ( 'exact' === $match_type ) {
			if ( $source === $request_path ) {
				return $rule;
			}
			continue;
		}

		if ( 'prefix' === $match_type && 0 === strpos( $request_path, $source ) ) {
			$len = strlen( $source );
			if ( $len > $best_len ) {
				$best     = $rule;
				$best_len = $len;
			}
		}
	}

	return $best;
}

/**
 * Normalizes a raw request URI or preview URL down to a leading-slash path
 * with no query string, matching the normalization applied to stored
 * source values in rr_validate_redirect_fields().
 *
 * @param string $uri Raw URI (may include scheme/host/query).
 * @return string
 */
function rr_redirect_normalize_path( $uri ) {
	$path = wp_parse_url( (string) $uri, PHP_URL_PATH );
	if ( ! is_string( $path ) || '' === $path ) {
		$path = '/';
	} elseif ( 0 !== strpos( $path, '/' ) ) {
		$path = '/' . $path;
	}

	if ( '/' === $path ) {
		return '/';
	}
	$trimmed = rtrim( $path, '/' );
	return ( '' === $trimmed ) ? '/' : $trimmed;
}


// ── Pipeline (create/update/delete/bulk/preview) ──────────────────────────────

/**
 * Validates and (unless dry-run) persists a new redirect.
 *
 * @param array $fields  Raw input fields.
 * @param bool  $dry_run True to validate and return the would-be result
 *                       without writing.
 * @return array{status: string, errors?: string[], redirect?: array}
 */
function rr_redirect_create( array $fields, $dry_run = false ) {
	$validation = rr_validate_redirect_fields( $fields );
	if ( ! empty( $validation['errors'] ) ) {
		return array(
			'status' => 'invalid',
			'errors' => $validation['errors'],
		);
	}

	$normalized = $validation['normalized'];
	$id         = rr_redirect_generate_id( $normalized['source'], rr_redirect_list() );
	$now        = current_time( 'mysql' );

	$redirect = array_merge(
		array( 'id' => $id ),
		$normalized,
		array(
			'created_at' => $now,
			'updated_at' => $now,
		)
	);

	if ( $dry_run ) {
		return array(
			'status'   => 'simulated',
			'redirect' => $redirect,
		);
	}

	$redirects        = rr_redirect_list();
	$redirects[ $id ] = $redirect;
	update_option( RR_REDIRECTS_KEY, $redirects );
	rrseo_bust_option_cache( RR_REDIRECTS_KEY );
	rrseo_purge_rest_cache( array( 'status', 'redirects' ) );

	return array(
		'status'   => 'created',
		'redirect' => $redirect,
	);
}

/**
 * Validates and (unless dry-run) persists changes to an existing redirect.
 * The id is immutable once created, even if the source field changes.
 *
 * @param string $id      Redirect id.
 * @param array  $fields  Fields to change; omitted keys keep their stored
 *                        value (re-validated as a whole either way).
 * @param bool   $dry_run True to validate and return the would-be result
 *                        without writing.
 * @return array{status: string, errors?: string[], redirect?: array}
 */
function rr_redirect_update( $id, array $fields, $dry_run = false ) {
	$existing = rr_redirect_get( $id );
	if ( null === $existing ) {
		return array(
			'status' => 'not_found',
			'id'     => $id,
		);
	}

	$merged     = array_merge( $existing, $fields );
	$validation = rr_validate_redirect_fields( $merged, $id );
	if ( ! empty( $validation['errors'] ) ) {
		return array(
			'status' => 'invalid',
			'errors' => $validation['errors'],
		);
	}

	$redirect = array_merge(
		$existing,
		$validation['normalized'],
		array( 'updated_at' => current_time( 'mysql' ) )
	);

	if ( $dry_run ) {
		return array(
			'status'   => 'simulated',
			'redirect' => $redirect,
		);
	}

	$redirects        = rr_redirect_list();
	$redirects[ $id ] = $redirect;
	update_option( RR_REDIRECTS_KEY, $redirects );
	rrseo_bust_option_cache( RR_REDIRECTS_KEY );
	rrseo_purge_rest_cache( array( 'status', 'redirects' ) );

	return array(
		'status'   => 'updated',
		'redirect' => $redirect,
	);
}

/**
 * Deletes a stored redirect.
 *
 * @param string $id Redirect id.
 * @return array{status: string, id?: string, redirect?: array}
 */
function rr_redirect_delete( $id ) {
	$redirects = rr_redirect_list();
	if ( ! isset( $redirects[ $id ] ) ) {
		return array(
			'status' => 'not_found',
			'id'     => $id,
		);
	}

	$deleted = $redirects[ $id ];
	unset( $redirects[ $id ] );
	update_option( RR_REDIRECTS_KEY, $redirects );
	rrseo_bust_option_cache( RR_REDIRECTS_KEY );
	rrseo_purge_rest_cache( array( 'status', 'redirects' ) );

	return array(
		'status'   => 'deleted',
		'redirect' => $deleted,
	);
}

/**
 * Atomically creates multiple redirects: validates every item first
 * (including duplicate-source detection within the same batch), and only
 * writes if every item passes. Mirrors rmb_snippets_bulk_create()'s
 * per-item error shape ({index, error}).
 *
 * @param array $items Raw redirect field-sets.
 * @return array{status: string, errors?: array, redirects?: array}
 */
function rr_redirect_bulk_create( array $items ) {
	if ( empty( $items ) ) {
		return array(
			'status' => 'invalid',
			'errors' => array(
				array(
					'index' => null,
					'error' => 'redirects must be a non-empty array',
				),
			),
		);
	}

	$batch_max = rrseo_batch_max();
	if ( count( $items ) > $batch_max ) {
		return array(
			'status' => 'invalid',
			'errors' => array(
				array(
					'index' => null,
					'error' => "batch exceeds the maximum of {$batch_max} items",
				),
			),
		);
	}

	$taken        = rr_redirect_list();
	$seen_sources = array();
	$errors       = array();
	$prepared     = array();
	$now          = current_time( 'mysql' );

	foreach ( $items as $index => $item ) {
		if ( ! is_array( $item ) ) {
			$errors[] = array(
				'index' => $index,
				'error' => 'each redirect must be an object',
			);
			continue;
		}

		$source_check = isset( $item['source'] ) ? sanitize_text_field( (string) $item['source'] ) : '';
		if ( '' !== $source_check && isset( $seen_sources[ $source_check ] ) ) {
			$errors[] = array(
				'index' => $index,
				'error' => "source '{$source_check}' is duplicated elsewhere in this batch",
			);
			continue;
		}

		$validation = rr_validate_redirect_fields( $item );
		if ( ! empty( $validation['errors'] ) ) {
			$errors[] = array(
				'index' => $index,
				'error' => implode( '; ', $validation['errors'] ),
			);
			continue;
		}

		$normalized = $validation['normalized'];
		$id         = rr_redirect_generate_id( $normalized['source'], $taken );

		$seen_sources[ $normalized['source'] ] = true;
		$taken[ $id ]                          = true;
		$prepared[ $id ]                       = array_merge(
			array( 'id' => $id ),
			$normalized,
			array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
	}

	if ( ! empty( $errors ) ) {
		return array(
			'status' => 'invalid',
			'errors' => $errors,
		);
	}

	$redirects = array_merge( rr_redirect_list(), $prepared );
	update_option( RR_REDIRECTS_KEY, $redirects );
	rrseo_bust_option_cache( RR_REDIRECTS_KEY );
	rrseo_purge_rest_cache( array( 'status', 'redirects' ) );

	return array(
		'status'    => 'created',
		'redirects' => array_values( $prepared ),
	);
}

/**
 * Tests what would happen for a given URL without applying or persisting
 * anything.
 *
 * @param string $url URL or path to test.
 * @return array{would_redirect: bool, matched_rule_id?: string,
 *               target?: string, status_code?: int}
 */
function rr_redirect_preview( $url ) {
	$path  = rr_redirect_normalize_path( $url );
	$match = rr_redirect_match( $path, rr_redirect_list() );

	if ( null === $match ) {
		return array( 'would_redirect' => false );
	}

	return array(
		'would_redirect'  => true,
		'matched_rule_id' => $match['id'],
		'target'          => $match['target'],
		'status_code'     => $match['status_code'],
	);
}


// ── Front-end application ─────────────────────────────────────────────────────

add_action( 'template_redirect', 'rrseo_apply_redirects', 1 );

/**
 * Applies stored redirect rules on the front end. Runs at
 * template_redirect:1 so it fires before WordPress resolves its own 404 or
 * canonical-redirect handling. REST and cron requests are skipped.
 */
function rrseo_apply_redirects() {
	if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		return;
	}
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$redirects = rr_redirect_list();
	if ( empty( $redirects ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only path comparison against stored rules, never output or stored.
	$path  = rr_redirect_normalize_path( $_SERVER['REQUEST_URI'] );
	$match = rr_redirect_match( $path, $redirects );
	if ( null === $match ) {
		return;
	}

	wp_safe_redirect( home_url( $match['target'] ), $match['status_code'] );
	exit;
}


// ── REST handlers ─────────────────────────────────────────────────────────────

/**
 * Handles GET /redirects -- lists all stored redirects.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response
 */
function rmb_redirects_list( WP_REST_Request $request ) {
	unset( $request );
	$redirects = rr_redirect_list();
	return rest_ensure_response(
		array(
			'count'     => count( $redirects ),
			'redirects' => array_values( $redirects ),
		)
	);
}

/**
 * Handles GET /redirects/{id} -- returns a single redirect.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_redirects_get_single( WP_REST_Request $request ) {
	$id       = (string) $request->get_param( 'id' );
	$redirect = rr_redirect_get( $id );
	if ( null === $redirect ) {
		return new WP_Error( 'not_found', "Redirect '{$id}' not found.", array( 'status' => 404 ) );
	}
	return rest_ensure_response( $redirect );
}

/**
 * Handles POST /redirects -- creates a new redirect.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_redirects_create( WP_REST_Request $request ) {
	$fields  = array(
		'source'      => $request->get_param( 'source' ),
		'target'      => $request->get_param( 'target' ),
		'status_code' => $request->get_param( 'status_code' ),
		'match_type'  => $request->get_param( 'match_type' ),
		'enabled'     => $request->get_param( 'enabled' ),
	);
	$dry_run = (bool) $request->get_param( 'dry_run' );

	$result = rr_redirect_create( $fields, $dry_run );

	if ( 'invalid' === $result['status'] ) {
		return new WP_Error(
			'redirect_validation_failed',
			'Redirect validation failed.',
			array(
				'status' => 422,
				'errors' => $result['errors'],
			)
		);
	}

	return rest_ensure_response(
		array(
			'success'  => true,
			'dry_run'  => $dry_run,
			'redirect' => $result['redirect'],
		)
	);
}

/**
 * Handles POST /redirects/{id} -- updates an existing redirect.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_redirects_update( WP_REST_Request $request ) {
	$id = (string) $request->get_param( 'id' );

	$fields = array();
	foreach ( array( 'source', 'target', 'status_code', 'match_type', 'enabled' ) as $field ) {
		$val = $request->get_param( $field );
		if ( null !== $val ) {
			$fields[ $field ] = $val;
		}
	}
	$dry_run = (bool) $request->get_param( 'dry_run' );

	$result = rr_redirect_update( $id, $fields, $dry_run );

	if ( 'not_found' === $result['status'] ) {
		return new WP_Error( 'not_found', "Redirect '{$id}' not found.", array( 'status' => 404 ) );
	}
	if ( 'invalid' === $result['status'] ) {
		return new WP_Error(
			'redirect_validation_failed',
			'Redirect validation failed.',
			array(
				'status' => 422,
				'errors' => $result['errors'],
			)
		);
	}

	return rest_ensure_response(
		array(
			'success'  => true,
			'dry_run'  => $dry_run,
			'redirect' => $result['redirect'],
		)
	);
}

/**
 * Handles DELETE /redirects/{id} -- removes a redirect.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_redirects_delete( WP_REST_Request $request ) {
	$id     = (string) $request->get_param( 'id' );
	$result = rr_redirect_delete( $id );

	if ( 'not_found' === $result['status'] ) {
		return new WP_Error( 'not_found', "Redirect '{$id}' not found.", array( 'status' => 404 ) );
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'deleted' => $result['redirect'],
		)
	);
}

/**
 * Handles POST /redirects/bulk -- atomically creates multiple redirects.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_redirects_bulk_create( WP_REST_Request $request ) {
	$incoming = $request->get_param( 'redirects' );
	if ( ! is_array( $incoming ) || empty( $incoming ) ) {
		return new WP_Error( 'missing_redirects', 'redirects must be a non-empty array.', array( 'status' => 400 ) );
	}

	$result = rr_redirect_bulk_create( $incoming );

	if ( 'invalid' === $result['status'] ) {
		return new WP_Error(
			'validation_failed',
			'One or more redirects failed validation. No redirects were saved.',
			array(
				'status' => 422,
				'errors' => $result['errors'],
			)
		);
	}

	return rest_ensure_response(
		array(
			'success'   => true,
			'count'     => count( $result['redirects'] ),
			'redirects' => $result['redirects'],
		)
	);
}

/**
 * Handles POST /redirects/preview -- tests what would happen for a given
 * URL without applying or persisting anything.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_redirects_preview( WP_REST_Request $request ) {
	$url = (string) $request->get_param( 'url' );
	if ( '' === $url ) {
		return new WP_Error( 'missing_url', 'url is required.', array( 'status' => 400 ) );
	}
	return rest_ensure_response( rr_redirect_preview( $url ) );
}
