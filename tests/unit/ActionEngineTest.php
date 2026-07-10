<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the v3.0 Bite 2 typed action engine
 * (includes/class-rrseo-actions.php).
 *
 * Covers rr_action_validate() per action type, and the full
 * rr_action_run() pipeline: dry-run simulation (no writes), execute
 * (write + envelope in rrseo_action_log + per-post audit row + both
 * cache busts). REST handlers are thin wrappers over rr_action_run()
 * and are exercised by the staging validation pass.
 */
class ActionEngineTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_options']         = array();
		$GLOBALS['_test_post_meta']       = array();
		$GLOBALS['_test_posts']           = array();
		$GLOBALS['_test_registered_meta'] = array();
		$GLOBALS['_test_cache_deletes']   = array();
		$GLOBALS['_test_fired_actions']   = array();
		$GLOBALS['_test_current_user_id'] = 0;
	}

	private function make_post( int $id, string $type = 'page', string $status = 'publish' ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_status = $status;

		$GLOBALS['_test_posts'][ $id ] = $post;
		return $post;
	}

	// ── rr_action_validate() ──────────────────────────────────────────────────

	public function test_unknown_action_type_is_rejected(): void {
		$v = rr_action_validate( 'delete_all_posts', null, array() );

		$this->assertNotEmpty( $v['errors'] );
		$this->assertStringContainsString( 'not whitelisted', $v['errors'][0] );
	}

	public function test_update_setting_rejects_non_whitelisted_option(): void {
		$v = rr_action_validate( 'update_setting', 'siteurl', array( 'new_value' => 'https://evil.test' ) );

		$this->assertNotEmpty( $v['errors'] );
		$this->assertStringContainsString( 'not a whitelisted option', $v['errors'][0] );
	}

	public function test_update_setting_requires_new_value(): void {
		$v = rr_action_validate( 'update_setting', 'blog_public', array() );

		$this->assertSame( array( 'payload.new_value is required' ), $v['errors'] );
	}

	public function test_update_setting_boolean_normalizes_to_string_flag(): void {
		$v = rr_action_validate( 'update_setting', 'blog_public', array( 'new_value' => true ) );

		$this->assertSame( array(), $v['errors'] );
		$this->assertSame( '1', $v['normalized']['new_value'] );

		$v = rr_action_validate( 'update_setting', 'blog_public', array( 'new_value' => '0' ) );
		$this->assertSame( '0', $v['normalized']['new_value'] );
	}

	public function test_update_setting_boolean_rejects_non_boolean(): void {
		$v = rr_action_validate( 'update_setting', 'blog_public', array( 'new_value' => 'yes' ) );

		$this->assertNotEmpty( $v['errors'] );
	}

	public function test_update_setting_enum_rejects_unknown_member(): void {
		$v = rr_action_validate( 'update_setting', 'show_on_front', array( 'new_value' => 'archive' ) );

		$this->assertNotEmpty( $v['errors'] );
		$this->assertStringContainsString( 'posts|page', $v['errors'][0] );
	}

	public function test_update_setting_positive_integer_rejects_zero(): void {
		$v = rr_action_validate( 'update_setting', 'posts_per_page', array( 'new_value' => 0 ) );

		$this->assertNotEmpty( $v['errors'] );
	}

	public function test_update_setting_page_on_front_requires_published_page(): void {
		$v = rr_action_validate( 'update_setting', 'page_on_front', array( 'new_value' => 99 ) );
		$this->assertStringContainsString( 'not found', $v['errors'][0] );

		$this->make_post( 7, 'post' );
		$v = rr_action_validate( 'update_setting', 'page_on_front', array( 'new_value' => 7 ) );
		$this->assertStringContainsString( "expected a page", $v['errors'][0] );

		$this->make_post( 8, 'page', 'draft' );
		$v = rr_action_validate( 'update_setting', 'page_on_front', array( 'new_value' => 8 ) );
		$this->assertStringContainsString( 'not published', $v['errors'][0] );

		$this->make_post( 9, 'page' );
		$v = rr_action_validate( 'update_setting', 'page_on_front', array( 'new_value' => '9' ) );
		$this->assertSame( array(), $v['errors'] );
		$this->assertSame( 9, $v['normalized']['new_value'] );
	}

	public function test_toggle_indexing_validates_post_and_value(): void {
		$v = rr_action_validate( 'toggle_indexing', 42, array( 'new_value' => 'noindex' ) );
		$this->assertStringContainsString( 'not found', $v['errors'][0] );

		$this->make_post( 42 );
		$v = rr_action_validate( 'toggle_indexing', 42, array( 'new_value' => 'maybe' ) );
		$this->assertStringContainsString( "expected 'index' or 'noindex'", $v['errors'][0] );

		$v = rr_action_validate( 'toggle_indexing', 42, array( 'new_value' => 'noindex' ) );
		$this->assertSame( array(), $v['errors'] );
	}

	public function test_update_meta_draft_rejects_unknown_and_overlong_fields(): void {
		$this->make_post( 5 );

		$v = rr_action_validate( 'update_meta_draft', 5, array( 'fields' => array( 'seo_score' => '99' ) ) );
		$this->assertStringContainsString( 'unknown field(s): seo_score', $v['errors'][0] );

		$v = rr_action_validate(
			'update_meta_draft',
			5,
			array( 'fields' => array( 'title' => str_repeat( 'x', RR_TITLE_MAX + 10 ) ) )
		);
		$this->assertNotEmpty( $v['errors'] );
		$this->assertStringContainsString( 'exceeds hard limit', $v['errors'][0] );
	}

	// ── rr_action_run(): dry-run ──────────────────────────────────────────────

	public function test_dry_run_simulates_without_writing(): void {
		$GLOBALS['_test_options']['blog_public'] = '0';

		$envelope = rr_action_run( 'update_setting', 'blog_public', array( 'new_value' => 1 ), true, 'req-dry' );

		$this->assertSame( 'simulated', $envelope['status'] );
		$this->assertNull( $envelope['action_id'] );
		$this->assertSame( '0', $envelope['before'] );
		$this->assertSame( '1', $envelope['after'] );
		$this->assertSame( array( 'old_value' => '0' ), $envelope['rollback_payload'] );

		// Nothing written: option unchanged, no envelope log, no cache busts.
		$this->assertSame( '0', $GLOBALS['_test_options']['blog_public'] );
		$this->assertArrayNotHasKey( RR_ACTION_LOG_KEY, $GLOBALS['_test_options'] );
		$this->assertSame( array(), $GLOBALS['_test_cache_deletes'] );
		$this->assertSame( array(), $GLOBALS['_test_fired_actions'] );
	}

	public function test_invalid_action_returns_errors_and_writes_nothing(): void {
		$envelope = rr_action_run( 'update_setting', 'siteurl', array( 'new_value' => 'x' ), false, 'req-bad' );

		$this->assertSame( 'invalid', $envelope['status'] );
		$this->assertNotEmpty( $envelope['errors'] );
		$this->assertSame( array(), $GLOBALS['_test_options'] );
	}

	// ── rr_action_run(): execute ──────────────────────────────────────────────

	public function test_execute_update_setting_writes_and_logs_envelope(): void {
		$GLOBALS['_test_options']['blog_public'] = '0';

		$envelope = rr_action_run( 'update_setting', 'blog_public', array( 'new_value' => 1 ), false, 'req-1' );

		$this->assertSame( 'completed', $envelope['status'] );
		$this->assertMatchesRegularExpression( '/^rrseo-action-\d{14}-[0-9a-f]{8}$/', $envelope['action_id'] );
		$this->assertTrue( $envelope['reversible'] );
		$this->assertSame( '1', $GLOBALS['_test_options']['blog_public'] );

		// Envelope persisted to the capped option log.
		$log = $GLOBALS['_test_options'][ RR_ACTION_LOG_KEY ];
		$this->assertCount( 1, $log );
		$this->assertSame( $envelope['action_id'], $log[0]['action_id'] );
		$this->assertSame( 'req-1', $log[0]['request_id'] );

		// v2.17.x invariant: option cache busted for the option AND the log,
		// page cache purge fired for the REST endpoints.
		$busted_keys = array_column( $GLOBALS['_test_cache_deletes'], 'key' );
		$this->assertContains( 'blog_public', $busted_keys );
		$this->assertContains( RR_ACTION_LOG_KEY, $busted_keys );

		$purges = array_column( $GLOBALS['_test_fired_actions'], 'hook' );
		$this->assertContains( 'litespeed_purge_url', $purges );
	}

	public function test_execute_toggle_indexing_writes_meta_and_audit_row(): void {
		$this->make_post( 42 );
		$robots_key = RR_SEO_META_KEYS['robots'];

		$GLOBALS['_test_post_meta'][42][ $robots_key ] = 'index';

		$envelope = rr_action_run( 'toggle_indexing', 42, array( 'new_value' => 'noindex' ), false, 'req-2' );

		$this->assertSame( 'completed', $envelope['status'] );
		$this->assertSame( 'index', $envelope['before'] );
		$this->assertSame( 'noindex', $GLOBALS['_test_post_meta'][42][ $robots_key ] );

		// Post-targeted action also writes the per-post audit row.
		$audit = $GLOBALS['_test_post_meta'][42][ RR_CHANGE_LOG_KEY ];
		$this->assertIsArray( $audit );
		$this->assertCount( 1, $audit );
		$this->assertSame( '/actions/execute', $audit[0]['endpoint'] );
		$this->assertSame( 'req-2', $audit[0]['request_id'] );
	}

	public function test_execute_update_meta_draft_touches_only_draft_keys(): void {
		$this->make_post( 5 );
		$live_title_key = RR_SEO_META_KEYS['title'];

		$GLOBALS['_test_post_meta'][5][ $live_title_key ] = 'Live Title';

		$envelope = rr_action_run(
			'update_meta_draft',
			5,
			array( 'fields' => array( 'title' => 'Draft Title That Is Long Enough To Pass' ) ),
			false,
			'req-3'
		);

		$this->assertSame( 'completed', $envelope['status'] );
		$this->assertSame(
			'Draft Title That Is Long Enough To Pass',
			$GLOBALS['_test_post_meta'][5][ RR_ACTION_DRAFT_PREFIX . 'title' ]
		);
		// Live key untouched.
		$this->assertSame( 'Live Title', $GLOBALS['_test_post_meta'][5][ $live_title_key ] );
		// Rollback envelope knows which draft fields to delete.
		$this->assertSame( array( 'title' ), $envelope['rollback_payload']['draft_fields'] );
	}

	public function test_action_log_is_capped(): void {
		$GLOBALS['_test_options'][ RR_ACTION_LOG_KEY ] = array_fill( 0, RR_ACTION_LOG_MAX, array( 'action_id' => 'old' ) );
		$GLOBALS['_test_options']['blog_public']       = '0';

		rr_action_run( 'update_setting', 'blog_public', array( 'new_value' => 1 ), false, 'req-cap' );

		$log = $GLOBALS['_test_options'][ RR_ACTION_LOG_KEY ];
		$this->assertCount( RR_ACTION_LOG_MAX, $log );
		$this->assertSame( 'req-cap', $log[ RR_ACTION_LOG_MAX - 1 ]['request_id'] );
	}
}
