<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for WAF-safe base64 snippet transport (issue #8, v3.3.0).
 *
 * Covers rr_decode_snippet_b64() -- strict base64 + UTF-8 validation -- and
 * the acceptance-criteria round trip: an onload=-bearing payload that WAFs
 * block in plain text decodes, stores, and emits verbatim. REST handlers
 * reuse the helper (create, bulk, update) and are exercised by the staging
 * validation pass.
 */
class SnippetBase64Test extends TestCase {

    private const ONLOAD_PAYLOAD = '<link rel="preload" as="style" href="https://cdn.example.com/blocking.css" onload="this.onload=null;this.rel=\'stylesheet\'">';

    protected function setUp(): void {
        $GLOBALS['_test_options']        = array();
        $GLOBALS['_test_fired_actions']  = array();
        $GLOBALS['_test_user_logged_in'] = false;
    }

    // ── rr_decode_snippet_b64() ───────────────────────────────────────────────

    public function test_decodes_valid_base64_containing_event_handler(): void {
        $decoded = rr_decode_snippet_b64( base64_encode( self::ONLOAD_PAYLOAD ) );

        $this->assertSame( self::ONLOAD_PAYLOAD, $decoded );
        $this->assertStringContainsString( 'onload=', $decoded );
    }

    public function test_decodes_multibyte_utf8_content(): void {
        $payload = '<script>console.log("Grüße — ¯\\_(ツ)_/¯");</script>';

        $this->assertSame( $payload, rr_decode_snippet_b64( base64_encode( $payload ) ) );
    }

    public function test_rejects_non_base64_alphabet(): void {
        $this->assertNull( rr_decode_snippet_b64( 'not base64!!!' ) );
    }

    public function test_rejects_base64_of_invalid_utf8(): void {
        $this->assertNull( rr_decode_snippet_b64( base64_encode( "\xFF\xFE\xFA" ) ) );
    }

    public function test_rejects_empty_and_non_string_values(): void {
        $this->assertNull( rr_decode_snippet_b64( '' ) );
        $this->assertNull( rr_decode_snippet_b64( null ) );
        $this->assertNull( rr_decode_snippet_b64( 42 ) );
        $this->assertNull( rr_decode_snippet_b64( array( 'x' ) ) );
        // base64 of the empty string decodes to '' -- not a usable body.
        $this->assertNull( rr_decode_snippet_b64( base64_encode( '' ) ) );
    }

    // ── Round trip: decoded body emits verbatim ───────────────────────────────

    public function test_decoded_onload_payload_emits_verbatim_at_priority_one(): void {
        $body = rr_decode_snippet_b64( base64_encode( self::ONLOAD_PAYLOAD ) );

        $GLOBALS['_test_options'][ RMB_SNIPPETS_KEY ]['async-css'] = array(
            'id'         => 'async-css',
            'title'      => 'Async external CSS (perf)',
            'content'    => $body,
            'location'   => 'head',
            'display_on' => 'entire_website',
            'status'     => 'active',
            'priority'   => 1,
        );

        ob_start();
        rmb_output_snippets( 'head', 1 );
        $output = (string) ob_get_clean();

        // The stored/emitted HTML carries the raw event handler -- base64 is
        // transport-only and never reaches storage or the page.
        $this->assertStringContainsString( self::ONLOAD_PAYLOAD, $output );
        $this->assertStringNotContainsString( 'base64', $output );
    }
}
