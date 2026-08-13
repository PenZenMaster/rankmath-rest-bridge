<?php
/**
 * Module/Script Name: RankRocket SEO -- Redirects (v3.9.0 Stage 1, v3.13.0 Stage 2, issue #21/#27)
 * Path: includes/class-rrseo-redirects.php
 *
 * Description:
 * REST-managed 301/302/307/308 redirects so audit workflows can fix legacy
 * URL 404s (old sitemap paths, retired permalinks) without SFTP or a
 * third-party redirect plugin. Rules are stored in the rrseo_redirects
 * option (no custom table, matching every other option-backed module in
 * this plugin) and applied on the front end via an early template_redirect
 * hook. Stage 2 adds regex matching (length + backtracking-safety capped),
 * cross-domain targets (global host allowlist), hit_count/last_hit
 * telemetry (write-throttled), multi-hop loop detection, and typed-action
 * engine integration (see includes/class-rrseo-actions.php).
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-08-13
 * Last Modified Date: 2026-08-13
 *
 * Comments:
 * v1.00 - Initial release (Stage 1). GET/POST /redirects, GET/POST/DELETE
 *         /redirects/{id}, POST /redirects/bulk, POST /redirects/preview.
 * v2.00 - Stage 2 (issue #27): regex match_type, cross-domain targets via
 *         allowlist, hit_count/last_hit telemetry, multi-hop loop
 *         detection. Typed-action wiring lives in class-rrseo-actions.php.
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

if ( ! defined( 'RR_REDIRECT_MATCH_TYPES' ) ) {
	define( 'RR_REDIRECT_MATCH_TYPES', array( 'exact', 'prefix', 'regex' ) );
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

// Regex source patterns longer than this are rejected outright (issue #27).
if ( ! defined( 'RR_REDIRECT_REGEX_MAX_LENGTH' ) ) {
	define( 'RR_REDIRECT_REGEX_MAX_LENGTH', 200 );
}

// Maximum hops rr_redirect_detect_chain_loop() follows before treating an
// unresolved chain as a loop.
if ( ! defined( 'RR_REDIRECT_MAX_CHAIN_HOPS' ) ) {
	define( 'RR_REDIRECT_MAX_CHAIN_HOPS', 10 );
}

// Minimum seconds between hit_count/last_hit writes for the same rule, to
// avoid an options-table write on every single front-end hit.
if ( ! defined( 'RR_REDIRECT_HIT_THROTTLE_SECONDS' ) ) {
	define( 'RR_REDIRECT_HIT_THROTTLE_SECONDS', 60 );
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


// ── Regex safety (pure, unit-testable) ──────────────────────────────────────────

/**
 * Wraps a regex pattern (no delimiters) in a delimiter character not
 * present in the pattern itself.
 *
 * @param string $pattern Undelimited PCRE pattern.
 * @return string|false Delimited pattern, or false when neither fallback
 *                       delimiter ('#', '~') is safe to use.
 */
function rr_redirect_regex_delimit( $pattern ) {
	$delimiter = ( false === strpos( $pattern, '#' ) ) ? '#' : '~';
	if ( false !== strpos( $pattern, $delimiter ) ) {
		return false;
	}
	return $delimiter . $pattern . $delimiter;
}

/**
 * Checks a regex source pattern for catastrophic-backtracking risk,
 * length, and basic PCRE validity before it's accepted as a redirect rule.
 * Does not guarantee safety against every possible ReDoS pattern -- a
 * length cap plus a common nested-quantifier heuristic, not a full regex
 * static analyzer.
 *
 * @param string $pattern Undelimited pattern (as stored in `source`).
 * @return string[] Error messages; empty when the pattern passes.
 */
function rr_redirect_regex_is_safe( $pattern ) {
	$errors = array();

	if ( strlen( $pattern ) > RR_REDIRECT_REGEX_MAX_LENGTH ) {
		$errors[] = 'pattern exceeds ' . RR_REDIRECT_REGEX_MAX_LENGTH . ' characters';
		return $errors;
	}

	// Heuristic: a quantified group containing another quantifier, e.g.
	// (a+)+ or (a*)*, is the classic catastrophic-backtracking shape.
	if ( preg_match( '/\([^()]*[+*][^()]*\)[+*]/', $pattern ) ) {
		$errors[] = 'pattern contains a nested repetition construct that risks catastrophic backtracking, e.g. (x+)+';
		return $errors;
	}

	$delimited = rr_redirect_regex_delimit( $pattern );
	if ( false === $delimited ) {
		$errors[] = 'pattern cannot be safely delimited (contains both # and ~)';
		return $errors;
	}

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- probing PCRE validity; a malformed pattern must not raise a warning here, it must return a validation error instead.
	if ( false === @preg_match( $delimited, '' ) ) {
		$errors[] = 'pattern is not valid PCRE syntax';
	}

	return $errors;
}


/**
 * Tests whether a (previously validated-safe) regex pattern matches a
 * literal string. Used as a safety net so a regex source can't accidentally
 * match a WordPress core path -- a defensive probe, not part of the
 * chain-loop walk (regex sources never participate in that).
 *
 * @param string $pattern     Undelimited, already-validated-safe pattern.
 * @param string $literal     Literal string to test the pattern against.
 * @return bool
 */
function rr_redirect_regex_matches_literal( $pattern, $literal ) {
	$delimited = rr_redirect_regex_delimit( $pattern );
	if ( false === $delimited ) {
		return false;
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- pattern already passed rr_redirect_regex_is_safe(); suppressing a warning on a defensive literal-string probe, not swallowing a real validation error.
	return 1 === @preg_match( $delimited, $literal );
}


// ── Cross-domain target allowlist (pure/filter-bound, unit-testable) ───────────

/**
 * Checks whether an absolute (http/https) target URL's host is on the
 * site-configured allowlist. Empty by default -- absolute targets are
 * rejected unless a site owner explicitly opts hosts in via the
 * rrseo_redirect_allowed_hosts filter (issue #27; matches how other
 * allowlists in this plugin, e.g. rrseo_allowed_post_types, are
 * filter-based rather than a new option).
 *
 * @param string $target Raw target value.
 * @return bool
 */
function rr_redirect_is_allowed_absolute_target( $target ) {
	$parts = wp_parse_url( $target );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return false;
	}
	if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}

	$allowed = array_map( 'strtolower', (array) apply_filters( 'rrseo_redirect_allowed_hosts', array() ) );
	return in_array( strtolower( $parts['host'] ), $allowed, true );
}


// ── Multi-hop loop detection (pure, unit-testable) ──────────────────────────────

/**
 * Detects whether source -> target would form a redirect loop, either
 * directly (source === target) or by chaining through other exact-type
 * rules' source/target pairs (A -> B -> A, or longer). Prefix and regex
 * rules are not followed as chain hops -- only exact-type rules resolve
 * deterministically enough to safely chain through.
 *
 * @param string      $source       Candidate source (leading-slash path).
 * @param string      $target       Candidate target.
 * @param array       $all_redirects Full redirect set (as from rr_redirect_list()).
 * @param string|null $self_id      When validating an update, the id being
 *                                  edited (excluded from the chain walk so
 *                                  its own stale entry doesn't interfere).
 * @return bool True when a loop is found, or the chain exceeds
 *              RR_REDIRECT_MAX_CHAIN_HOPS without resolving.
 */
function rr_redirect_detect_chain_loop( $source, $target, array $all_redirects, $self_id = null ) {
	$current = $target;
	$seen    = array( $source => true );

	for ( $hop = 0; $hop < RR_REDIRECT_MAX_CHAIN_HOPS; $hop++ ) {
		if ( isset( $seen[ $current ] ) ) {
			return true;
		}
		$seen[ $current ] = true;

		$next = null;
		foreach ( $all_redirects as $id => $rule ) {
			if ( $id === $self_id ) {
				continue;
			}
			$rule_match_type = isset( $rule['match_type'] ) ? $rule['match_type'] : 'exact';
			if ( 'exact' === $rule_match_type && isset( $rule['source'], $rule['target'] ) && $rule['source'] === $current ) {
				$next = $rule['target'];
				break;
			}
		}

		if ( null === $next ) {
			return false;
		}
		$current = $next;
	}

	return true;
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

	// match_type is resolved first: regex sources skip the leading-slash
	// path-shape rule entirely and are validated as patterns instead.
	$match_type = 'exact';
	if ( array_key_exists( 'match_type', $fields ) && null !== $fields['match_type'] && '' !== $fields['match_type'] ) {
		$match_type = sanitize_text_field( (string) $fields['match_type'] );
	}
	if ( ! in_array( $match_type, RR_REDIRECT_MATCH_TYPES, true ) ) {
		$errors[] = 'match_type must be one of: ' . implode( ', ', RR_REDIRECT_MATCH_TYPES );
	}
	$is_regex = ( 'regex' === $match_type );

	if ( $is_regex ) {
		// Not sanitize_text_field(): it collapses whitespace, which would
		// silently mangle otherwise-valid PCRE syntax.
		$source_raw = isset( $fields['source'] ) ? trim( (string) $fields['source'] ) : '';
		if ( '' === $source_raw ) {
			$errors[] = 'source is required';
			$source   = '';
		} else {
			$regex_errors = rr_redirect_regex_is_safe( $source_raw );
			if ( ! empty( $regex_errors ) ) {
				foreach ( $regex_errors as $regex_error ) {
					$errors[] = "source: {$regex_error}";
				}
				$source = '';
			} else {
				$source = $source_raw;
			}
		}
	} else {
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
	}

	$target_raw = isset( $fields['target'] ) ? sanitize_text_field( (string) $fields['target'] ) : '';
	if ( '' === $target_raw ) {
		$errors[] = 'target is required';
		$target   = '';
	} elseif ( 0 === strpos( $target_raw, '/' ) ) {
		$target = $target_raw;
	} elseif ( rr_redirect_is_allowed_absolute_target( $target_raw ) ) {
		$target = $target_raw;
	} else {
		$errors[] = "target must start with '/', or be an absolute http(s) URL whose host is on the"
			. " rrseo_redirect_allowed_hosts allowlist: got '{$target_raw}'";
		$target   = '';
	}

	if ( empty( $errors ) && ! $is_regex ) {
		if ( rr_redirect_detect_chain_loop( $source, $target, rr_redirect_list(), $existing_id ) ) {
			$errors[] = 'source/target forms a redirect loop, either directly or by chaining through other rules';
		}
	}

	if ( empty( $errors ) ) {
		foreach ( RR_REDIRECT_BLOCKED_SOURCES as $blocked ) {
			$blocks_this_path = $is_regex
				? rr_redirect_regex_matches_literal( $source, $blocked )
				: ( $source === $blocked || 0 === strpos( $source, rtrim( $blocked, '/' ) . '/' ) );
			if ( $blocks_this_path ) {
				$errors[] = "source '{$source}' targets a WordPress core path ('{$blocked}') and cannot be redirected";
				break;
			}
		}
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
 * Precedence: exact-type rules win outright; among prefix-type rules, the
 * longest matching source wins (standard longest-prefix-match tie-break);
 * regex-type rules are only considered when no exact or prefix rule
 * matched, first-registration-order wins among multiple regex matches.
 * Disabled rules are never matched.
 *
 * @param string $request_path Leading-slash path, no query string.
 * @param array  $redirects    Full redirect set (as from rr_redirect_list()).
 * @return array|null The matched rule, or null.
 */
function rr_redirect_match( $request_path, array $redirects ) {
	$prefix_best     = null;
	$prefix_best_len = -1;
	$regex_best      = null;

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
			if ( $len > $prefix_best_len ) {
				$prefix_best     = $rule;
				$prefix_best_len = $len;
			}
			continue;
		}

		if ( 'regex' === $match_type && null === $regex_best
			&& rr_redirect_regex_matches_literal( $source, $request_path ) ) {
			$regex_best = $rule;
		}
	}

	if ( null !== $prefix_best ) {
		return $prefix_best;
	}
	return $regex_best;
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
			'hit_count'  => 0,
			'last_hit'   => null,
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
 * per-item error shape ({index, error}). Honors $dry_run: validates and
 * returns the would-be created redirects without persisting (issue #25).
 *
 * @param array $items   Raw redirect field-sets.
 * @param bool  $dry_run True to validate and return the would-be result
 *                       without writing.
 * @return array{status: string, errors?: array, redirects?: array}
 */
function rr_redirect_bulk_create( array $items, $dry_run = false ) {
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
				'hit_count'  => 0,
				'last_hit'   => null,
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

	if ( $dry_run ) {
		return array(
			'status'    => 'simulated',
			'redirects' => array_values( $prepared ),
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


// ── Hit-count telemetry (issue #27) ─────────────────────────────────────────────

/**
 * Decides whether a hit should be recorded (write-throttled): skip when
 * the rule's last recorded hit was under RR_REDIRECT_HIT_THROTTLE_SECONDS
 * ago, to avoid an options-table write on every single front-end hit.
 * Pure/unit-testable -- the actual read/write lives in
 * rr_redirect_record_hit().
 *
 * @param string|null $last_hit        Stored last_hit timestamp ('mysql'
 *                                     format via current_time()), or null.
 * @param int         $now             Current Unix timestamp.
 * @param int         $throttle_seconds Minimum seconds between writes.
 * @return bool
 */
function rr_redirect_should_record_hit( $last_hit, $now, $throttle_seconds ) {
	if ( empty( $last_hit ) ) {
		return true;
	}
	$last_ts = strtotime( (string) $last_hit );
	if ( false === $last_ts ) {
		return true;
	}
	return ( $now - $last_ts ) >= $throttle_seconds;
}

/**
 * Increments hit_count and updates last_hit for a matched rule, subject to
 * the write-throttle in rr_redirect_should_record_hit(). Deliberately does
 * NOT call rrseo_purge_rest_cache() -- purging the page-level REST cache on
 * every single front-end visitor hit would defeat caching for a popular
 * redirect; only rrseo_bust_option_cache() runs, which is cheap and keeps
 * subsequent same-request/soon-after PHP-level reads correct. A brief
 * staleness window on GET /redirects' hit_count is an accepted trade-off.
 *
 * @param string $id Redirect id.
 */
function rr_redirect_record_hit( $id ) {
	$redirects = rr_redirect_list();
	if ( ! isset( $redirects[ $id ] ) ) {
		return;
	}

	$last_hit = isset( $redirects[ $id ]['last_hit'] ) ? $redirects[ $id ]['last_hit'] : null;
	if ( ! rr_redirect_should_record_hit( $last_hit, time(), RR_REDIRECT_HIT_THROTTLE_SECONDS ) ) {
		return;
	}

	$redirects[ $id ]['hit_count'] = ( isset( $redirects[ $id ]['hit_count'] ) ? (int) $redirects[ $id ]['hit_count'] : 0 ) + 1;
	$redirects[ $id ]['last_hit']  = current_time( 'mysql' );
	update_option( RR_REDIRECTS_KEY, $redirects );
	rrseo_bust_option_cache( RR_REDIRECTS_KEY );
}


// ── Front-end application ─────────────────────────────────────────────────────

add_action( 'template_redirect', 'rrseo_apply_redirects', 1 );

// wp_safe_redirect() checks WordPress core's own allowed_redirect_hosts
// filter and silently downgrades any host not on it to a same-site
// redirect -- without this bridge, a cross-domain target already validated
// against rrseo_redirect_allowed_hosts at write time would never actually
// fire, since these are two separate allowlists by default.
add_filter( 'allowed_redirect_hosts', 'rrseo_redirect_extend_allowed_hosts' );

/**
 * Merges this plugin's rrseo_redirect_allowed_hosts allowlist into
 * WordPress core's allowed_redirect_hosts, so wp_safe_redirect() accepts
 * hosts already validated at write time via
 * rr_redirect_is_allowed_absolute_target().
 *
 * @param array $hosts Core's existing allowed host list.
 * @return array
 */
function rrseo_redirect_extend_allowed_hosts( $hosts ) {
	$ours = (array) apply_filters( 'rrseo_redirect_allowed_hosts', array() );
	return array_values( array_unique( array_merge( (array) $hosts, $ours ) ) );
}

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

	if ( ! empty( $match['id'] ) ) {
		rr_redirect_record_hit( $match['id'] );
	}

	$target = ( 0 === strpos( (string) $match['target'], '/' ) ) ? home_url( $match['target'] ) : $match['target'];
	wp_safe_redirect( $target, $match['status_code'] );
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
 * Honors dry_run:true -- validates and returns the would-be created
 * redirects without persisting (issue #25).
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_redirects_bulk_create( WP_REST_Request $request ) {
	$incoming = $request->get_param( 'redirects' );
	if ( ! is_array( $incoming ) || empty( $incoming ) ) {
		return new WP_Error( 'missing_redirects', 'redirects must be a non-empty array.', array( 'status' => 400 ) );
	}
	$dry_run = (bool) $request->get_param( 'dry_run' );

	$result = rr_redirect_bulk_create( $incoming, $dry_run );

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
			'dry_run'   => $dry_run,
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
