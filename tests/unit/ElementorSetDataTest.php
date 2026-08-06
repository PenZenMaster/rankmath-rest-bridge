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
    // Return shape
    // ------------------------------------------------------------------

    public function test_result_always_has_errors_warnings_widget_count_keys(): void {
        $result = rr_validate_elementor_data( [] );
        $this->assertArrayHasKey( 'errors', $result );
        $this->assertArrayHasKey( 'warnings', $result );
        $this->assertArrayHasKey( 'widget_count', $result );
    }
}
