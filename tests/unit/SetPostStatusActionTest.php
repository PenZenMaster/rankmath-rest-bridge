<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the set_post_status typed action (issue #37): moving an existing
 * page between publish and draft through the action engine, with validation,
 * dry run, execute, audit, drift-checked rollback and the homepage guard.
 */
class SetPostStatusActionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_options']           = array();
		$GLOBALS['_test_post_meta']         = array();
		$GLOBALS['_test_posts']             = array();
		$GLOBALS['_test_pages_list']        = array();
		$GLOBALS['_test_posts_list']        = array();
		$GLOBALS['_test_permalink']         = array();
		$GLOBALS['_test_registered_meta']   = array();
		$GLOBALS['_test_cache_deletes']     = array();
		$GLOBALS['_test_fired_actions']     = array();
		$GLOBALS['_test_transients']        = array();
		$GLOBALS['_test_caps']              = array();
		$GLOBALS['_test_update_post_error'] = false;
		$GLOBALS['_test_current_user_id']   = 0;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_caps'], $GLOBALS['_test_update_post_error'] );
	}

	private function page( int $id, string $status = 'publish', string $type = 'page' ): WP_Post {
		$post               = new WP_Post();
		$post->ID           = $id;
		$post->post_type    = $type;
		$post->post_status  = $status;
		$post->post_name    = 'page-' . $id;
		$post->post_title   = 'Page ' . $id;
		$post->post_content = 'Original content ' . $id;
		$post->post_parent  = 7;
		$post->post_password = '';
		$post->post_excerpt  = '';
		$post->post_modified_gmt = '2026-04-29 10:00:00';

		$GLOBALS['_test_posts'][ $id ]     = $post;
		$GLOBALS['_test_pages_list'][]     = $post;
		$GLOBALS['_test_permalink'][ $id ] = 'https://example.test/page-' . $id . '/';
		return $post;
	}

	private function payload( string $expected = 'publish', string $new = 'draft', string $reason = 'cleanup' ): array {
		return array(
			'expected_status' => $expected,
			'new_value'       => $new,
			'reason'          => $reason,
		);
	}

	// ── Whitelist ─────────────────────────────────────────────────────────────

	public function test_set_post_status_is_whitelisted_and_create_page_remains(): void {
		$this->assertContains( 'set_post_status', RR_ACTION_TYPES );
		$this->assertContains( 'create_page', RR_ACTION_TYPES );
	}

	// ── Validation ────────────────────────────────────────────────────────────

	public function test_valid_publish_to_draft_normalizes_and_warns_about_regeneration(): void {
		$this->page( 5 );

		$v = rr_action_validate( 'set_post_status', 5, $this->payload() );

		$this->assertSame( array(), $v['errors'] );
		$this->assertSame( 5, $v['normalized']['post_id'] );
		$this->assertSame( 'publish', $v['normalized']['expected_status'] );
		$this->assertSame( 'draft', $v['normalized']['new_value'] );
		$this->assertSame( 'cleanup', $v['normalized']['reason'] );
		$this->assertStringContainsString( 'POST /cache/purge', $v['warnings'][0] );
	}

	public function test_valid_draft_to_publish_is_allowed(): void {
		$this->page( 5, 'draft' );

		$v = rr_action_validate( 'set_post_status', 5, $this->payload( 'draft', 'publish' ) );

		$this->assertSame( array(), $v['errors'] );
	}

	public function test_missing_post_and_non_page_targets_are_rejected(): void {
		$this->page( 6, 'publish', 'post' );

		$missing = rr_action_validate( 'set_post_status', 999, $this->payload() );
		$post    = rr_action_validate( 'set_post_status', 6, $this->payload() );

		$this->assertStringContainsString( 'not found', $missing['errors'][0] );
		$this->assertStringContainsString( 'only pages are supported', $post['errors'][0] );
	}

	public function test_only_publish_and_draft_are_accepted(): void {
		$this->page( 5 );

		foreach ( array( 'trash', 'private', 'pending', 'future', '' ) as $bad ) {
			$v = rr_action_validate( 'set_post_status', 5, $this->payload( 'publish', $bad ) );
			$this->assertNotEmpty( $v['errors'], 'new_value ' . $bad );
		}
		$v = rr_action_validate( 'set_post_status', 5, $this->payload( 'trash', 'draft' ) );
		$this->assertNotEmpty( $v['errors'] );
	}

	public function test_expected_status_is_required(): void {
		$this->page( 5 );

		$v = rr_action_validate( 'set_post_status', 5, array( 'new_value' => 'draft' ) );

		$this->assertContains( "payload.expected_status: expected 'publish' or 'draft'", $v['errors'] );
	}

	public function test_noop_transition_is_rejected(): void {
		$this->page( 5 );

		$v = rr_action_validate( 'set_post_status', 5, $this->payload( 'publish', 'publish' ) );

		$this->assertContains( 'payload.new_value must differ from payload.expected_status', $v['errors'] );
	}

	public function test_status_that_changed_since_preview_is_a_conflict(): void {
		$this->page( 5, 'draft' );

		$v = rr_action_validate( 'set_post_status', 5, $this->payload( 'publish', 'draft' ) );

		$this->assertStringContainsString( 'conflict', $v['errors'][0] );
		$this->assertStringContainsString( "is 'draft'", $v['errors'][0] );
	}

	public function test_homepage_and_posts_page_are_protected(): void {
		$this->page( 5 );
		$this->page( 6 );
		$GLOBALS['_test_options']['page_on_front']  = 5;
		$GLOBALS['_test_options']['page_for_posts'] = 6;

		foreach ( array( 5, 6 ) as $id ) {
			$v = rr_action_validate( 'set_post_status', $id, $this->payload() );
			$this->assertStringContainsString( 'homepage or posts page', $v['errors'][0], 'page ' . $id );
		}
	}

	public function test_per_target_capabilities_are_enforced(): void {
		$this->page( 5 );
		$this->page( 6, 'draft' );

		$GLOBALS['_test_caps']['edit_post'] = false;
		$edit                               = rr_action_validate( 'set_post_status', 5, $this->payload() );
		$this->assertStringContainsString( 'cannot edit', $edit['errors'][0] );

		$GLOBALS['_test_caps'] = array( 'publish_post' => false );
		$publish               = rr_action_validate( 'set_post_status', 6, $this->payload( 'draft', 'publish' ) );
		$this->assertStringContainsString( 'cannot publish', $publish['errors'][0] );

		// Unpublishing needs edit capability only.
		$unpublish = rr_action_validate( 'set_post_status', 5, $this->payload() );
		$this->assertSame( array(), $unpublish['errors'] );
	}

	public function test_overlong_reason_is_rejected(): void {
		$this->page( 5 );

		$v = rr_action_validate( 'set_post_status', 5, $this->payload( 'publish', 'draft', str_repeat( 'x', 256 ) ) );

		$this->assertContains( 'payload.reason must be 255 characters or fewer', $v['errors'] );
	}

	// ── Dry run ───────────────────────────────────────────────────────────────

	public function test_dry_run_predicts_the_transition_without_any_write(): void {
		$page                                   = $this->page( 5 );
		$GLOBALS['_test_transients']['rrseo_canonical_counts'] = array( 'cached' => true );

		$envelope = rr_action_run( 'set_post_status', 5, $this->payload(), true, 'req-dry' );

		$this->assertSame( 'simulated', $envelope['status'] );
		$this->assertNull( $envelope['action_id'] );
		$this->assertSame( 'publish', $envelope['before'] );
		$this->assertSame( 'draft', $envelope['after'] );
		$this->assertSame( 'publish', $page->post_status );
		$this->assertSame( array(), $GLOBALS['_test_fired_actions'] );
		$this->assertArrayHasKey( 'rrseo_canonical_counts', $GLOBALS['_test_transients'] );
		$this->assertSame( array(), get_option( RR_ACTION_LOG_KEY, array() ) );
	}

	// ── Execute ───────────────────────────────────────────────────────────────

	public function test_execute_changes_only_status_and_returns_a_rollback_envelope(): void {
		$page = $this->page( 5 );
		$GLOBALS['_test_post_meta'][5]['_elementor_data'] = '[{"id":"a"}]';
		$GLOBALS['_test_transients']['rrseo_canonical_counts'] = array( 'cached' => true );

		$envelope = rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-1' );

		$this->assertSame( 'completed', $envelope['status'] );
		$this->assertStringStartsWith( 'rrseo-action-', $envelope['action_id'] );
		$this->assertSame( 'publish', $envelope['before'] );
		$this->assertSame( 'draft', $envelope['after'] );
		$this->assertTrue( $envelope['reversible'] );
		$this->assertSame( array( 'old_status' => 'publish' ), $envelope['rollback_payload'] );

		// Only status changed.
		$this->assertSame( 'draft', $page->post_status );
		$this->assertSame( 'page-5', $page->post_name );
		$this->assertSame( 'Original content 5', $page->post_content );
		$this->assertSame( 7, $page->post_parent );
		$this->assertSame( '[{"id":"a"}]', $GLOBALS['_test_post_meta'][5]['_elementor_data'] );
	}

	public function test_execute_logs_envelope_audit_row_and_invalidates_caches(): void {
		$this->page( 5 );
		$GLOBALS['_test_transients']['rrseo_canonical_counts'] = array( 'cached' => true );

		$envelope = rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-1' );

		$log = $GLOBALS['_test_options'][ RR_ACTION_LOG_KEY ];
		$this->assertSame( $envelope['action_id'], $log[0]['action_id'] );

		$audit = $GLOBALS['_test_post_meta'][5][ RR_CHANGE_LOG_KEY ];
		$this->assertSame( '/actions/execute', $audit[0]['endpoint'] );
		$this->assertSame( 'publish', $audit[0]['changes']['set_post_status']['before'] );
		$this->assertSame( 'draft', $audit[0]['changes']['set_post_status']['after'] );

		$this->assertArrayNotHasKey( 'rrseo_canonical_counts', $GLOBALS['_test_transients'] );
		$urls = array();
		foreach ( $GLOBALS['_test_fired_actions'] as $fired ) {
			if ( 'litespeed_purge_url' === $fired['hook'] ) {
				$urls[] = $fired['args'][0];
			}
		}
		$this->assertContains( 'https://example.test/wp-json/rankrocket-seo/v1/canonical-urls/preview', $urls );
		$this->assertContains( 'https://example.test/wp-json/rankrocket-seo/v1/llms/preview', $urls );
	}

	public function test_drafting_a_page_removes_only_its_url_from_the_canonical_set(): void {
		$this->page( 5 );
		$this->page( 6 );

		$before = array_column( rr_get_canonical_url_set()['urls'], 'url' );
		rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-1' );
		$after = array_column( rr_get_canonical_url_set()['urls'], 'url' );

		$this->assertContains( 'https://example.test/page-5/', $before );
		$this->assertContains( 'https://example.test/page-6/', $before );
		$this->assertNotContains( 'https://example.test/page-5/', $after );
		$this->assertContains( 'https://example.test/page-6/', $after );
	}

	public function test_status_changed_between_preview_and_execute_is_a_conflict_not_an_overwrite(): void {
		$page = $this->page( 5 );
		rr_action_run( 'set_post_status', 5, $this->payload(), true, 'req-preview' );
		$page->post_status = 'draft'; // Someone else drafted it after the preview.

		$result = rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-exec' );

		$this->assertSame( 'invalid', $result['status'] );
		$this->assertStringContainsString( 'conflict', $result['errors'][0] );
		$this->assertSame( array(), get_option( RR_ACTION_LOG_KEY, array() ) );
	}

	public function test_a_change_that_does_not_take_effect_is_reported_and_not_reversible(): void {
		$page                                   = $this->page( 5 );
		$GLOBALS['_test_update_post_error']     = true;

		$envelope = rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-1' );

		$this->assertSame( 'publish', $page->post_status );
		$this->assertSame( 'publish', $envelope['after'] );
		$this->assertFalse( $envelope['reversible'] );
		$this->assertNull( $envelope['rollback_payload'] );
		$this->assertNotEmpty( array_filter( $envelope['warnings'], fn( $w ) => false !== strpos( $w, 'did not take effect' ) ) );
	}

	// ── Rollback ──────────────────────────────────────────────────────────────

	public function test_rollback_restores_status_and_preserves_everything_else(): void {
		$page = $this->page( 5 );
		$GLOBALS['_test_post_meta'][5]['_elementor_data'] = '[{"id":"a"}]';
		$envelope = rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-1' );
		$this->assertSame( 'draft', $page->post_status );

		$result = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 'draft', $result['before'] );
		$this->assertSame( 'publish', $result['after'] );
		$this->assertSame( 'publish', $page->post_status );
		$this->assertSame( 'page-5', $page->post_name );
		$this->assertSame( 'Original content 5', $page->post_content );
		$this->assertSame( 7, $page->post_parent );
		$this->assertSame( '[{"id":"a"}]', $GLOBALS['_test_post_meta'][5]['_elementor_data'] );
	}

	public function test_rollback_dry_run_does_not_write(): void {
		$page     = $this->page( 5 );
		$envelope = rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-1' );

		$result = rr_action_rollback_run( $envelope['action_id'], true, false, 'req-rb' );

		$this->assertSame( 'simulated', $result['status'] );
		$this->assertSame( 'draft', $page->post_status );
	}

	public function test_rollback_refuses_status_drift_unless_forced(): void {
		$page     = $this->page( 5 );
		$envelope = rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-1' );
		$page->post_status = 'publish'; // Republished by someone else.

		$refused = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

		$this->assertSame( 'state_drift', $refused['status'] );
		$this->assertStringContainsString( "status is now 'publish'", $refused['drift'][0] );

		$forced = rr_action_rollback_run( $envelope['action_id'], false, true, 'req-rb2' );
		$this->assertSame( 'completed', $forced['status'] );
	}

	public function test_rollback_refuses_when_page_became_the_homepage(): void {
		$this->page( 5 );
		$envelope                                  = rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-1' );
		$GLOBALS['_test_options']['page_on_front'] = 5;

		$refused = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

		$this->assertSame( 'state_drift', $refused['status'] );
		$this->assertStringContainsString( 'homepage or posts page', $refused['drift'][0] );
	}

	public function test_rollback_refuses_when_page_no_longer_exists(): void {
		$this->page( 5 );
		$envelope = rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-1' );
		unset( $GLOBALS['_test_posts'][5] );

		$refused = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

		$this->assertSame( 'state_drift', $refused['status'] );
		$this->assertStringContainsString( 'no longer exists', $refused['drift'][0] );
	}

	public function test_rollback_cannot_run_twice(): void {
		$this->page( 5 );
		$envelope = rr_action_run( 'set_post_status', 5, $this->payload(), false, 'req-1' );
		rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

		$second = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb2' );

		$this->assertSame( 'already_rolled_back', $second['status'] );
	}
}
