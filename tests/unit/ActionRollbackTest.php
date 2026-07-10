<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the v3.0 Bite 3 rollback layer
 * (includes/class-rrseo-actions.php).
 *
 * Covers rr_action_log_find(), and the rr_action_rollback_run() pipeline:
 * not_found / not_reversible / already_rolled_back / state_drift refusals,
 * force override, dry-run simulation, per-type restore semantics (including
 * delete-on-empty-prior), envelope bookkeeping (original marked, rollback
 * record appended), audit rows for post-targeted actions, and the v2.17.x
 * cache-bust invariant. REST handlers are thin wrappers over
 * rr_action_rollback_run() and are exercised by the staging validation pass.
 */
class ActionRollbackTest extends TestCase {

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

    /**
     * Executes an action through the real pipeline and returns its envelope,
     * so rollback tests run against genuine stored state.
     */
    private function execute( string $type, $target, array $payload ): array {
        $envelope = rr_action_run( $type, $target, $payload, false, 'req-setup' );
        $this->assertSame( 'completed', $envelope['status'] );
        return $envelope;
    }

    // ── rr_action_log_find() ──────────────────────────────────────────────────

    public function test_log_find_returns_envelope_and_index(): void {
        $GLOBALS['_test_options']['blog_public'] = '0';
        $envelope                                = $this->execute( 'update_setting', 'blog_public', array( 'new_value' => 1 ) );

        $found = rr_action_log_find( $envelope['action_id'] );

        $this->assertNotNull( $found );
        $this->assertSame( 0, $found['index'] );
        $this->assertSame( $envelope['action_id'], $found['envelope']['action_id'] );
    }

    public function test_log_find_returns_null_for_unknown_id(): void {
        $this->assertNull( rr_action_log_find( 'rrseo-action-20990101000000-deadbeef' ) );
    }

    // ── Refusal paths ─────────────────────────────────────────────────────────

    public function test_rollback_unknown_action_returns_not_found(): void {
        $result = rr_action_rollback_run( 'rrseo-action-20990101000000-deadbeef', false, false, 'req-rb' );

        $this->assertSame( 'not_found', $result['status'] );
        $this->assertSame( array(), $GLOBALS['_test_options'] );
    }

    public function test_rollback_of_irreversible_action_is_refused_with_reason(): void {
        $GLOBALS['_test_options'][ RR_LLMS_CONFIG_KEY ] = array();
        $envelope                                       = $this->execute( 'regenerate_llms_txt', null, array() );

        $result = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

        $this->assertSame( 'not_reversible', $result['status'] );
        $this->assertStringContainsString( 'rendered from live configuration', $result['reason'] );
    }

    public function test_double_rollback_is_refused_with_first_rollback_reference(): void {
        $GLOBALS['_test_options']['blog_public'] = '0';
        $envelope                                = $this->execute( 'update_setting', 'blog_public', array( 'new_value' => 1 ) );

        $first  = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb1' );
        $second = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb2' );

        $this->assertSame( 'completed', $first['status'] );
        $this->assertSame( 'already_rolled_back', $second['status'] );
        $this->assertSame( $first['action_id'], $second['rollback_action_id'] );
        // Value stayed rolled back; the second call wrote nothing.
        $this->assertSame( '0', $GLOBALS['_test_options']['blog_public'] );
    }

    public function test_rollback_of_a_rollback_record_is_refused(): void {
        $GLOBALS['_test_options']['blog_public'] = '0';
        $envelope                                = $this->execute( 'update_setting', 'blog_public', array( 'new_value' => 1 ) );

        $rollback = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );
        $result   = rr_action_rollback_run( $rollback['action_id'], false, false, 'req-rb2' );

        $this->assertSame( 'not_reversible', $result['status'] );
        $this->assertStringContainsString( 're-execute the original action', $result['reason'] );
    }

    // ── Drift detection ───────────────────────────────────────────────────────

    public function test_drift_refuses_rollback_and_force_overrides(): void {
        $GLOBALS['_test_options']['blog_public'] = '0';
        $envelope                                = $this->execute( 'update_setting', 'blog_public', array( 'new_value' => 1 ) );

        // Someone changes the option after the action executed.
        $GLOBALS['_test_options']['blog_public'] = '2';

        $refused = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );
        $this->assertSame( 'state_drift', $refused['status'] );
        $this->assertNotEmpty( $refused['drift'] );
        $this->assertSame( '2', $GLOBALS['_test_options']['blog_public'] );

        $forced = rr_action_rollback_run( $envelope['action_id'], false, true, 'req-rb' );
        $this->assertSame( 'completed', $forced['status'] );
        // Drift is surfaced as a warning on the forced rollback record.
        $this->assertNotEmpty( $forced['warnings'] );
        $this->assertSame( '0', $GLOBALS['_test_options']['blog_public'] );
    }

    // ── Dry run ───────────────────────────────────────────────────────────────

    public function test_dry_run_simulates_without_writing_or_marking(): void {
        $GLOBALS['_test_options']['blog_public'] = '0';
        $envelope                                = $this->execute( 'update_setting', 'blog_public', array( 'new_value' => 1 ) );
        $log_before                              = $GLOBALS['_test_options'][ RR_ACTION_LOG_KEY ];

        $result = rr_action_rollback_run( $envelope['action_id'], true, false, 'req-dry' );

        $this->assertSame( 'simulated', $result['status'] );
        $this->assertNull( $result['action_id'] );
        $this->assertSame( '1', $result['before'] );
        $this->assertSame( '0', $result['after'] );
        // Nothing changed: option still applied, log untouched, original unmarked.
        $this->assertSame( '1', $GLOBALS['_test_options']['blog_public'] );
        $this->assertSame( $log_before, $GLOBALS['_test_options'][ RR_ACTION_LOG_KEY ] );

        // A real rollback still succeeds afterwards.
        $real = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );
        $this->assertSame( 'completed', $real['status'] );
    }

    // ── Restore semantics per action type ─────────────────────────────────────

    public function test_rollback_update_setting_restores_and_bookkeeps(): void {
        $GLOBALS['_test_options']['blog_public'] = '0';
        $envelope                                = $this->execute( 'update_setting', 'blog_public', array( 'new_value' => 1 ) );
        $GLOBALS['_test_cache_deletes']          = array();
        $GLOBALS['_test_fired_actions']          = array();

        $result = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

        $this->assertSame( 'completed', $result['status'] );
        $this->assertSame( '0', $GLOBALS['_test_options']['blog_public'] );
        $this->assertSame( $envelope['action_id'], $result['target_id'] );
        $this->assertFalse( $result['reversible'] );

        // Log now holds the marked original plus the rollback record.
        $log = $GLOBALS['_test_options'][ RR_ACTION_LOG_KEY ];
        $this->assertCount( 2, $log );
        $this->assertSame( $result['action_id'], $log[0]['rollback_action_id'] );
        $this->assertNotEmpty( $log[0]['rolled_back_at'] );
        $this->assertSame( 'rollback', $log[1]['action_type'] );

        // v2.17.x invariant: option + log busted, page cache purge fired.
        $busted_keys = array_column( $GLOBALS['_test_cache_deletes'], 'key' );
        $this->assertContains( 'blog_public', $busted_keys );
        $this->assertContains( RR_ACTION_LOG_KEY, $busted_keys );
        $purges = array_column( $GLOBALS['_test_fired_actions'], 'hook' );
        $this->assertContains( 'litespeed_purge_url', $purges );
    }

    public function test_rollback_toggle_indexing_deletes_meta_when_prior_was_absent(): void {
        $this->make_post( 42 );
        $robots_key = RR_SEO_META_KEYS['robots'];
        // No prior robots meta: before is ''.
        $envelope = $this->execute( 'toggle_indexing', 42, array( 'new_value' => 'noindex' ) );
        $this->assertSame( 'noindex', $GLOBALS['_test_post_meta'][42][ $robots_key ] );

        $result = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

        $this->assertSame( 'completed', $result['status'] );
        // Prior state was "no directive stored" -- the key is deleted, not ''.
        $this->assertArrayNotHasKey( $robots_key, $GLOBALS['_test_post_meta'][42] );

        // Post-targeted rollback writes a per-post audit row.
        $audit = $GLOBALS['_test_post_meta'][42][ RR_CHANGE_LOG_KEY ];
        $this->assertIsArray( $audit );
        $this->assertSame( '/actions/rollback', end( $audit )['endpoint'] );
    }

    public function test_rollback_toggle_indexing_restores_prior_value(): void {
        $this->make_post( 42 );
        $robots_key                                    = RR_SEO_META_KEYS['robots'];
        $GLOBALS['_test_post_meta'][42][ $robots_key ] = 'index';
        $envelope                                      = $this->execute( 'toggle_indexing', 42, array( 'new_value' => 'noindex' ) );

        $result = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

        $this->assertSame( 'completed', $result['status'] );
        $this->assertSame( 'index', $GLOBALS['_test_post_meta'][42][ $robots_key ] );
    }

    public function test_rollback_update_meta_draft_deletes_new_and_restores_old(): void {
        $this->make_post( 5 );
        $title_draft = RR_ACTION_DRAFT_PREFIX . 'title';
        $desc_draft  = RR_ACTION_DRAFT_PREFIX . 'description';
        // title draft pre-exists; description draft does not.
        $GLOBALS['_test_post_meta'][5][ $title_draft ] = 'Old Draft Title Long Enough To Pass';

        $envelope = $this->execute(
            'update_meta_draft',
            5,
            array(
                'fields' => array(
                    'title'       => 'New Draft Title Long Enough To Pass',
                    'description' => 'A new draft description that is comfortably long enough to validate.',
                ),
            )
        );

        $result = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

        $this->assertSame( 'completed', $result['status'] );
        // Pre-existing draft restored; brand-new draft deleted outright.
        $this->assertSame( 'Old Draft Title Long Enough To Pass', $GLOBALS['_test_post_meta'][5][ $title_draft ] );
        $this->assertArrayNotHasKey( $desc_draft, $GLOBALS['_test_post_meta'][5] );
    }

    public function test_meta_draft_drift_detected_per_field(): void {
        $this->make_post( 5 );
        $envelope = $this->execute(
            'update_meta_draft',
            5,
            array( 'fields' => array( 'title' => 'New Draft Title Long Enough To Pass' ) )
        );

        // Draft edited by someone else after the action.
        $GLOBALS['_test_post_meta'][5][ RR_ACTION_DRAFT_PREFIX . 'title' ] = 'Manually Edited Draft';

        $result = rr_action_rollback_run( $envelope['action_id'], false, false, 'req-rb' );

        $this->assertSame( 'state_drift', $result['status'] );
        $this->assertStringContainsString( 'title', $result['drift'][0] );
    }
}
