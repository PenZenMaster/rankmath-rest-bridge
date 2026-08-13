<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the redirects module (includes/class-rrseo-redirects.php,
 * issue #21 Stage 1).
 *
 * Covers rr_validate_redirect_fields() (pure), rr_redirect_match() (pure),
 * rr_redirect_generate_id() (pure), and the full pipeline functions
 * (rr_redirect_create/update/delete/bulk_create/preview). REST handlers
 * are thin wrappers over these pipeline functions and are not exercised
 * directly here, matching this codebase's existing test convention (see
 * ActionEngineTest.php).
 */
class RedirectsTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_test_options']       = array();
        $GLOBALS['_test_cache_deletes'] = array();
        $GLOBALS['_test_fired_actions'] = array();
        $GLOBALS['_test_redirects']     = array();
    }

    private function seed( array $redirect ): void {
        $redirects = $GLOBALS['_test_options'][ RR_REDIRECTS_KEY ] ?? array();
        $redirects[ $redirect['id'] ] = $redirect;
        $GLOBALS['_test_options'][ RR_REDIRECTS_KEY ] = $redirects;
    }

    private function make_rule( array $overrides = array() ): array {
        return array_merge(
            array(
                'id'          => 'old-page',
                'source'      => '/old-page',
                'target'      => '/new-page',
                'match_type'  => 'exact',
                'status_code' => 301,
                'enabled'     => true,
                'created_at'  => '2026-08-13 00:00:00',
                'updated_at'  => '2026-08-13 00:00:00',
            ),
            $overrides
        );
    }

    // ── rr_validate_redirect_fields() ───────────────────────────────────────

    public function test_missing_source_is_rejected(): void {
        $v = rr_validate_redirect_fields( array( 'target' => '/new' ) );
        $this->assertContains( 'source is required', $v['errors'] );
    }

    public function test_missing_target_is_rejected(): void {
        $v = rr_validate_redirect_fields( array( 'source' => '/old' ) );
        $this->assertContains( 'target is required', $v['errors'] );
    }

    public function test_source_without_leading_slash_is_rejected(): void {
        $v = rr_validate_redirect_fields( array( 'source' => 'old', 'target' => '/new' ) );
        $this->assertNotEmpty( $v['errors'] );
        $this->assertStringContainsString( "must start with '/'", $v['errors'][0] );
    }

    public function test_target_without_leading_slash_is_rejected(): void {
        $v = rr_validate_redirect_fields( array( 'source' => '/old', 'target' => 'new' ) );
        $this->assertNotEmpty( $v['errors'] );
        $this->assertStringContainsString( "must start with '/'", $v['errors'][0] );
    }

    public function test_identical_source_and_target_is_rejected(): void {
        $v = rr_validate_redirect_fields( array( 'source' => '/same', 'target' => '/same' ) );
        $this->assertContains( 'source and target must not be identical (redirect loop)', $v['errors'] );
    }

    public function test_blocked_wp_core_sources_are_rejected(): void {
        foreach ( RR_REDIRECT_BLOCKED_SOURCES as $blocked ) {
            $v = rr_validate_redirect_fields( array( 'source' => $blocked, 'target' => '/elsewhere' ) );
            $this->assertNotEmpty( $v['errors'], "Expected '{$blocked}' to be rejected" );
            $this->assertStringContainsString( 'WordPress core path', $v['errors'][0] );
        }
    }

    public function test_blocked_source_rejects_subpaths_too(): void {
        $v = rr_validate_redirect_fields( array( 'source' => '/wp-admin/foo', 'target' => '/elsewhere' ) );
        $this->assertNotEmpty( $v['errors'] );
        $this->assertStringContainsString( 'WordPress core path', $v['errors'][0] );
    }

    public function test_invalid_match_type_is_rejected(): void {
        $v = rr_validate_redirect_fields( array( 'source' => '/a', 'target' => '/b', 'match_type' => 'regex' ) );
        $this->assertNotEmpty( $v['errors'] );
        $this->assertStringContainsString( 'match_type must be one of', $v['errors'][0] );
    }

    public function test_invalid_status_code_is_rejected(): void {
        $v = rr_validate_redirect_fields( array( 'source' => '/a', 'target' => '/b', 'status_code' => 200 ) );
        $this->assertNotEmpty( $v['errors'] );
        $this->assertStringContainsString( 'status_code must be one of', $v['errors'][0] );
    }

    public function test_defaults_applied_when_omitted(): void {
        $v = rr_validate_redirect_fields( array( 'source' => '/a', 'target' => '/b' ) );
        $this->assertSame( array(), $v['errors'] );
        $this->assertSame( 'exact', $v['normalized']['match_type'] );
        $this->assertSame( 301, $v['normalized']['status_code'] );
        $this->assertTrue( $v['normalized']['enabled'] );
    }

    public function test_trailing_slash_normalized_on_source(): void {
        $v = rr_validate_redirect_fields( array( 'source' => '/old-page/', 'target' => '/new-page' ) );
        $this->assertSame( array(), $v['errors'] );
        $this->assertSame( '/old-page', $v['normalized']['source'] );
    }

    public function test_root_source_stays_root(): void {
        $v = rr_validate_redirect_fields( array( 'source' => '/', 'target' => '/new-page' ) );
        $this->assertSame( array(), $v['errors'] );
        $this->assertSame( '/', $v['normalized']['source'] );
    }

    public function test_source_collision_with_enabled_redirect_is_rejected(): void {
        $this->seed( $this->make_rule() );
        $v = rr_validate_redirect_fields( array( 'source' => '/old-page', 'target' => '/somewhere-else' ) );
        $this->assertNotEmpty( $v['errors'] );
        $this->assertStringContainsString( 'already used by redirect', $v['errors'][0] );
    }

    public function test_source_collision_skipped_against_own_id_on_update(): void {
        $this->seed( $this->make_rule() );
        $v = rr_validate_redirect_fields(
            array( 'source' => '/old-page', 'target' => '/updated-target' ),
            'old-page'
        );
        $this->assertSame( array(), $v['errors'] );
    }

    public function test_source_collision_ignores_disabled_redirects(): void {
        $this->seed( $this->make_rule( array( 'enabled' => false ) ) );
        $v = rr_validate_redirect_fields( array( 'source' => '/old-page', 'target' => '/somewhere-else' ) );
        $this->assertSame( array(), $v['errors'] );
    }

    // ── rr_redirect_match() ──────────────────────────────────────────────────

    public function test_exact_match_hits(): void {
        $rules = array( 'r1' => $this->make_rule() );
        $match = rr_redirect_match( '/old-page', $rules );
        $this->assertSame( 'old-page', $match['id'] );
    }

    public function test_exact_match_requires_full_equality(): void {
        $rules = array( 'r1' => $this->make_rule() );
        $this->assertNull( rr_redirect_match( '/old-page/extra', $rules ) );
    }

    public function test_prefix_match_hits_subpaths(): void {
        $rules = array(
            'r1' => $this->make_rule( array( 'id' => 'r1', 'source' => '/old-services', 'match_type' => 'prefix' ) ),
        );
        $match = rr_redirect_match( '/old-services/plumbing', $rules );
        $this->assertSame( 'r1', $match['id'] );
    }

    public function test_longest_prefix_wins_on_overlap(): void {
        $rules = array(
            'short' => $this->make_rule( array( 'id' => 'short', 'source' => '/old', 'target' => '/a', 'match_type' => 'prefix' ) ),
            'long'  => $this->make_rule( array( 'id' => 'long', 'source' => '/old/services', 'target' => '/b', 'match_type' => 'prefix' ) ),
        );
        $match = rr_redirect_match( '/old/services/plumbing', $rules );
        $this->assertSame( 'long', $match['id'] );
    }

    public function test_disabled_rules_are_skipped(): void {
        $rules = array( 'r1' => $this->make_rule( array( 'enabled' => false ) ) );
        $this->assertNull( rr_redirect_match( '/old-page', $rules ) );
    }

    public function test_no_match_returns_null(): void {
        $rules = array( 'r1' => $this->make_rule() );
        $this->assertNull( rr_redirect_match( '/unrelated', $rules ) );
    }

    public function test_exact_rule_wins_over_overlapping_prefix_rule(): void {
        $rules = array(
            'prefix_rule' => $this->make_rule( array( 'id' => 'prefix_rule', 'source' => '/old', 'target' => '/a', 'match_type' => 'prefix' ) ),
            'exact_rule'  => $this->make_rule( array( 'id' => 'exact_rule', 'source' => '/old/page', 'target' => '/b', 'match_type' => 'exact' ) ),
        );
        $match = rr_redirect_match( '/old/page', $rules );
        $this->assertSame( 'exact_rule', $match['id'] );
    }

    // ── rr_redirect_generate_id() ────────────────────────────────────────────

    public function test_generate_id_slugifies_source(): void {
        $this->assertSame( 'page-sitemap-xml', rr_redirect_generate_id( '/page-sitemap.xml', array() ) );
    }

    public function test_generate_id_increments_on_collision(): void {
        $taken = array( 'old-page' => true );
        $this->assertSame( 'old-page_1', rr_redirect_generate_id( '/old-page', $taken ) );
    }

    // ── Pipeline: create / list / get / update / delete round trip ──────────

    public function test_create_then_list_and_get_round_trip(): void {
        $result = rr_redirect_create( array( 'source' => '/page-sitemap.xml', 'target' => '/sitemap_index.xml' ) );

        $this->assertSame( 'created', $result['status'] );
        $this->assertSame( '/page-sitemap.xml', $result['redirect']['source'] );

        $id = $result['redirect']['id'];
        $this->assertSame( $result['redirect'], rr_redirect_get( $id ) );
        $this->assertArrayHasKey( $id, rr_redirect_list() );
    }

    public function test_create_invalid_returns_errors_without_writing(): void {
        $result = rr_redirect_create( array( 'source' => '/same', 'target' => '/same' ) );

        $this->assertSame( 'invalid', $result['status'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertSame( array(), rr_redirect_list() );
    }

    public function test_create_dry_run_does_not_persist(): void {
        $result = rr_redirect_create( array( 'source' => '/a', 'target' => '/b' ), true );

        $this->assertSame( 'simulated', $result['status'] );
        $this->assertSame( '/a', $result['redirect']['source'] );
        $this->assertSame( array(), rr_redirect_list() );
    }

    public function test_update_not_found(): void {
        $result = rr_redirect_update( 'nope', array( 'target' => '/x' ) );
        $this->assertSame( 'not_found', $result['status'] );
    }

    public function test_update_changes_stored_fields(): void {
        $this->seed( $this->make_rule() );
        $result = rr_redirect_update( 'old-page', array( 'target' => '/brand-new-target' ) );

        $this->assertSame( 'updated', $result['status'] );
        $this->assertSame( '/brand-new-target', $result['redirect']['target'] );
        $this->assertSame( '/brand-new-target', rr_redirect_get( 'old-page' )['target'] );
        // id is immutable even though source is unchanged here.
        $this->assertSame( 'old-page', $result['redirect']['id'] );
    }

    public function test_update_dry_run_does_not_persist(): void {
        $this->seed( $this->make_rule() );
        $result = rr_redirect_update( 'old-page', array( 'target' => '/would-be-new' ), true );

        $this->assertSame( 'simulated', $result['status'] );
        $this->assertSame( '/would-be-new', $result['redirect']['target'] );
        $this->assertSame( '/new-page', rr_redirect_get( 'old-page' )['target'] );
    }

    public function test_update_invalid_leaves_stored_value_unchanged(): void {
        $this->seed( $this->make_rule() );
        $result = rr_redirect_update( 'old-page', array( 'status_code' => 999 ) );

        $this->assertSame( 'invalid', $result['status'] );
        $this->assertSame( 301, rr_redirect_get( 'old-page' )['status_code'] );
    }

    public function test_delete_removes_stored_redirect(): void {
        $this->seed( $this->make_rule() );
        $result = rr_redirect_delete( 'old-page' );

        $this->assertSame( 'deleted', $result['status'] );
        $this->assertNull( rr_redirect_get( 'old-page' ) );
    }

    public function test_delete_not_found(): void {
        $result = rr_redirect_delete( 'nope' );
        $this->assertSame( 'not_found', $result['status'] );
    }

    public function test_write_paths_bust_cache_and_purge_rest(): void {
        rr_redirect_create( array( 'source' => '/a', 'target' => '/b' ) );

        $busted_keys = array_column( $GLOBALS['_test_cache_deletes'], 'key' );
        $this->assertContains( RR_REDIRECTS_KEY, $busted_keys );

        $purges = array_column( $GLOBALS['_test_fired_actions'], 'hook' );
        $this->assertContains( 'litespeed_purge_url', $purges );
    }

    // ── Pipeline: bulk create ────────────────────────────────────────────────

    public function test_bulk_create_writes_all_valid_items(): void {
        $result = rr_redirect_bulk_create(
            array(
                array( 'source' => '/a', 'target' => '/aa' ),
                array( 'source' => '/b', 'target' => '/bb' ),
            )
        );

        $this->assertSame( 'created', $result['status'] );
        $this->assertCount( 2, $result['redirects'] );
        $this->assertCount( 2, rr_redirect_list() );
    }

    public function test_bulk_create_is_atomic_on_one_bad_item(): void {
        $result = rr_redirect_bulk_create(
            array(
                array( 'source' => '/a', 'target' => '/aa' ),
                array( 'source' => '/bad-no-target' ),
            )
        );

        $this->assertSame( 'invalid', $result['status'] );
        $this->assertCount( 1, $result['errors'] );
        $this->assertSame( 1, $result['errors'][0]['index'] );
        // Nothing persisted, including the otherwise-valid first item.
        $this->assertSame( array(), rr_redirect_list() );
    }

    public function test_bulk_create_rejects_duplicate_source_within_batch(): void {
        $result = rr_redirect_bulk_create(
            array(
                array( 'source' => '/dup', 'target' => '/one' ),
                array( 'source' => '/dup', 'target' => '/two' ),
            )
        );

        $this->assertSame( 'invalid', $result['status'] );
        $this->assertStringContainsString( 'duplicated elsewhere in this batch', $result['errors'][0]['error'] );
        $this->assertSame( array(), rr_redirect_list() );
    }

    public function test_bulk_create_empty_array_is_invalid(): void {
        $result = rr_redirect_bulk_create( array() );
        $this->assertSame( 'invalid', $result['status'] );
    }

    public function test_bulk_create_dry_run_does_not_persist(): void {
        $result = rr_redirect_bulk_create(
            array(
                array( 'source' => '/a', 'target' => '/aa' ),
                array( 'source' => '/b', 'target' => '/bb' ),
            ),
            true
        );

        $this->assertSame( 'simulated', $result['status'] );
        $this->assertCount( 2, $result['redirects'] );
        $this->assertSame( array(), rr_redirect_list() );
    }

    public function test_bulk_create_dry_run_still_reports_validation_errors(): void {
        $result = rr_redirect_bulk_create(
            array(
                array( 'source' => '/a', 'target' => '/aa' ),
                array( 'source' => '/bad-no-target' ),
            ),
            true
        );

        $this->assertSame( 'invalid', $result['status'] );
        $this->assertSame( array(), rr_redirect_list() );
    }

    public function test_bulk_create_enforces_batch_max(): void {
        $items = array();
        for ( $i = 0; $i < rrseo_batch_max() + 1; $i++ ) {
            $items[] = array( 'source' => "/item-{$i}", 'target' => "/target-{$i}" );
        }

        $result = rr_redirect_bulk_create( $items );
        $this->assertSame( 'invalid', $result['status'] );
        $this->assertStringContainsString( 'exceeds the maximum', $result['errors'][0]['error'] );
    }

    // ── Pipeline: preview ─────────────────────────────────────────────────────

    public function test_preview_reports_would_redirect_on_match(): void {
        $this->seed( $this->make_rule() );
        $result = rr_redirect_preview( '/old-page' );

        $this->assertTrue( $result['would_redirect'] );
        $this->assertSame( 'old-page', $result['matched_rule_id'] );
        $this->assertSame( '/new-page', $result['target'] );
        $this->assertSame( 301, $result['status_code'] );
    }

    public function test_preview_reports_no_match(): void {
        $this->seed( $this->make_rule() );
        $result = rr_redirect_preview( '/unrelated' );

        $this->assertFalse( $result['would_redirect'] );
    }

    public function test_preview_does_not_persist_anything(): void {
        rr_redirect_preview( '/whatever' );
        $this->assertArrayNotHasKey( RR_REDIRECTS_KEY, $GLOBALS['_test_options'] );
    }

    public function test_preview_handles_query_string_and_host(): void {
        $this->seed( $this->make_rule() );
        $result = rr_redirect_preview( 'https://example.test/old-page?utm_source=x' );

        $this->assertTrue( $result['would_redirect'] );
        $this->assertSame( 'old-page', $result['matched_rule_id'] );
    }
}
