<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #3 — schema + audit-log meta flattened to ''.
 *
 * v2.14.4 registered RR_SCHEMA_META_KEY and RR_CHANGE_LOG_KEY via
 * register_post_meta() with type "string" and sanitize_textarea_field as the
 * sanitize_callback. WP core applies a registered sanitize_callback on every
 * update_post_meta(), and the string sanitizers return '' for arrays — so
 * every schema write and every audit-log append was silently discarded.
 *
 * The bootstrap's update_post_meta stub mirrors core's sanitize_meta()
 * behaviour, so these tests exercise the exact layer that broke.
 */
class MetaPersistenceTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_post_meta']       = array();
		$GLOBALS['_test_registered_meta'] = array();
		$GLOBALS['_test_current_user_id'] = 0;

		// Replay the plugin's init-time hooks (meta registration lives there).
		// Closures needing a fuller WP environment bail or throw harmlessly.
		foreach ( $GLOBALS['_test_actions']['init'] ?? array() as $entry ) {
			try {
				call_user_func( $entry['callback'] );
			} catch ( \Throwable $e ) {
				continue;
			}
		}
	}

	// ── Registration surface ──────────────────────────────────────────────────

	public function test_internal_array_keys_are_not_registered(): void {
		// Sanity: the registration closure ran to completion — the first and
		// last keys it registers are both present.
		$this->assertArrayHasKey( '_rr_seo_canonical', $GLOBALS['_test_registered_meta'] );
		$this->assertArrayHasKey( META_LLMS_SECTION, $GLOBALS['_test_registered_meta'] );

		// The regression: array-valued internal keys must NOT carry a
		// registered string sanitize_callback (issue #3).
		$this->assertArrayNotHasKey( RR_SCHEMA_META_KEY, $GLOBALS['_test_registered_meta'] );
		$this->assertArrayNotHasKey( RR_CHANGE_LOG_KEY, $GLOBALS['_test_registered_meta'] );
	}

	public function test_string_sanitizer_flattens_arrays(): void {
		// Documents the failure mechanism and proves the harness models it:
		// a string-registered key destroys array values on write.
		register_post_meta(
			'',
			'_rrseo_test_flatten',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);
		update_post_meta( 12, '_rrseo_test_flatten', array( 'a' => 1 ) );

		$this->assertSame( '', get_post_meta( 12, '_rrseo_test_flatten', true ) );
	}

	// ── Schema write path ─────────────────────────────────────────────────────

	public function test_schema_graph_array_survives_update_post_meta(): void {
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'LocalBusiness',
			'name'        => 'Test Business',
			'description' => 'd',
			'url'         => 'https://example.test',
		);

		update_post_meta( 12, RR_SCHEMA_META_KEY, $schema );

		$this->assertSame( $schema, get_post_meta( 12, RR_SCHEMA_META_KEY, true ) );
	}

	// ── Audit log write path ──────────────────────────────────────────────────

	public function test_audit_log_entry_persists_as_array(): void {
		rr_audit_log(
			12,
			'/schema',
			array(
				'schema' => array(
					'before' => null,
					'after'  => array( '@type' => 'LocalBusiness' ),
				),
			),
			'req-1',
			'written'
		);

		$log = get_post_meta( 12, RR_CHANGE_LOG_KEY, true );

		$this->assertIsArray( $log );
		$this->assertCount( 1, $log );
		$this->assertSame( '/schema', $log[0]['endpoint'] );
		$this->assertSame( 'written', $log[0]['status'] );
		$this->assertSame( 'req-1', $log[0]['request_id'] );
		$this->assertSame( 'system', $log[0]['user_login'] );
	}

	public function test_audit_log_appends_second_entry(): void {
		rr_audit_log( 12, '/schema', array( 'schema' => array() ), 'req-1', 'written' );
		rr_audit_log( 12, '/update', array( 'title' => array() ), 'req-2', 'written' );

		$log = get_post_meta( 12, RR_CHANGE_LOG_KEY, true );

		$this->assertCount( 2, $log );
		$this->assertSame( '/update', $log[1]['endpoint'] );
	}
}
