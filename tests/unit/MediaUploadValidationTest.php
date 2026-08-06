<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for rr_validate_media_fields() and rr_validate_media_file().
 *
 * Both have no WP dependencies beyond the RR_MEDIA_ALLOWED_SOURCES /
 * RR_MEDIA_ALLOWED_MIME_TYPES / RR_MEDIA_MAX_BYTES constants.
 */
class MediaUploadValidationTest extends TestCase {

    // ------------------------------------------------------------------
    // rr_validate_media_fields()
    // ------------------------------------------------------------------

    public function test_valid_fields_pass(): void {
        $result = rr_validate_media_fields( 'Downtown Peru, IL', 'client_supplied', false );
        $this->assertNull( $result['error'] );
        $this->assertNull( $result['code'] );
    }

    public function test_empty_alt_text_returns_error(): void {
        $result = rr_validate_media_fields( '', 'client_supplied', false );
        $this->assertEquals( 'invalid_alt_text', $result['code'] );
    }

    public function test_whitespace_only_alt_text_returns_error(): void {
        $result = rr_validate_media_fields( '   ', 'client_supplied', false );
        $this->assertEquals( 'invalid_alt_text', $result['code'] );
    }

    public function test_unknown_source_returns_error(): void {
        $result = rr_validate_media_fields( 'Alt text', 'downloaded_from_google', false );
        $this->assertEquals( 'invalid_source', $result['code'] );
        $this->assertStringContainsString( 'not allowed', $result['error'] );
    }

    public function test_all_allowed_sources_pass(): void {
        foreach ( RR_MEDIA_ALLOWED_SOURCES as $source ) {
            $result = rr_validate_media_fields( 'Alt text', $source, false );
            $this->assertNull( $result['error'], "Expected source '{$source}' to be allowed." );
        }
    }

    public function test_placeholder_true_with_non_placeholder_source_returns_error(): void {
        $result = rr_validate_media_fields( 'Alt text', 'client_supplied', true );
        $this->assertEquals( 'invalid_source', $result['code'] );
        $this->assertStringContainsString( 'ai_placeholder', $result['error'] );
    }

    public function test_placeholder_true_with_ai_placeholder_source_passes(): void {
        $result = rr_validate_media_fields( 'Alt text', 'ai_placeholder', true );
        $this->assertNull( $result['error'] );
    }

    public function test_placeholder_false_with_any_source_passes(): void {
        $result = rr_validate_media_fields( 'Alt text', 'stock', false );
        $this->assertNull( $result['error'] );
    }

    public function test_alt_text_checked_before_source(): void {
        // Both fields are invalid; alt_text should surface first.
        $result = rr_validate_media_fields( '', 'not_a_real_source', false );
        $this->assertEquals( 'invalid_alt_text', $result['code'] );
    }

    // ------------------------------------------------------------------
    // rr_validate_media_file()
    // ------------------------------------------------------------------

    public function test_valid_file_passes(): void {
        $result = rr_validate_media_file( 1024 * 1024, 'image/webp' );
        $this->assertNull( $result['error'] );
        $this->assertNull( $result['status'] );
    }

    public function test_all_allowed_mime_types_pass(): void {
        foreach ( RR_MEDIA_ALLOWED_MIME_TYPES as $mime ) {
            $result = rr_validate_media_file( 1024, $mime );
            $this->assertNull( $result['error'], "Expected mime '{$mime}' to be allowed." );
        }
    }

    public function test_oversized_file_returns_413(): void {
        $result = rr_validate_media_file( RR_MEDIA_MAX_BYTES + 1, 'image/jpeg' );
        $this->assertEquals( 'file_too_large', $result['code'] );
        $this->assertEquals( 413, $result['status'] );
    }

    public function test_file_at_exactly_max_bytes_passes(): void {
        $result = rr_validate_media_file( RR_MEDIA_MAX_BYTES, 'image/jpeg' );
        $this->assertNull( $result['error'] );
    }

    public function test_disallowed_mime_returns_415(): void {
        $result = rr_validate_media_file( 1024, 'image/svg+xml' );
        $this->assertEquals( 'invalid_mime_type', $result['code'] );
        $this->assertEquals( 415, $result['status'] );
    }

    public function test_empty_mime_returns_415_with_unknown_label(): void {
        $result = rr_validate_media_file( 1024, '' );
        $this->assertEquals( 'invalid_mime_type', $result['code'] );
        $this->assertStringContainsString( 'unknown', $result['error'] );
    }

    public function test_size_checked_before_mime(): void {
        // Both are invalid; size should surface first (matches handler order).
        $result = rr_validate_media_file( RR_MEDIA_MAX_BYTES + 1, 'image/svg+xml' );
        $this->assertEquals( 'file_too_large', $result['code'] );
    }
}
