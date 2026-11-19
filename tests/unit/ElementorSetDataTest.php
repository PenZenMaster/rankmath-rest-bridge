<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for rr_validate_elementor_data().
 *
 * No WP dependencies beyond apply_filters() and wp_is_numeric_array(),
 * both stubbed in bootstrap.php.
 */
class ElementorSetDataTest extends TestCase {

    private function section( array $elements = [], string $id = 'sec1' ): array {
        return [
            'id'       => $id,
            'elType'   => 'section',
            'settings' => [],
            'elements' => $elements,
        ];
    }

    private function widget( string $widgetType, string $id = 'w1' ): array {
        return [
            'id'         => $id,
            'elType'     => 'widget',
            'widgetType' => $widgetType,
            'settings'   => [],
        ];
    }

    // ------------------------------------------------------------------
    // Top-level shape
    // ------------------------------------------------------------------

    public function test_valid_section_tree_passes(): void {
        $result = rr_validate_elementor_data( [ $this->section( [ $this->widget( 'heading' ) ] ) ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertEquals( 1, $result['widget_count'] );
    }

    public function test_valid_container_tree_passes(): void {
        $container = [
            'id'       => 'c1',
            'elType'   => 'container',
            'settings' => [],
            'elements' => [ $this->widget( 'image' ) ],
        ];
        $result = rr_validate_elementor_data( [ $container ] );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_non_array_returns_error(): void {
        $result = rr_validate_elementor_data( 'not-an-array' );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'must be an array', $result['errors'][0] );
    }

    public function test_associative_array_returns_error(): void {
        $result = rr_validate_elementor_data( [ 'foo' => 'bar' ] );
        $this->assertNotEmpty( $result['errors'] );
    }

    public function test_empty_array_returns_error(): void {
        $result = rr_validate_elementor_data( [] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'at least one element', $result['errors'][0] );
    }

    public function test_invalid_top_level_eltype_returns_error(): void {
        $node = $this->section();
        $node['elType'] = 'widget';
        $result = rr_validate_elementor_data( [ $node ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'elementor_data[0]', $result['errors'][0] );
    }

    public function test_missing_elements_returns_error(): void {
        $node = [ 'id' => 's1', 'elType' => 'section', 'settings' => [] ];
        $result = rr_validate_elementor_data( [ $node ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( "missing required 'elements'", $result['errors'][0] );
    }

    public function test_missing_id_returns_error(): void {
        $node = [ 'elType' => 'section', 'settings' => [], 'elements' => [] ];
        $result = rr_validate_elementor_data( [ $node ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( "missing required 'id'", $result['errors'][0] );
    }

    public function test_missing_settings_returns_error(): void {
        $node = [ 'id' => 's1', 'elType' => 'section', 'elements' => [] ];
        $result = rr_validate_elementor_data( [ $node ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( "missing required 'settings'", $result['errors'][0] );
    }

    public function test_non_object_node_reports_index(): void {
        $result = rr_validate_elementor_data( [ 'not-an-object' ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'elementor_data[0]', $result['errors'][0] );
    }

    public function test_second_node_error_reports_correct_index(): void {
        $result = rr_validate_elementor_data( [ $this->section(), [ 'bad' => true ] ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'elementor_data[1]', $result['errors'][0] );
    }

    // ------------------------------------------------------------------
    // Widget counting (recursive)
    // ------------------------------------------------------------------

    public function test_widget_count_zero_for_empty_section(): void {
        $result = rr_validate_elementor_data( [ $this->section() ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertEquals( 0, $result['widget_count'] );
    }

    public function test_widget_count_counts_nested_column_widgets(): void {
        $column = [
            'id'       => 'col1',
            'elType'   => 'column',
            'settings' => [],
            'elements' => [ $this->widget( 'heading', 'w1' ), $this->widget( 'image', 'w2' ) ],
        ];
        $result = rr_validate_elementor_data( [ $this->section( [ $column ] ) ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertEquals( 2, $result['widget_count'] );
    }

    public function test_widget_count_sums_across_top_level_sections(): void {
        $result = rr_validate_elementor_data( [
            $this->section( [ $this->widget( 'heading', 'w1' ) ], 's1' ),
            $this->section( [ $this->widget( 'button', 'w2' ) ], 's2' ),
        ] );
        $this->assertEquals( 2, $result['widget_count'] );
    }

    // ------------------------------------------------------------------
    // Pro-widget warnings (best-effort, non-blocking)
    // ------------------------------------------------------------------

    public function test_known_core_widget_produces_no_warning(): void {
        $result = rr_validate_elementor_data( [ $this->section( [ $this->widget( 'heading' ) ] ) ] );
        $this->assertEmpty( $result['warnings'] );
    }

    public function test_unknown_widget_type_produces_warning_not_error(): void {
        $result = rr_validate_elementor_data( [ $this->section( [ $this->widget( 'pro-forms' ) ] ) ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertNotEmpty( $result['warnings'] );
        $this->assertStringContainsString( 'pro-forms', $result['warnings'][0] );
    }

    public function test_all_core_widget_types_produce_no_warning(): void {
        foreach ( RR_ELEMENTOR_CORE_WIDGET_TYPES as $type ) {
            $result = rr_validate_elementor_data( [ $this->section( [ $this->widget( $type ) ] ) ] );
            $this->assertEmpty( $result['warnings'], "Expected widgetType '{$type}' to produce no warning." );
        }
    }

    // ------------------------------------------------------------------
    // Elementor 4 atomic elements (issue #44)
    // ------------------------------------------------------------------

    /**
     * Shape of an Elementor 4.x atomic page as returned by GET /elementor/{id}
     * on stuarthale.com: e-flexbox > e-div-block > e-heading / e-paragraph,
     * with settings stored as $$type / value objects.
     */
    private function atomic_page(): array {
        return [
            [
                'id'       => 'a1b2c3d',
                'elType'   => 'e-flexbox',
                'isInner'  => false,
                'settings' => [ 'classes' => [ '$$type' => 'classes', 'value' => [] ] ],
                'elements' => [
                    [
                        'id'       => 'b2c3d4e',
                        'elType'   => 'e-div-block',
                        'isInner'  => false,
                        'settings' => [],
                        'elements' => [
                            [
                                'id'         => 'c3d4e5f',
                                'elType'     => 'widget',
                                'widgetType' => 'e-heading',
                                'settings'   => [
                                    'tag'   => [ '$$type' => 'string', 'value' => 'h1' ],
                                    'title' => [ '$$type' => 'escaped-html', 'value' => 'Stuart Hale' ],
                                ],
                                'elements'   => [],
                            ],
                            [
                                'id'         => 'd4e5f6a',
                                'elType'     => 'widget',
                                'widgetType' => 'e-paragraph',
                                'settings'   => [
                                    'paragraph' => [ '$$type' => 'string', 'value' => 'Body copy.' ],
                                ],
                                'elements'   => [],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'       => 'e5f6a7b',
                'elType'   => 'e-div-block',
                'isInner'  => false,
                'settings' => [],
                'elements' => [],
            ],
        ];
    }

    public function test_atomic_page_passes_without_errors_or_warnings(): void {
        $result = rr_validate_elementor_data( $this->atomic_page() );
        $this->assertEmpty( $result['errors'] );
        $this->assertEmpty( $result['warnings'] );
        $this->assertEquals( 2, $result['widget_count'] );
    }

    public function test_atomic_page_round_trips_through_json(): void {
        $decoded = json_decode( wp_json_encode( $this->atomic_page() ), true );
        $result  = rr_validate_elementor_data( $decoded );
        $this->assertEmpty( $result['errors'] );
    }

    public function test_top_level_types_filter_extends_allowlist(): void {
        $node           = $this->section();
        $node['elType'] = 'e-custom-container';
        $this->assertNotEmpty( rr_validate_elementor_data( [ $node ] )['errors'] );

        $GLOBALS['_test_filters']['rrseo_elementor_top_level_types'][] = function ( $types ) {
            $types[] = 'e-custom-container';
            return $types;
        };
        try {
            $this->assertEmpty( rr_validate_elementor_data( [ $node ] )['errors'] );
        } finally {
            unset( $GLOBALS['_test_filters']['rrseo_elementor_top_level_types'] );
        }
    }

    public function test_error_message_lists_atomic_types(): void {
        $node           = $this->section();
        $node['elType'] = 'column';
        $result         = rr_validate_elementor_data( [ $node ] );
        $this->assertStringContainsString( 'e-flexbox', $result['errors'][0] );
    }

    // ------------------------------------------------------------------
    // Return shape
    // ------------------------------------------------------------------

    public function test_result_always_has_errors_warnings_widget_count_keys(): void {
        $result = rr_validate_elementor_data( [] );
        $this->assertArrayHasKey( 'errors', $result );
        $this->assertArrayHasKey( 'warnings', $result );
        $this->assertArrayHasKey( 'widget_count', $result );
    }
}
