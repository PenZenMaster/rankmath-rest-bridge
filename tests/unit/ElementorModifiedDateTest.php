<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for issue #38: POST /elementor/set-data must advance the WordPress
 * modification timestamps (and so sitemap lastmod) on a real content change,
 * and only then.
 */
class ElementorModifiedDateTest extends TestCase {

    private const POST_ID = 37;

    protected function setUp(): void {
        $GLOBALS['_test_posts']             = array();
        $GLOBALS['_test_post_meta']         = array();
        $GLOBALS['_test_transients']        = array();
        $GLOBALS['_test_update_post_error'] = false;
        $GLOBALS['_test_cleaned_posts']     = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_test_update_post_error'] );
    }

    private function layout( string $text = 'Hello' ): array {
        return array(
            array(
                'id'       => 'sec1',
                'elType'   => 'section',
                'settings' => array(),
                'elements' => array(
                    array(
                        'id'         => 'w1',
                        'elType'     => 'widget',
                        'widgetType' => 'heading',
                        'settings'   => array( 'title' => $text ),
                    ),
                ),
            ),
        );
    }

    private function seedPost( array $stored_layout ): WP_Post {
        $post                    = new WP_Post();
        $post->ID                = self::POST_ID;
        $post->post_status       = 'publish';
        $post->post_title        = 'Home';
        $post->post_content      = 'Raw content';
        $post->post_date         = '2025-01-01 08:00:00';
        $post->post_date_gmt     = '2025-01-01 08:00:00';
        $post->post_modified     = '2025-06-01 09:00:00';
        $post->post_modified_gmt = '2025-06-01 09:00:00';

        $GLOBALS['_test_posts'][ self::POST_ID ] = $post;
        $GLOBALS['_test_post_meta'][ self::POST_ID ][ RR_ELEMENTOR_DATA_META_KEY ]          = json_encode( $stored_layout );
        $GLOBALS['_test_post_meta'][ self::POST_ID ][ RR_ELEMENTOR_EDIT_MODE_META_KEY ]     = 'builder';
        $GLOBALS['_test_post_meta'][ self::POST_ID ][ RR_ELEMENTOR_TEMPLATE_TYPE_META_KEY ] = 'wp-page';
        $GLOBALS['_test_transients']['rrseo_canonical_counts']                              = array( 'pages' => 1 );
        return $post;
    }

    private function request( $data, bool $dry_run = false ): WP_REST_Request {
        return new WP_REST_Request(
            array(
                'post_id'        => self::POST_ID,
                'elementor_data' => $data,
                'edit_mode'      => 'builder',
                'template_type'  => 'wp-page',
                'dry_run'        => $dry_run,
            )
        );
    }

    public function test_real_change_advances_modified_fields_and_preserves_the_rest(): void {
        $post = $this->seedPost( $this->layout( 'Hello' ) );

        $result = rmb_elementor_set_data( $this->request( $this->layout( 'Hello, world' ) ) );

        $this->assertTrue( $result['changed'] );
        $this->assertTrue( $result['post_modified_updated'] );
        $this->assertSame( '2026-08-13 00:00:00', $post->post_modified );
        $this->assertSame( '2026-08-13 00:00:00', $post->post_modified_gmt );
        $this->assertSame( '2025-01-01 08:00:00', $post->post_date );
        $this->assertSame( '2025-01-01 08:00:00', $post->post_date_gmt );
        $this->assertSame( 'publish', $post->post_status );
        $this->assertSame( 'Home', $post->post_title );
        $this->assertSame( 'Raw content', $post->post_content );
    }

    public function test_real_change_invalidates_canonical_cache(): void {
        $this->seedPost( $this->layout( 'Hello' ) );

        rmb_elementor_set_data( $this->request( $this->layout( 'Changed' ) ) );

        $this->assertArrayNotHasKey( 'rrseo_canonical_counts', $GLOBALS['_test_transients'] );
    }

    public function test_dry_run_does_not_touch_dates_or_cache(): void {
        $post = $this->seedPost( $this->layout( 'Hello' ) );

        $result = rmb_elementor_set_data( $this->request( $this->layout( 'Changed' ), true ) );

        $this->assertTrue( $result['dry_run'] );
        $this->assertSame( '2025-06-01 09:00:00', $post->post_modified );
        $this->assertSame( '2025-06-01 09:00:00', $post->post_modified_gmt );
        $this->assertArrayHasKey( 'rrseo_canonical_counts', $GLOBALS['_test_transients'] );
    }

    public function test_validation_failure_does_not_touch_dates_or_cache(): void {
        $post = $this->seedPost( $this->layout( 'Hello' ) );

        $result = rmb_elementor_set_data( $this->request( 'not-an-array' ) );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( '2025-06-01 09:00:00', $post->post_modified );
        $this->assertSame( '2025-06-01 09:00:00', $post->post_modified_gmt );
        $this->assertArrayHasKey( 'rrseo_canonical_counts', $GLOBALS['_test_transients'] );
    }

    public function test_identical_payload_does_not_advance_dates(): void {
        $post = $this->seedPost( $this->layout( 'Hello' ) );

        $result = rmb_elementor_set_data( $this->request( $this->layout( 'Hello' ) ) );

        $this->assertFalse( $result['changed'] );
        $this->assertFalse( $result['post_modified_updated'] );
        $this->assertSame( '2025-06-01 09:00:00', $post->post_modified );
        $this->assertSame( '2025-06-01 09:00:00', $post->post_modified_gmt );
        $this->assertArrayHasKey( 'rrseo_canonical_counts', $GLOBALS['_test_transients'] );
    }

    public function test_identical_payload_with_reordered_keys_is_not_a_change(): void {
        $post      = $this->seedPost( $this->layout( 'Hello' ) );
        $reordered = $this->layout( 'Hello' );
        $reordered[0] = array_reverse( $reordered[0], true );

        $result = rmb_elementor_set_data( $this->request( $reordered ) );

        $this->assertFalse( $result['changed'] );
        $this->assertSame( '2025-06-01 09:00:00', $post->post_modified_gmt );
    }

    public function test_changed_edit_mode_counts_as_a_change(): void {
        $post = $this->seedPost( $this->layout( 'Hello' ) );
        $GLOBALS['_test_post_meta'][ self::POST_ID ][ RR_ELEMENTOR_EDIT_MODE_META_KEY ] = '';

        $result = rmb_elementor_set_data( $this->request( $this->layout( 'Hello' ) ) );

        $this->assertTrue( $result['changed'] );
        $this->assertSame( '2026-08-13 00:00:00', $post->post_modified_gmt );
    }

    public function test_timestamp_update_failure_keeps_write_and_reports_false(): void {
        $post                               = $this->seedPost( $this->layout( 'Hello' ) );
        $GLOBALS['_test_update_post_error'] = true;

        $result = rmb_elementor_set_data( $this->request( $this->layout( 'Changed' ) ) );

        $this->assertNotInstanceOf( WP_Error::class, $result );
        $this->assertTrue( $result['changed'] );
        $this->assertFalse( $result['post_modified_updated'] );
        $this->assertSame( '2025-06-01 09:00:00', $post->post_modified );
        $this->assertSame( '2025-06-01 09:00:00', $post->post_modified_gmt );
        $this->assertArrayHasKey( 'rrseo_canonical_counts', $GLOBALS['_test_transients'] );

        $stored = $GLOBALS['_test_post_meta'][ self::POST_ID ][ RR_ELEMENTOR_DATA_META_KEY ];
        $this->assertStringContainsString( 'Changed', stripslashes( (string) $stored ) );
    }
}
