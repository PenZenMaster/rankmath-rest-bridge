<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the schema-hygiene module (includes/class-rrseo-schema-hygiene.php,
 * issue #23) and rr_validate_schema_strip_third_party() (rankmath-rest-bridge.php).
 *
 * Covers the pure helpers only. The wp_head buffer hooks
 * (rrseo_schema_hygiene_buffer_start/end) require a live WP environment and
 * are not exercised here, matching this codebase's existing convention for
 * hook-registered WordPress-bound functions.
 */
class SchemaHygieneTest extends TestCase {

    // ── rr_validate_schema_strip_third_party() ────────────────────────────────

    public function test_strip_third_party_rejects_non_array(): void {
        $v = rr_validate_schema_strip_third_party( 'Product' );
        $this->assertNotEmpty( $v['errors'] );
    }

    public function test_strip_third_party_rejects_non_string_entry(): void {
        $v = rr_validate_schema_strip_third_party( [ 'Product', 42 ] );
        $this->assertNotEmpty( $v['errors'] );
    }

    public function test_strip_third_party_rejects_empty_string_entry(): void {
        $v = rr_validate_schema_strip_third_party( [ 'Product', '' ] );
        $this->assertNotEmpty( $v['errors'] );
    }

    public function test_strip_third_party_accepts_and_dedupes(): void {
        $v = rr_validate_schema_strip_third_party( [ 'Product', 'Service', 'Product' ] );
        $this->assertSame( [], $v['errors'] );
        $this->assertSame( [ 'Product', 'Service' ], $v['normalized'] );
    }

    public function test_strip_third_party_accepts_empty_array(): void {
        $v = rr_validate_schema_strip_third_party( [] );
        $this->assertSame( [], $v['errors'] );
        $this->assertSame( [], $v['normalized'] );
    }

    // ── rr_schema_hygiene_extract_json_types() ─────────────────────────────────

    public function test_extract_json_types_non_array_is_empty(): void {
        $this->assertSame( [], rr_schema_hygiene_extract_json_types( null ) );
        $this->assertSame( [], rr_schema_hygiene_extract_json_types( 'x' ) );
    }

    public function test_extract_json_types_single_node(): void {
        $this->assertSame(
            [ 'Product' ],
            rr_schema_hygiene_extract_json_types( [ '@type' => 'Product' ] )
        );
    }

    public function test_extract_json_types_graph_envelope(): void {
        $decoded = [
            '@graph' => [
                [ '@type' => 'Product' ],
                [ '@type' => 'Offer' ],
            ],
        ];
        $this->assertSame( [ 'Product', 'Offer' ], rr_schema_hygiene_extract_json_types( $decoded ) );
    }

    public function test_extract_json_types_bare_array_of_nodes(): void {
        $decoded = [
            [ '@type' => 'Product' ],
            [ '@type' => 'AggregateRating' ],
        ];
        $this->assertSame( [ 'Product', 'AggregateRating' ], rr_schema_hygiene_extract_json_types( $decoded ) );
    }

    // ── rr_schema_hygiene_strip_html() ──────────────────────────────────────────

    private function ldjson( array $data ): string {
        return '<script type="application/ld+json">' . json_encode( $data ) . '</script>';
    }

    public function test_strip_html_removes_matching_third_party_block(): void {
        $html = '<head>' . $this->ldjson( [ '@type' => 'Product', 'name' => 'Stray' ] ) . '</head>';
        $out  = rr_schema_hygiene_strip_html( $html, [ 'Product' ] );

        $this->assertStringNotContainsString( 'Stray', $out );
    }

    public function test_strip_html_keeps_non_matching_block(): void {
        $html = '<head>' . $this->ldjson( [ '@type' => 'FAQPage', 'name' => 'Keep me' ] ) . '</head>';
        $out  = rr_schema_hygiene_strip_html( $html, [ 'Product' ] );

        $this->assertStringContainsString( 'Keep me', $out );
    }

    public function test_strip_html_never_touches_plugin_marked_block(): void {
        $plugin_block = RR_SCHEMA_HYGIENE_MARKER_START . $this->ldjson( [ '@type' => 'Product' ] ) . RR_SCHEMA_HYGIENE_MARKER_END;
        $html         = '<head>' . $plugin_block . '</head>';

        $out = rr_schema_hygiene_strip_html( $html, [ 'Product' ] );

        $this->assertStringContainsString( $plugin_block, $out );
    }

    public function test_strip_html_strips_third_party_but_keeps_plugin_block_same_type(): void {
        $plugin_block = RR_SCHEMA_HYGIENE_MARKER_START . $this->ldjson( [ '@type' => 'Product', 'name' => 'Ours' ] ) . RR_SCHEMA_HYGIENE_MARKER_END;
        $stray_block  = $this->ldjson( [ '@type' => 'Product', 'name' => 'Theirs' ] );
        $html         = '<head>' . $plugin_block . $stray_block . '</head>';

        $out = rr_schema_hygiene_strip_html( $html, [ 'Product' ] );

        $this->assertStringContainsString( 'Ours', $out );
        $this->assertStringNotContainsString( 'Theirs', $out );
    }

    public function test_strip_html_whole_block_removed_when_graph_mixes_types(): void {
        $html = '<head>' . $this->ldjson(
            [
                '@graph' => [
                    [ '@type' => 'Product', 'name' => 'Strip me' ],
                    [ '@type' => 'FAQPage', 'name' => 'Also removed -- whole block only' ],
                ],
            ]
        ) . '</head>';

        $out = rr_schema_hygiene_strip_html( $html, [ 'Product' ] );

        $this->assertStringNotContainsString( 'Strip me', $out );
        $this->assertStringNotContainsString( 'Also removed', $out );
    }

    public function test_strip_html_noop_when_strip_types_empty(): void {
        $html = '<head>' . $this->ldjson( [ '@type' => 'Product' ] ) . '</head>';
        $this->assertSame( $html, rr_schema_hygiene_strip_html( $html, [] ) );
    }

    public function test_strip_html_noop_when_no_script_tags(): void {
        $html = '<head><title>No schema here</title></head>';
        $this->assertSame( $html, rr_schema_hygiene_strip_html( $html, [ 'Product' ] ) );
    }

    public function test_strip_html_ignores_malformed_json(): void {
        $html = '<head><script type="application/ld+json">{not valid json</script></head>';
        $this->assertSame( $html, rr_schema_hygiene_strip_html( $html, [ 'Product' ] ) );
    }

    public function test_strip_html_handles_multiple_stray_blocks(): void {
        $html = '<head>'
            . $this->ldjson( [ '@type' => 'Product', 'name' => 'First stray' ] )
            . $this->ldjson( [ '@type' => 'FAQPage', 'name' => 'Kept' ] )
            . $this->ldjson( [ '@type' => 'Product', 'name' => 'Second stray' ] )
            . '</head>';

        $out = rr_schema_hygiene_strip_html( $html, [ 'Product' ] );

        $this->assertStringNotContainsString( 'First stray', $out );
        $this->assertStringNotContainsString( 'Second stray', $out );
        $this->assertStringContainsString( 'Kept', $out );
    }
}
