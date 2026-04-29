<?php

use PHPUnit\Framework\TestCase;

/**
 * Validates that update-manifest.json satisfies the Plugin Update Checker (PUC)
 * contract and the REST /self-update handler requirements.
 *
 * These tests act as a regression guard: if a future edit breaks the manifest
 * format, the test suite catches it before the change is pushed to GitHub.
 */
class ManifestTest extends TestCase {

    private static array $manifest;

    public static function setUpBeforeClass(): void {
        $path = dirname( __DIR__, 2 ) . '/update-manifest.json';
        self::assertFileExists( $path, 'update-manifest.json must exist at repo root.' );

        $raw = file_get_contents( $path );
        self::assertNotFalse( $raw, 'Failed to read update-manifest.json.' );

        $data = json_decode( $raw, true );
        self::assertNotNull( $data, 'update-manifest.json must be valid JSON.' );
        self::$manifest = $data;
    }

    // ------------------------------------------------------------------
    // PUC required fields (validateMetadata checks name + version)
    // ------------------------------------------------------------------

    public function test_manifest_has_name(): void {
        $this->assertArrayHasKey( 'name', self::$manifest );
        $this->assertNotEmpty( self::$manifest['name'] );
    }

    public function test_manifest_has_version(): void {
        $this->assertArrayHasKey( 'version', self::$manifest );
        $this->assertNotEmpty( self::$manifest['version'] );
    }

    public function test_manifest_version_matches_plugin_constant(): void {
        $this->assertSame( RMB_VERSION, self::$manifest['version'] );
    }

    // ------------------------------------------------------------------
    // PUC + REST handler field: download_url (not zip_url)
    // ------------------------------------------------------------------

    public function test_manifest_has_download_url_not_zip_url(): void {
        $this->assertArrayHasKey( 'download_url', self::$manifest,
            'PUC and /self-update both require "download_url"; "zip_url" is silently ignored.' );
        $this->assertArrayNotHasKey( 'zip_url', self::$manifest,
            '"zip_url" is a legacy wrong field name and must not be present.' );
    }

    public function test_download_url_is_a_valid_url(): void {
        $url = self::$manifest['download_url'] ?? '';
        $this->assertNotEmpty( $url );
        $this->assertNotFalse(
            filter_var( $url, FILTER_VALIDATE_URL ),
            "download_url must be a valid URL; got: {$url}"
        );
    }

    // ------------------------------------------------------------------
    // PUC recommended fields
    // ------------------------------------------------------------------

    public function test_manifest_has_slug(): void {
        $this->assertArrayHasKey( 'slug', self::$manifest );
        $this->assertNotEmpty( self::$manifest['slug'] );
    }

    public function test_manifest_has_sections_with_description(): void {
        $this->assertArrayHasKey( 'sections', self::$manifest );
        $this->assertIsArray( self::$manifest['sections'] );
        $this->assertArrayHasKey( 'description', self::$manifest['sections'] );
        $this->assertNotEmpty( self::$manifest['sections']['description'] );
    }

    // ------------------------------------------------------------------
    // Zip path sanity: download_url must include the correct version folder
    // ------------------------------------------------------------------

    public function test_download_url_references_current_version(): void {
        $url     = self::$manifest['download_url'] ?? '';
        $version = self::$manifest['version']      ?? '';
        $this->assertStringContainsString(
            "v{$version}",
            $url,
            "download_url should reference the current version folder v{$version}."
        );
    }
}
