<?php
/**
 * Module/Script Name: RankRocket SEO — Typed Action Engine (v3.0 Bite 2)
 * Path: includes/class-rrseo-actions.php
 *
 * Description:
 * Executor endpoints for the external Audit Engine: a typed, whitelisted
 * action surface with dry-run support. The plugin validates, applies, and
 * logs; the Audit Engine decides, approves, and queues (see
 * docs/plugin-v3-executor-spec.md). Every executed action stores a rollback
 * envelope in the rrseo_action_log option (post-targeted actions also write
 * a _rrseo_change_log audit row) and fires both cache busts per the v2.17.x
 * invariant.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-07-09
 * Last Modified Date: 2026-07-10
 *
 * Comments:
 * v1.00 - Initial release. POST /actions/dry-run + /actions/execute with the
 *         Bite 2 whitelist: update_setting, regenerate_llms_txt,
 *         update_meta_draft, toggle_indexing.
 * v1.10 - v3.0 Bite 3 rollback layer: GET /actions/{action_id} envelope
 *         lookup and POST /actions/{action_id}/rollback replaying stored
 *         envelopes, with drift detection (force:true override), double-
 *         rollback protection, and dry-run support.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Option key holding the capped action envelope log (no custom tables).
if ( ! defined( 'RR_ACTION_LOG_KEY' ) ) {
	define( 'RR_ACTION_LOG_KEY', 'rrseo_action_log' );
}

// Maximum stored action envelopes; oldest entries are dropped first.
if ( ! defined( 'RR_ACTION_LOG_MAX' ) ) {
	define( 'RR_ACTION_LOG_MAX', 200 );
}

// Draft meta key prefix for update_meta_draft — never the live rr_seo_* keys.
if ( ! defined( 'RR_ACTION_DRAFT_PREFIX' ) ) {
	define( 'RR_ACTION_DRAFT_PREFIX', '_rr_seo_draft_' );
}

// Whitelisted WP core options for update_setting, with strict value types
// (issue #5). Types: boolean ('1'/'0'), string, integer_post_id (published
// page), enum:<values>, integer_positive.
if ( ! defined( 'RR_ACTION_SETTING_WHITELIST' ) ) {
	define(
		'RR_ACTION_SETTING_WHITELIST',
		array(
			'blog_public'            => 'boolean',
			'blogname'               => 'string',
			'blogdescription'        => 'string',
			'page_on_front'          => 'integer_post_id',
			'page_for_posts'         => 'integer_post_id',
			'show_on_front'          => 'enum:posts,page',
			'default_ping_status'    => 'enum:open,closed',
			'default_comment_status' => 'enum:open,closed',
			'posts_per_page'         => 'integer_positive',
		)
	);
}

// The Bite 2 action whitelist. Anything else is rejected at validation.
if ( ! defined( 'RR_ACTION_TYPES' ) ) {
	define(
		'RR_ACTION_TYPES',
		array( 'update_setting', 'regenerate_llms_txt', 'update_meta_draft', 'toggle_indexing' )
	);
}


// ── Validation (pure, unit-testable) ──────────────────────────────────────────

/**
 * Validates a typed action request and normalizes its payload.
 *
 * @param string $action_type One of RR_ACTION_TYPES.
 * @param mixed  $target_id   Option name (update_setting), post ID
 *                            (update_meta_draft, toggle_indexing), or null.
 * @param array  $payload     Action-specific payload.
 * @return array{errors: string[], warnings: string[], normalized: array}
 */
function rr_action_validate( $action_type, $target_id, array $payload ) {
	if ( ! in_array( $action_type, RR_ACTION_TYPES, true ) ) {
		return array(
			'errors'     => array( "action_type '{$action_type}' is not whitelisted. Allowed: " . implode( ', ', RR_ACTION_TYPES ) ),
			'warnings'   => array(),
			'normalized' => array(),
		);
	}

	switch ( $action_type ) {
		case 'update_setting':
			return rr_action_validate_update_setting( $target_id, $payload );
		case 'regenerate_llms_txt':
			return array(
				'errors'     => array(),
				'warnings'   => array(),
				'normalized' => array(),
			);
		case 'update_meta_draft':
			return rr_action_validate_update_meta_draft( $target_id, $payload );
		case 'toggle_indexing':
		default:
			return rr_action_validate_toggle_indexing( $target_id, $payload );
	}
}

/**
 * Validates an update_setting action against the option whitelist.
 *
 * @param mixed $target_id Option name.
 * @param array $payload   Expects key new_value.
 * @return array{errors: string[], warnings: string[], normalized: array}
 */
function rr_action_validate_update_setting( $target_id, array $payload ) {
	$errors     = array();
	$normalized = array();
	$option     = is_string( $target_id ) ? $target_id : '';

	if ( ! array_key_exists( $option, RR_ACTION_SETTING_WHITELIST ) ) {
		$errors[] = "target_id '{$option}' is not a whitelisted option. Allowed: " . implode( ', ', array_keys( RR_ACTION_SETTING_WHITELIST ) );
		return array(
			'errors'     => $errors,
			'warnings'   => array(),
			'normalized' => array(),
		);
	}

	if ( ! array_key_exists( 'new_value', $payload ) ) {
		$errors[] = 'payload.new_value is required';
		return array(
			'errors'     => $errors,
			'warnings'   => array(),
			'normalized' => array(),
		);
	}

	$type  = RR_ACTION_SETTING_WHITELIST[ $option ];
	$value = $payload['new_value'];

	if ( 'boolean' === $type ) {
		if ( ! in_array( $value, array( true, false, 0, 1, '0', '1' ), true ) ) {
			$errors[] = "{$option}: expected boolean (0/1), got '" . ( is_scalar( $value ) ? $value : gettype( $value ) ) . "'";
		} else {
			$normalized['new_value'] = $value ? '1' : '0';
		}
	} elseif ( 'string' === $type ) {
		if ( ! is_scalar( $value ) ) {
			$errors[] = "{$option}: expected string, got " . gettype( $value );
		} else {
			$normalized['new_value'] = sanitize_text_field( (string) $value );
		}
	} elseif ( 'integer_post_id' === $type ) {
		$post_id = absint( $value );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			$errors[] = "{$option}: post {$post_id} not found";
		} elseif ( 'page' !== $post->post_type ) {
			$errors[] = "{$option}: post {$post_id} is a '{$post->post_type}', expected a page";
		} elseif ( 'publish' !== $post->post_status ) {
			$errors[] = "{$option}: page {$post_id} is not published (status '{$post->post_status}')";
		} else {
			$normalized['new_value'] = $post_id;
		}
	} elseif ( 'integer_positive' === $type ) {
		$int = absint( $value );
		if ( $int < 1 ) {
			$errors[] = "{$option}: expected a positive integer";
		} else {
			$normalized['new_value'] = $int;
		}
	} elseif ( 0 === strpos( $type, 'enum:' ) ) {
		$allowed = explode( ',', substr( $type, 5 ) );
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			$errors[] = "{$option}: expected one of " . implode( '|', $allowed );
		} else {
			$normalized['new_value'] = $value;
		}
	}

	return array(
		'errors'     => $errors,
		'warnings'   => array(),
		'normalized' => $normalized,
	);
}

/**
 * Validates an update_meta_draft action: draft SEO fields for a post.
 *
 * @param mixed $target_id Post ID.
 * @param array $payload   Expects key fields: map of field name => value.
 * @return array{errors: string[], warnings: string[], normalized: array}
 */
function rr_action_validate_update_meta_draft( $target_id, array $payload ) {
	$errors  = array();
	$post_id = absint( $target_id );

	if ( ! $post_id || ! get_post( $post_id ) ) {
		$errors[] = "target_id: post {$post_id} not found";
	}

	$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
	if ( empty( $fields ) ) {
		$errors[] = 'payload.fields is required and must be a non-empty object';
	}

	$unknown = array_diff( array_keys( $fields ), array_keys( RR_SEO_META_KEYS ) );
	if ( $unknown ) {
		$errors[] = 'unknown field(s): ' . implode( ', ', $unknown ) . '. Allowed: ' . implode( ', ', array_keys( RR_SEO_META_KEYS ) );
	}

	$warnings = array();
	if ( empty( $errors ) ) {
		$validation = rr_validate_seo_fields( $fields, $post_id );
		$errors     = array_merge( $errors, $validation['errors'] );
		$warnings   = $validation['warnings'];
	}

	return array(
		'errors'     => $errors,
		'warnings'   => $warnings,
		'normalized' => array(
			'post_id' => $post_id,
			'fields'  => $fields,
		),
	);
}

/**
 * Validates a toggle_indexing action: post-level index/noindex directive.
 *
 * @param mixed $target_id Post ID.
 * @param array $payload   Expects key new_value: 'index' or 'noindex'.
 * @return array{errors: string[], warnings: string[], normalized: array}
 */
function rr_action_validate_toggle_indexing( $target_id, array $payload ) {
	$errors  = array();
	$post_id = absint( $target_id );

	if ( ! $post_id || ! get_post( $post_id ) ) {
		$errors[] = "target_id: post {$post_id} not found";
	}

	$value = isset( $payload['new_value'] ) ? $payload['new_value'] : null;
	if ( ! in_array( $value, array( 'index', 'noindex' ), true ) ) {
		$errors[] = "payload.new_value: expected 'index' or 'noindex'";
	}

	return array(
		'errors'     => $errors,
		'warnings'   => array(),
		'normalized' => array(
			'post_id'   => $post_id,
			'new_value' => is_string( $value ) ? $value : '',
		),
	);
}


// ── Apply layer ───────────────────────────────────────────────────────────────

/**
 * Applies (or simulates) a validated action. Assumes rr_action_validate()
 * returned no errors for the same inputs.
 *
 * @param string $action_type Whitelisted action type.
 * @param mixed  $target_id   Raw target identifier.
 * @param array  $normalized  Normalized payload from rr_action_validate().
 * @param bool   $dry_run     True to simulate without writing.
 * @return array{before: mixed, after: mixed, rollback_payload: array|null,
 *               reversible: bool, reason: string, post_id: int|null,
 *               touched_options: string[], purge_endpoints: string[]}
 */
function rr_action_apply( $action_type, $target_id, array $normalized, $dry_run ) {
	switch ( $action_type ) {
		case 'update_setting':
			$option = (string) $target_id;
			$before = get_option( $option );
			$after  = $normalized['new_value'];
			if ( ! $dry_run ) {
				update_option( $option, $after );
			}
			return array(
				'before'           => $before,
				'after'            => $after,
				'rollback_payload' => array( 'old_value' => $before ),
				'reversible'       => true,
				'reason'           => '',
				'post_id'          => null,
				'touched_options'  => array( $option ),
				'purge_endpoints'  => array( 'status' ),
			);

		case 'regenerate_llms_txt':
			$config = get_option( RR_LLMS_CONFIG_KEY, get_option( 'rmb_llms_config', array() ) );
			if ( ! $dry_run ) {
				rr_invalidate_canonical_cache();
			}
			$result  = rr_render_llms_txt( is_array( $config ) ? $config : array() );
			$content = isset( $result['content'] ) ? (string) $result['content'] : '';
			$stats   = array(
				'line_count' => substr_count( $content, "\n" ),
				'byte_size'  => strlen( $content ),
			);
			return array(
				'before'           => null,
				'after'            => $stats,
				'rollback_payload' => null,
				'reversible'       => false,
				'reason'           => 'llms.txt is rendered from live configuration; there is no stored prior content to restore',
				'post_id'          => null,
				'touched_options'  => array( RR_LLMS_CONFIG_KEY ),
				'purge_endpoints'  => array( 'status', 'llms/preview' ),
			);

		case 'update_meta_draft':
			$post_id = $normalized['post_id'];
			$before  = array();
			$after   = array();
			foreach ( $normalized['fields'] as $field => $value ) {
				$draft_key        = RR_ACTION_DRAFT_PREFIX . $field;
				$before[ $field ] = get_post_meta( $post_id, $draft_key, true );
				$after[ $field ]  = sanitize_text_field( (string) $value );
				if ( ! $dry_run ) {
					update_post_meta( $post_id, $draft_key, $after[ $field ] );
				}
			}
			return array(
				'before'           => $before,
				'after'            => $after,
				'rollback_payload' => array(
					'draft_fields' => array_keys( $after ),
					'old_values'   => $before,
				),
				'reversible'       => true,
				'reason'           => '',
				'post_id'          => $post_id,
				'touched_options'  => array(),
				'purge_endpoints'  => array( 'status' ),
			);

		case 'toggle_indexing':
		default:
			$post_id    = $normalized['post_id'];
			$robots_key = RR_SEO_META_KEYS['robots'];
			$before     = get_post_meta( $post_id, $robots_key, true );
			$after      = $normalized['new_value'];
			if ( ! $dry_run ) {
				update_post_meta( $post_id, $robots_key, $after );
			}
			return array(
				'before'           => $before,
				'after'            => $after,
				'rollback_payload' => array( 'old_value' => $before ),
				'reversible'       => true,
				'reason'           => '',
				'post_id'          => $post_id,
				'touched_options'  => array(),
				'purge_endpoints'  => array( 'status' ),
			);
	}
}


// ── Pipeline ──────────────────────────────────────────────────────────────────

/**
 * Generates a unique action ID: rrseo-action-{utc-timestamp}-{hash}.
 *
 * @return string
 */
function rr_action_id() {
	return 'rrseo-action-' . gmdate( 'YmdHis' ) . '-' . substr( md5( uniqid( 'rrseo', true ) ), 0, 8 );
}

/**
 * Caps and persists the action log, busting its option cache.
 *
 * @param array $log Full log array (list of envelopes).
 * @return void
 */
function rr_action_log_write( array $log ) {
	if ( count( $log ) > RR_ACTION_LOG_MAX ) {
		$log = array_slice( $log, -RR_ACTION_LOG_MAX );
	}
	update_option( RR_ACTION_LOG_KEY, array_values( $log ), false );
	rrseo_bust_option_cache( RR_ACTION_LOG_KEY );
}

/**
 * Appends an action envelope to the capped rrseo_action_log option.
 *
 * @param array $envelope Full action envelope.
 * @return void
 */
function rr_action_log_store( array $envelope ) {
	$log   = get_option( RR_ACTION_LOG_KEY, array() );
	$log   = is_array( $log ) ? $log : array();
	$log[] = $envelope;
	rr_action_log_write( $log );
}

/**
 * Finds a stored envelope by action ID.
 *
 * @param string $action_id Action ID from an execute response.
 * @return array{envelope: array, index: int}|null Null when not present
 *                                                 (never stored, or pruned
 *                                                 by the log cap).
 */
function rr_action_log_find( $action_id ) {
	$log = get_option( RR_ACTION_LOG_KEY, array() );
	$log = is_array( $log ) ? $log : array();
	foreach ( $log as $index => $envelope ) {
		if ( is_array( $envelope ) && isset( $envelope['action_id'] ) && $envelope['action_id'] === $action_id ) {
			return array(
				'envelope' => $envelope,
				'index'    => $index,
			);
		}
	}
	return null;
}

/**
 * Runs the full action pipeline: validate, apply or simulate, log, cache-bust.
 *
 * Returned status values: 'invalid' (validation failed; errors populated),
 * 'simulated' (dry run; nothing written), 'completed' (applied + logged).
 *
 * @param string $action_type Requested action type.
 * @param mixed  $target_id   Target identifier (option name or post ID).
 * @param array  $payload     Action payload.
 * @param bool   $dry_run     True to simulate.
 * @param string $request_id  Correlation ID for the audit trail.
 * @return array Pipeline result; see status key.
 */
function rr_action_run( $action_type, $target_id, array $payload, $dry_run, $request_id ) {
	$validation = rr_action_validate( (string) $action_type, $target_id, $payload );
	if ( ! empty( $validation['errors'] ) ) {
		return array(
			'status'   => 'invalid',
			'errors'   => $validation['errors'],
			'warnings' => $validation['warnings'],
		);
	}

	$result = rr_action_apply( (string) $action_type, $target_id, $validation['normalized'], (bool) $dry_run );

	$envelope = array(
		'action_id'        => $dry_run ? null : rr_action_id(),
		'action_type'      => (string) $action_type,
		'target_id'        => is_scalar( $target_id ) ? $target_id : null,
		'status'           => $dry_run ? 'simulated' : 'completed',
		'applied_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'before'           => $result['before'],
		'after'            => $result['after'],
		'rollback_payload' => $result['rollback_payload'],
		'reversible'       => $result['reversible'],
		'reason'           => $result['reason'],
		'warnings'         => $validation['warnings'],
		'request_id'       => (string) $request_id,
	);

	if ( ! $dry_run ) {
		// Envelope store (Bite 3 GET /actions/{id} reads this).
		rr_action_log_store( $envelope );

		// Post-targeted actions also get a per-post audit row.
		if ( ! empty( $result['post_id'] ) ) {
			rr_audit_log(
				$result['post_id'],
				'/actions/execute',
				array(
					$envelope['action_type'] => array(
						'before' => $result['before'],
						'after'  => $result['after'],
					),
				),
				$envelope['request_id'],
				'written'
			);
		}

		// v2.17.x invariant: both cache layers bust on every executor write.
		foreach ( $result['touched_options'] as $option_key ) {
			rrseo_bust_option_cache( $option_key );
		}
		rrseo_purge_rest_cache( $result['purge_endpoints'] );
	}

	return $envelope;
}


// ── Rollback layer (v3.0 Bite 3) ──────────────────────────────────────────────

/**
 * Detects drift between an envelope's recorded 'after' state and the current
 * live value. A non-empty result means something else changed the target
 * since the action executed; rollback then requires force:true.
 *
 * @param array $envelope Stored action envelope.
 * @return string[] Human-readable drift descriptions; empty when clean.
 */
function rr_action_rollback_drift( array $envelope ) {
	$drift = array();

	switch ( $envelope['action_type'] ) {
		case 'update_setting':
			$option  = (string) $envelope['target_id'];
			$current = get_option( $option );
			if ( (string) $current !== (string) $envelope['after'] ) {
				$drift[] = "{$option}: current value '" . ( is_scalar( $current ) ? $current : gettype( $current ) )
					. "' no longer matches the action's recorded result '{$envelope['after']}'";
			}
			break;

		case 'update_meta_draft':
			$post_id = absint( $envelope['target_id'] );
			$after   = is_array( $envelope['after'] ) ? $envelope['after'] : array();
			foreach ( $after as $field => $expected ) {
				$current = get_post_meta( $post_id, RR_ACTION_DRAFT_PREFIX . $field, true );
				if ( (string) $current !== (string) $expected ) {
					$drift[] = "{$field}: current draft value no longer matches the action's recorded result";
				}
			}
			break;

		case 'toggle_indexing':
			$post_id = absint( $envelope['target_id'] );
			$current = get_post_meta( $post_id, RR_SEO_META_KEYS['robots'], true );
			if ( (string) $current !== (string) $envelope['after'] ) {
				$drift[] = "robots: current value '{$current}' no longer matches the action's recorded result '{$envelope['after']}'";
			}
			break;
	}

	return $drift;
}

/**
 * Restores the pre-action state recorded in an envelope's rollback payload.
 * Empty-string / false prior values mean the key did not exist, so the
 * restore deletes rather than writing an empty value.
 *
 * @param array $envelope Stored action envelope (reversible, with payload).
 * @param bool  $dry_run  True to compute the restore without writing.
 * @return array{before: mixed, after: mixed, post_id: int|null,
 *               touched_options: string[], purge_endpoints: string[]}
 */
function rr_action_rollback_apply( array $envelope, $dry_run ) {
	$payload = $envelope['rollback_payload'];

	switch ( $envelope['action_type'] ) {
		case 'update_setting':
			$option = (string) $envelope['target_id'];
			$old    = $payload['old_value'];
			$before = get_option( $option );
			if ( ! $dry_run ) {
				if ( false === $old ) {
					delete_option( $option );
				} else {
					update_option( $option, $old );
				}
			}
			return array(
				'before'          => $before,
				'after'           => $old,
				'post_id'         => null,
				'touched_options' => array( $option ),
				'purge_endpoints' => array( 'status' ),
			);

		case 'update_meta_draft':
			$post_id    = absint( $envelope['target_id'] );
			$fields     = isset( $payload['draft_fields'] ) ? (array) $payload['draft_fields'] : array();
			$old_values = isset( $payload['old_values'] ) ? (array) $payload['old_values'] : array();
			$before     = array();
			$after      = array();
			foreach ( $fields as $field ) {
				$draft_key        = RR_ACTION_DRAFT_PREFIX . $field;
				$old              = isset( $old_values[ $field ] ) ? $old_values[ $field ] : '';
				$before[ $field ] = get_post_meta( $post_id, $draft_key, true );
				$after[ $field ]  = $old;
				if ( ! $dry_run ) {
					if ( '' === $old ) {
						delete_post_meta( $post_id, $draft_key );
					} else {
						update_post_meta( $post_id, $draft_key, $old );
					}
				}
			}
			return array(
				'before'          => $before,
				'after'           => $after,
				'post_id'         => $post_id,
				'touched_options' => array(),
				'purge_endpoints' => array( 'status' ),
			);

		case 'toggle_indexing':
		default:
			$post_id    = absint( $envelope['target_id'] );
			$robots_key = RR_SEO_META_KEYS['robots'];
			$old        = $payload['old_value'];
			$before     = get_post_meta( $post_id, $robots_key, true );
			if ( ! $dry_run ) {
				if ( '' === $old ) {
					delete_post_meta( $post_id, $robots_key );
				} else {
					update_post_meta( $post_id, $robots_key, $old );
				}
			}
			return array(
				'before'          => $before,
				'after'           => $old,
				'post_id'         => $post_id,
				'touched_options' => array(),
				'purge_endpoints' => array( 'status' ),
			);
	}
}

/**
 * Runs the rollback pipeline for a stored action envelope.
 *
 * Returned status values: 'not_found', 'not_reversible',
 * 'already_rolled_back', 'state_drift' (refused; retry with force),
 * 'simulated' (dry run), 'completed' (restored + logged).
 *
 * @param string $action_id  Action ID from an execute response.
 * @param bool   $dry_run    True to simulate without writing.
 * @param bool   $force      True to roll back despite state drift.
 * @param string $request_id Correlation ID for the audit trail.
 * @return array Pipeline result; see status key.
 */
function rr_action_rollback_run( $action_id, $dry_run, $force, $request_id ) {
	$found = rr_action_log_find( (string) $action_id );
	if ( null === $found ) {
		return array(
			'status'    => 'not_found',
			'action_id' => (string) $action_id,
		);
	}

	$original = $found['envelope'];

	if ( empty( $original['reversible'] ) || empty( $original['rollback_payload'] ) ) {
		$reason = ! empty( $original['reason'] )
			? $original['reason']
			: 'the stored envelope carries no rollback payload';
		return array(
			'status'    => 'not_reversible',
			'action_id' => $original['action_id'],
			'reason'    => $reason,
		);
	}

	if ( ! empty( $original['rolled_back_at'] ) ) {
		return array(
			'status'             => 'already_rolled_back',
			'action_id'          => $original['action_id'],
			'rolled_back_at'     => $original['rolled_back_at'],
			'rollback_action_id' => isset( $original['rollback_action_id'] ) ? $original['rollback_action_id'] : null,
		);
	}

	$drift = rr_action_rollback_drift( $original );
	if ( $drift && ! $force ) {
		return array(
			'status'    => 'state_drift',
			'action_id' => $original['action_id'],
			'drift'     => $drift,
		);
	}

	$result = rr_action_rollback_apply( $original, (bool) $dry_run );

	$envelope = array(
		'action_id'        => $dry_run ? null : rr_action_id(),
		'action_type'      => 'rollback',
		'target_id'        => $original['action_id'],
		'status'           => $dry_run ? 'simulated' : 'completed',
		'applied_at'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'before'           => $result['before'],
		'after'            => $result['after'],
		'rollback_payload' => null,
		'reversible'       => false,
		'reason'           => 'a rollback is not itself reversible; re-execute the original action to redo it',
		'warnings'         => $drift,
		'request_id'       => (string) $request_id,
	);

	if ( ! $dry_run ) {
		// Mark the original and append the rollback record in one log write.
		$log = get_option( RR_ACTION_LOG_KEY, array() );
		$log = is_array( $log ) ? $log : array();
		if ( isset( $log[ $found['index'] ] ) ) {
			$log[ $found['index'] ]['rolled_back_at']     = $envelope['applied_at'];
			$log[ $found['index'] ]['rollback_action_id'] = $envelope['action_id'];
		}
		$log[] = $envelope;
		rr_action_log_write( $log );

		if ( ! empty( $result['post_id'] ) ) {
			rr_audit_log(
				$result['post_id'],
				'/actions/rollback',
				array(
					$original['action_type'] => array(
						'before' => $result['before'],
						'after'  => $result['after'],
					),
				),
				$envelope['request_id'],
				'written'
			);
		}

		// v2.17.x invariant: both cache layers bust on every executor write.
		foreach ( $result['touched_options'] as $option_key ) {
			rrseo_bust_option_cache( $option_key );
		}
		rrseo_purge_rest_cache( $result['purge_endpoints'] );
	}

	return $envelope;
}


// ── REST handlers ─────────────────────────────────────────────────────────────

/**
 * Shared request unpacking for the two /actions endpoints.
 *
 * @param WP_REST_Request $request   REST request object.
 * @param bool            $force_dry True to ignore the request's dry_run flag.
 * @return WP_REST_Response|WP_Error
 */
function rmb_actions_dispatch( WP_REST_Request $request, $force_dry ) {
	$payload = $request->get_param( 'payload' );
	$dry_run = $force_dry ? true : (bool) $request->get_param( 'dry_run' );

	$envelope = rr_action_run(
		(string) $request->get_param( 'action_type' ),
		$request->get_param( 'target_id' ),
		is_array( $payload ) ? $payload : array(),
		$dry_run,
		rr_request_id( $request )
	);

	if ( 'invalid' === $envelope['status'] ) {
		return new WP_Error(
			'action_validation_failed',
			'Action validation failed',
			array(
				'status'   => 422,
				'errors'   => $envelope['errors'],
				'warnings' => $envelope['warnings'],
			)
		);
	}

	$envelope['audit_ref'] = array(
		'action_log' => RR_ACTION_LOG_KEY,
		'post_id'    => null,
	);
	if ( in_array( $envelope['action_type'], array( 'update_meta_draft', 'toggle_indexing' ), true ) ) {
		$envelope['audit_ref']['post_id'] = absint( $envelope['target_id'] );
	}

	return rest_ensure_response( $envelope );
}

/**
 * Handles POST /actions/dry-run — validate + simulate, never writes.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_actions_dry_run( WP_REST_Request $request ) {
	return rmb_actions_dispatch( $request, true );
}

/**
 * Handles POST /actions/execute — apply a typed action; honors dry_run:true.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_actions_execute( WP_REST_Request $request ) {
	return rmb_actions_dispatch( $request, false );
}

/**
 * Handles GET /actions/{action_id} — envelope lookup in rrseo_action_log.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_actions_get( WP_REST_Request $request ) {
	$action_id = (string) $request->get_param( 'action_id' );
	$found     = rr_action_log_find( $action_id );

	if ( null === $found ) {
		return new WP_Error(
			'action_not_found',
			"No stored envelope for action '{$action_id}'. The log keeps the most recent " . RR_ACTION_LOG_MAX . ' executed actions; older entries are pruned.',
			array( 'status' => 404 )
		);
	}

	return rest_ensure_response( $found['envelope'] );
}

/**
 * Handles POST /actions/{action_id}/rollback — replay the stored envelope.
 * Honors dry_run:true (simulate) and force:true (override drift refusal).
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error
 */
function rmb_actions_rollback( WP_REST_Request $request ) {
	$result = rr_action_rollback_run(
		(string) $request->get_param( 'action_id' ),
		(bool) $request->get_param( 'dry_run' ),
		(bool) $request->get_param( 'force' ),
		rr_request_id( $request )
	);

	switch ( $result['status'] ) {
		case 'not_found':
			return new WP_Error(
				'action_not_found',
				"No stored envelope for action '{$result['action_id']}'. The log keeps the most recent "
					. RR_ACTION_LOG_MAX . ' executed actions; older entries are pruned.',
				array( 'status' => 404 )
			);

		case 'not_reversible':
			return new WP_Error(
				'action_not_reversible',
				"Action '{$result['action_id']}' cannot be rolled back: {$result['reason']}.",
				array( 'status' => 422 )
			);

		case 'already_rolled_back':
			return new WP_Error(
				'action_already_rolled_back',
				"Action '{$result['action_id']}' was already rolled back at {$result['rolled_back_at']}.",
				array(
					'status'             => 409,
					'rollback_action_id' => $result['rollback_action_id'],
				)
			);

		case 'state_drift':
			return new WP_Error(
				'action_state_drift',
				"The target of action '{$result['action_id']}' has changed since the action executed. Send {\"force\": true} to roll back anyway.",
				array(
					'status' => 409,
					'drift'  => $result['drift'],
				)
			);
	}

	return rest_ensure_response( $result );
}
