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
}
