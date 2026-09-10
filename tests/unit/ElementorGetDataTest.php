<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for rr_get_elementor_data_for_post().
 *
 * Pure data-access helper behind GET /elementor/{post_id} - kept separate
 * from the REST callback (rmb_elementor_get_data()) so it's testable via the
 * existing get_post()/get_post_meta() stubs without a WP_REST_Request or
 * rest_ensure_response stub, mirroring rr_validate_elementor_data()'s split
 * from rmb_elementor_set_data() (see ElementorSetDataTest.php).
 */
class ElementorGetDataTest extends TestCase {

    protected function setUp(): void {
        unset( $GLOBALS['_test_posts'] );
        unset( $GLOBALS['_test_post_meta'] );
    }

    private function seedPost( int $id ): void {
        $post     = new WP_Post();
        $post->ID = $id;
        $GLOBALS['_test_posts'][ $id ] = $post;
    }

    public function test_missing_post_returns_not_found(): void {
        $result = rr_get_elementor_data_for_post( 999 );
        $this->assertFalse( $result['found'] );
    }

    public function test_post_with_no_stored_layout_returns_null_data(): void {
        $this->seedPost( 42 );
        $result = rr_get_elementor_data_for_post( 42 );
        $this->assertTrue( $result['found'] );
        $this->assertNull( $result['elementor_data'] );
        $this->assertSame( '', $result['edit_mode'] );
        $this->assertSame( '', $result['template_type'] );
        $this->assertSame( array(), $result['page_settings'] );
    }

    public function test_post_with_stored_layout_returns_decoded_data(): void {
        $this->seedPost( 42 );
        $nodes = array(
            array(
                'id'       => 'sec1',
                'elType'   => 'section',
                'settings' => array(),
                'elements' => array(),
            ),
        );
        $GLOBALS['_test_post_meta'][42][ RR_ELEMENTOR_DATA_META_KEY ]          = json_encode( $nodes );
        $GLOBALS['_test_post_meta'][42][ RR_ELEMENTOR_EDIT_MODE_META_KEY ]     = 'builder';
        $GLOBALS['_test_post_meta'][42][ RR_ELEMENTOR_TEMPLATE_TYPE_META_KEY ] = 'wp-page';
        $GLOBALS['_test_post_meta'][42][ RR_ELEMENTOR_PAGE_SETTINGS_META_KEY ] = array( 'css_print_method' => 'internal' );

        $result = rr_get_elementor_data_for_post( 42 );

        $this->assertTrue( $result['found'] );
        $this->assertSame( $nodes, $result['elementor_data'] );
        $this->assertSame( 'builder', $result['edit_mode'] );
        $this->assertSame( 'wp-page', $result['template_type'] );
        $this->assertSame( array( 'css_print_method' => 'internal' ), $result['page_settings'] );
    }

    public function test_malformed_stored_json_returns_null_data_not_error(): void {
        $this->seedPost( 42 );
        $GLOBALS['_test_post_meta'][42][ RR_ELEMENTOR_DATA_META_KEY ] = '{not valid json';

        $result = rr_get_elementor_data_for_post( 42 );

        $this->assertTrue( $result['found'] );
        $this->assertNull( $result['elementor_data'] );
    }

    public function test_non_array_page_settings_returns_empty_array(): void {
        $this->seedPost( 42 );
        $GLOBALS['_test_post_meta'][42][ RR_ELEMENTOR_PAGE_SETTINGS_META_KEY ] = '';

        $result = rr_get_elementor_data_for_post( 42 );

        $this->assertSame( array(), $result['page_settings'] );
    }
}
