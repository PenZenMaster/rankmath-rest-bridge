<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for rr_validate_seo_fields().
 *
 * Runs with $post_id = null to avoid WP meta/post-type checks unless
 * explicitly exercising the post-lookup branch.
 */
class SeoValidationTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_test_posts'] = [];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_test_posts'] );
    }

    // ------------------------------------------------------------------
    // Title
    // ------------------------------------------------------------------

    public function test_title_within_ideal_range_passes(): void {
        $result = rr_validate_seo_fields( [ 'title' => str_repeat( 'a', 45 ) ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertEmpty( $result['warnings'] );
    }

    public function test_title_exceeds_hard_limit_returns_error(): void {
        $result = rr_validate_seo_fields( [ 'title' => str_repeat( 'a', RR_TITLE_MAX + 1 ) ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'hard limit', $result['errors'][0] );
    }

    public function test_title_above_warn_max_returns_warning(): void {
        $result = rr_validate_seo_fields( [ 'title' => str_repeat( 'a', RR_TITLE_WARN_MAX + 1 ) ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertNotEmpty( $result['warnings'] );
        $this->assertStringContainsString( 'above recommended maximum', $result['warnings'][0] );
    }

    public function test_title_below_warn_min_returns_warning(): void {
        $result = rr_validate_seo_fields( [ 'title' => str_repeat( 'a', RR_TITLE_WARN_MIN - 1 ) ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertNotEmpty( $result['warnings'] );
        $this->assertStringContainsString( 'below recommended minimum', $result['warnings'][0] );
    }

    public function test_title_exactly_at_hard_limit_passes(): void {
        $result = rr_validate_seo_fields( [ 'title' => str_repeat( 'a', RR_TITLE_MAX ) ] );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_empty_title_skips_validation(): void {
        $result = rr_validate_seo_fields( [ 'title' => '' ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertEmpty( $result['warnings'] );
    }

    // ------------------------------------------------------------------
    // Description
    // ------------------------------------------------------------------

    public function test_description_within_ideal_range_passes(): void {
        $result = rr_validate_seo_fields( [ 'description' => str_repeat( 'a', 100 ) ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertEmpty( $result['warnings'] );
    }

    public function test_description_exceeds_hard_limit_returns_error(): void {
        $result = rr_validate_seo_fields( [ 'description' => str_repeat( 'a', RR_DESC_MAX + 1 ) ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'hard limit', $result['errors'][0] );
    }

    public function test_description_above_warn_max_returns_warning(): void {
        $result = rr_validate_seo_fields( [ 'description' => str_repeat( 'a', RR_DESC_WARN_MAX + 1 ) ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertNotEmpty( $result['warnings'] );
    }

    public function test_description_below_warn_min_returns_warning(): void {
        $result = rr_validate_seo_fields( [ 'description' => str_repeat( 'a', RR_DESC_WARN_MIN - 1 ) ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertNotEmpty( $result['warnings'] );
    }

    // ------------------------------------------------------------------
    // OG image URL
    // ------------------------------------------------------------------

    public function test_og_image_valid_url_passes(): void {
        $result = rr_validate_seo_fields( [ 'og_image' => 'https://example.com/image.jpg' ] );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_og_image_invalid_url_returns_error(): void {
        $result = rr_validate_seo_fields( [ 'og_image' => 'not-a-url' ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'not a valid URL', $result['errors'][0] );
    }

    public function test_og_image_empty_skips_validation(): void {
        $result = rr_validate_seo_fields( [ 'og_image' => '' ] );
        $this->assertEmpty( $result['errors'] );
    }

    // ------------------------------------------------------------------
    // Robots
    // ------------------------------------------------------------------

    public function test_robots_valid_single_value_passes(): void {
        $result = rr_validate_seo_fields( [ 'robots' => 'noindex' ] );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_robots_valid_combined_passes(): void {
        $result = rr_validate_seo_fields( [ 'robots' => 'noindex,nofollow' ] );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_robots_invalid_value_returns_error(): void {
        $result = rr_validate_seo_fields( [ 'robots' => 'all,badvalue' ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'invalid value', $result['errors'][0] );
    }

    // ------------------------------------------------------------------
    // Post-ID branch
    // ------------------------------------------------------------------

    public function test_unknown_post_id_returns_error(): void {
        // get_post() stub returns null for any ID not in $GLOBALS['_test_posts'].
        $result = rr_validate_seo_fields( [], 9999 );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'post not found', $result['errors'][0] );
    }

    public function test_known_post_allowed_type_passes(): void {
        $post             = new stdClass();
        $post->post_type  = 'post';
        $GLOBALS['_test_posts'][1] = $post;

        $result = rr_validate_seo_fields( [], 1 );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_known_post_disallowed_type_returns_error(): void {
        $post             = new stdClass();
        $post->post_type  = 'custom_type_xyz';
        $GLOBALS['_test_posts'][2] = $post;

        $result = rr_validate_seo_fields( [], 2 );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'not allowed', $result['errors'][0] );
    }

    // ------------------------------------------------------------------
    // Multiple fields in one call
    // ------------------------------------------------------------------

    public function test_multiple_errors_reported_together(): void {
        $result = rr_validate_seo_fields( [
            'title'    => str_repeat( 'a', RR_TITLE_MAX + 1 ),
            'og_image' => 'not-a-url',
        ] );
        $this->assertCount( 2, $result['errors'] );
    }
}
