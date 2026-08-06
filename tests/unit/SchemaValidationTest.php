<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for rr_validate_schema().
 *
 * rr_validate_schema() has no WP dependencies beyond apply_filters(),
 * which is stubbed in bootstrap.php to return its second argument unchanged.
 */
class SchemaValidationTest extends TestCase {

    // ------------------------------------------------------------------
    // JSON string input
    // ------------------------------------------------------------------

    public function test_valid_json_string_is_parsed_and_accepted(): void {
        $schema = json_encode( [
            '@context' => 'https://schema.org',
            '@type'    => 'Article',
            'name'     => 'Test Article',
        ] );

        $result = rr_validate_schema( $schema );
        $this->assertEmpty( $result['errors'] );
        $this->assertIsArray( $result['schema'] );
        $this->assertEquals( 'Article', $result['schema']['@type'] );
    }

    public function test_invalid_json_string_returns_error(): void {
        $result = rr_validate_schema( '{not valid json' );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'invalid JSON', $result['errors'][0] );
        $this->assertNull( $result['schema'] );
    }

    // ------------------------------------------------------------------
    // Array input
    // ------------------------------------------------------------------

    public function test_valid_array_with_allowed_type_passes(): void {
        $result = rr_validate_schema( [
            '@context' => 'https://schema.org',
            '@type'    => 'BlogPosting',
        ] );
        $this->assertEmpty( $result['errors'] );
        $this->assertNotNull( $result['schema'] );
    }

    public function test_non_array_non_string_returns_error(): void {
        $result = rr_validate_schema( 42 );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'must be a JSON object', $result['errors'][0] );
    }

    // ------------------------------------------------------------------
    // Required fields
    // ------------------------------------------------------------------

    public function test_missing_context_returns_error(): void {
        $result = rr_validate_schema( [ '@type' => 'Article' ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( '@context', $result['errors'][0] );
    }

    public function test_missing_type_returns_error(): void {
        $result = rr_validate_schema( [ '@context' => 'https://schema.org' ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( '@type', $result['errors'][0] );
    }

    public function test_missing_both_required_fields_returns_two_errors(): void {
        $result = rr_validate_schema( [] );
        $this->assertCount( 2, $result['errors'] );
    }

    // ------------------------------------------------------------------
    // @type allowlist
    // ------------------------------------------------------------------

    public function test_allowed_type_passes(): void {
        foreach ( RR_ALLOWED_SCHEMA_TYPES as $type ) {
            $result = rr_validate_schema( [
                '@context' => 'https://schema.org',
                '@type'    => $type,
            ] );
            $this->assertEmpty( $result['errors'], "Expected '{$type}' to be allowed." );
        }
    }

    public function test_unknown_type_returns_error(): void {
        $result = rr_validate_schema( [
            '@context' => 'https://schema.org',
            '@type'    => 'UnknownCustomType',
        ] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'not allowed', $result['errors'][0] );
    }

    // ------------------------------------------------------------------
    // Return shape
    // ------------------------------------------------------------------

    public function test_result_always_has_errors_warnings_schema_keys(): void {
        $result = rr_validate_schema( [] );
        $this->assertArrayHasKey( 'errors',   $result );
        $this->assertArrayHasKey( 'warnings', $result );
        $this->assertArrayHasKey( 'schema',   $result );
    }

    // ------------------------------------------------------------------
    // Multi-node input (issue #13): bare array and @graph envelope
    // ------------------------------------------------------------------

    public function test_bare_array_of_nodes_normalizes_to_graph_envelope(): void {
        $result = rr_validate_schema( [
            [ '@type' => 'Service', 'name' => 'Bookkeeping' ],
            [ '@type' => 'BreadcrumbList' ],
        ] );

        $this->assertEmpty( $result['errors'] );
        $this->assertEquals( 'https://schema.org', $result['schema']['@context'] );
        $this->assertCount( 2, $result['schema']['@graph'] );
        $this->assertEquals( 'Service', $result['schema']['@graph'][0]['@type'] );
        $this->assertEquals( 'BreadcrumbList', $result['schema']['@graph'][1]['@type'] );
    }

    public function test_graph_envelope_with_context_passes_through(): void {
        $result = rr_validate_schema( [
            '@context' => 'https://schema.org',
            '@graph'   => [
                [ '@type' => 'Service' ],
                [ '@type' => 'FAQPage' ],
            ],
        ] );

        $this->assertEmpty( $result['errors'] );
        $this->assertEquals( 'https://schema.org', $result['schema']['@context'] );
        $this->assertCount( 2, $result['schema']['@graph'] );
    }

    public function test_graph_envelope_missing_context_defaults_to_schema_org(): void {
        $result = rr_validate_schema( [
            '@graph' => [ [ '@type' => 'Service' ] ],
        ] );

        $this->assertEmpty( $result['errors'] );
        $this->assertEquals( 'https://schema.org', $result['schema']['@context'] );
    }

    public function test_empty_graph_array_returns_error(): void {
        $result = rr_validate_schema( [
            '@context' => 'https://schema.org',
            '@graph'   => [],
        ] );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'at least one node', $result['errors'][0] );
        $this->assertNull( $result['schema'] );
    }

    public function test_top_level_empty_array_still_reports_missing_fields(): void {
        // Regression: an empty PHP array must not be mistaken for an empty
        // graph — it should fall through to single-node validation exactly
        // like it did before multi-node support existed.
        $result = rr_validate_schema( [] );
        $this->assertCount( 2, $result['errors'] );
        $this->assertStringContainsString( '@context', $result['errors'][0] );
        $this->assertStringContainsString( '@type', $result['errors'][1] );
    }

    public function test_graph_node_with_unknown_type_reports_index(): void {
        $result = rr_validate_schema( [
            [ '@type' => 'Service' ],
            [ '@type' => 'UnknownCustomType' ],
        ] );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( '@graph[1]', $result['errors'][0] );
        $this->assertStringContainsString( 'not allowed', $result['errors'][0] );
        $this->assertNull( $result['schema'] );
    }

    public function test_graph_node_missing_type_reports_index(): void {
        $result = rr_validate_schema( [
            [ '@type' => 'Service' ],
            [ 'name' => 'No type here' ],
        ] );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( '@graph[1]', $result['errors'][0] );
        $this->assertStringContainsString( 'missing required @type', $result['errors'][0] );
    }

    public function test_graph_node_non_object_reports_index(): void {
        $result = rr_validate_schema( [
            [ '@type' => 'Service' ],
            'not-an-object',
        ] );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( '@graph[1]', $result['errors'][0] );
        $this->assertStringContainsString( 'must be a JSON object', $result['errors'][0] );
    }

    public function test_graph_exceeding_max_nodes_returns_413_status(): void {
        $nodes = array_fill( 0, 21, [ '@type' => 'Service' ] );

        $result = rr_validate_schema( $nodes );

        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'exceeds max of 20 nodes', $result['errors'][0] );
        $this->assertArrayHasKey( 'status', $result );
        $this->assertEquals( 413, $result['status'] );
        $this->assertNull( $result['schema'] );
    }

    public function test_graph_at_exactly_max_nodes_passes(): void {
        $nodes = array_fill( 0, 20, [ '@type' => 'Service' ] );

        $result = rr_validate_schema( $nodes );

        $this->assertEmpty( $result['errors'] );
        $this->assertCount( 20, $result['schema']['@graph'] );
    }

    public function test_json_string_array_of_nodes_is_parsed_and_normalized(): void {
        $json = json_encode( [
            [ '@type' => 'Service' ],
            [ '@type' => 'BreadcrumbList' ],
        ] );

        $result = rr_validate_schema( $json );

        $this->assertEmpty( $result['errors'] );
        $this->assertCount( 2, $result['schema']['@graph'] );
    }
}
