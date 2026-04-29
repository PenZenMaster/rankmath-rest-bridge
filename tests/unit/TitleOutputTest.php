<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the rr_override_document_title() pre_get_document_title callback.
 *
 * Regression guard for the bug where add_filter( 'pre_get_document_title', ... )
 * was called inside a wp_head priority-1 callback — the same priority as WordPress
 * core's _wp_render_title_tag(), which was registered first and therefore fired
 * before the plugin's nested add_filter() call, causing SEO titles to be ignored.
 *
 * The fix registers the filter at plugin-load time. These tests verify the function
 * exists and returns correct values under each condition.
 */
class TitleOutputTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_test_is_singular']       = false;
        $GLOBALS['_test_queried_object_id'] = 0;
        $GLOBALS['_test_post_meta']         = [];
        $GLOBALS['_test_posts']             = [];
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['_test_is_singular'],
            $GLOBALS['_test_queried_object_id'],
            $GLOBALS['_test_post_meta'],
            $GLOBALS['_test_posts']
        );
    }

    // ------------------------------------------------------------------
    // Function exists
    // ------------------------------------------------------------------

    public function test_override_function_exists(): void {
        $this->assertTrue(
            function_exists( 'rr_override_document_title' ),
            'rr_override_document_title() must be defined at plugin load.'
        );
    }

    // ------------------------------------------------------------------
    // Pass-through conditions (must NOT change the title)
    // ------------------------------------------------------------------

    public function test_non_singular_page_returns_original_title(): void {
        $GLOBALS['_test_is_singular'] = false;
        $this->assertSame( 'Default Title', rr_override_document_title( 'Default Title' ) );
    }

    public function test_singular_with_no_seo_title_returns_original(): void {
        $GLOBALS['_test_is_singular']       = true;
        $GLOBALS['_test_queried_object_id'] = 10;
        // No post meta → rr_get_seo_meta returns '' → pass through.
        $this->assertSame( 'Default – Test Site', rr_override_document_title( 'Default – Test Site' ) );
    }

    public function test_singular_with_empty_string_meta_returns_original(): void {
        $GLOBALS['_test_is_singular']               = true;
        $GLOBALS['_test_queried_object_id']         = 11;
        $GLOBALS['_test_post_meta'][11]['rr_seo_title'] = '';
        $this->assertSame( 'Original', rr_override_document_title( 'Original' ) );
    }

    // ------------------------------------------------------------------
    // Override conditions (must replace the title)
    // ------------------------------------------------------------------

    public function test_singular_with_seo_title_overrides_default(): void {
        $GLOBALS['_test_is_singular']               = true;
        $GLOBALS['_test_queried_object_id']         = 20;
        $GLOBALS['_test_post_meta'][20]['rr_seo_title'] = 'My Optimized SEO Title';

        $result = rr_override_document_title( 'Page – Site Name' );
        $this->assertSame( 'My Optimized SEO Title', $result );
    }

    public function test_legacy_rank_math_title_used_when_native_absent(): void {
        // rr_get_seo_meta() falls back to rank_math_title when rr_seo_title is empty.
        $GLOBALS['_test_is_singular']                       = true;
        $GLOBALS['_test_queried_object_id']                 = 30;
        $GLOBALS['_test_post_meta'][30]['rank_math_title']  = 'Legacy RankMath Title';
        // rr_seo_title not set → get_post_meta returns '' → fallback kicks in.

        $result = rr_override_document_title( 'Default Title' );
        $this->assertSame( 'Legacy RankMath Title', $result );
    }

    public function test_native_key_takes_priority_over_legacy_key(): void {
        $GLOBALS['_test_is_singular']                       = true;
        $GLOBALS['_test_queried_object_id']                 = 40;
        $GLOBALS['_test_post_meta'][40]['rr_seo_title']     = 'Native Title';
        $GLOBALS['_test_post_meta'][40]['rank_math_title']  = 'Legacy Title';

        $result = rr_override_document_title( 'Default Title' );
        $this->assertSame( 'Native Title', $result );
    }

    // ------------------------------------------------------------------
    // Token resolution
    // ------------------------------------------------------------------

    public function test_sitename_token_is_resolved_in_seo_title(): void {
        $GLOBALS['_test_is_singular']               = true;
        $GLOBALS['_test_queried_object_id']         = 50;
        // %sitename% is resolved by rmb_resolve_tokens() via get_bloginfo('name') stub = 'Test Site'.
        $GLOBALS['_test_post_meta'][50]['rr_seo_title'] = 'Best Page | %sitename%';

        $result = rr_override_document_title( 'Default Title' );
        $this->assertSame( 'Best Page | Test Site', $result );
    }
}
