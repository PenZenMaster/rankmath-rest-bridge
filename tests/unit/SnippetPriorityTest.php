<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for snippet emission priority (issue #7, v3.2.0).
 *
 * Covers rr_validate_snippet_priority(), rmb_snippet_priority() location
 * defaults, rmb_register_snippet_emitters() bucket registration (one
 * add_action per distinct (hook, priority) pair, defaults always present),
 * and rmb_output_snippets() bucket filtering -- a priority-1 head snippet
 * emits only from the priority-1 emitter, which WordPress fires before the
 * theme's wp_head:5-10 stylesheet output. REST write handlers reuse
 * rr_validate_snippet_priority() and are exercised by the staging pass.
 */
class SnippetPriorityTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_test_options']       = array();
        $GLOBALS['_test_actions']       = array();
        $GLOBALS['_test_fired_actions'] = array();
        $GLOBALS['_test_user_logged_in'] = false;
    }

    private function seed_snippet( string $id, array $overrides = array() ): void {
        $GLOBALS['_test_options'][ RMB_SNIPPETS_KEY ][ $id ] = array_merge(
            array(
                'id'         => $id,
                'title'      => $id,
                'content'    => "<!-- body:{$id} -->",
                'location'   => 'head',
                'display_on' => 'entire_website',
                'status'     => 'active',
            ),
            $overrides
        );
    }

    private function render( string $location, ?int $priority = null ): string {
        ob_start();
        rmb_output_snippets( $location, $priority );
        return (string) ob_get_clean();
    }

    // ── rr_validate_snippet_priority() ────────────────────────────────────────

    public function test_priority_validation_accepts_integers_in_range(): void {
        $this->assertSame( 0, rr_validate_snippet_priority( 0 ) );
        $this->assertSame( 1, rr_validate_snippet_priority( 1 ) );
        $this->assertSame( 20, rr_validate_snippet_priority( '20' ) );
        $this->assertSame( 10000, rr_validate_snippet_priority( 10000 ) );
    }

    public function test_priority_validation_rejects_out_of_range_and_non_integers(): void {
        $this->assertNull( rr_validate_snippet_priority( -1 ) );
        $this->assertNull( rr_validate_snippet_priority( 10001 ) );
        $this->assertNull( rr_validate_snippet_priority( '-5' ) );
        $this->assertNull( rr_validate_snippet_priority( '1.5' ) );
        $this->assertNull( rr_validate_snippet_priority( 2.5 ) );
        $this->assertNull( rr_validate_snippet_priority( 'first' ) );
        $this->assertNull( rr_validate_snippet_priority( true ) );
        $this->assertNull( rr_validate_snippet_priority( array( 1 ) ) );
    }

    // ── rmb_snippet_priority() ────────────────────────────────────────────────

    public function test_effective_priority_uses_stored_value_or_location_default(): void {
        $this->assertSame( 1, rmb_snippet_priority( array( 'priority' => 1 ), 'head' ) );
        $this->assertSame( 20, rmb_snippet_priority( array(), 'head' ) );
        $this->assertSame( 10, rmb_snippet_priority( array(), 'body_open' ) );
        $this->assertSame( 10, rmb_snippet_priority( array(), 'footer' ) );
    }

    // ── rmb_register_snippet_emitters() ───────────────────────────────────────

    public function test_registrar_always_registers_the_three_location_defaults(): void {
        rmb_register_snippet_emitters();

        $this->assertSame( array( 20 ), array_column( $GLOBALS['_test_actions']['wp_head'], 'priority' ) );
        $this->assertSame( array( 10 ), array_column( $GLOBALS['_test_actions']['wp_body_open'], 'priority' ) );
        $this->assertSame( array( 10 ), array_column( $GLOBALS['_test_actions']['wp_footer'], 'priority' ) );
    }

    public function test_registrar_adds_one_emitter_per_custom_priority_bucket(): void {
        $this->seed_snippet( 'preload-a', array( 'priority' => 1 ) );
        $this->seed_snippet( 'preload-b', array( 'priority' => 1 ) );
        $this->seed_snippet( 'late-js', array(
            'location' => 'footer',
            'priority' => 100,
        ) );

        rmb_register_snippet_emitters();

        // Two head buckets (1 + default 20) -- the duplicate priority-1
        // snippets share one emitter. Footer gains a 100 bucket.
        $this->assertSame( array( 20, 1 ), array_column( $GLOBALS['_test_actions']['wp_head'], 'priority' ) );
        $this->assertSame( array( 10, 100 ), array_column( $GLOBALS['_test_actions']['wp_footer'], 'priority' ) );
    }

    public function test_priority_one_emitter_registers_before_a_theme_priority_ten_fixture(): void {
        $this->seed_snippet( 'critical-css', array( 'priority' => 1 ) );
        add_action(
            'wp_head',
            function () {
                echo '<link rel="stylesheet" href="theme.css">';
            },
            10
        );

        rmb_register_snippet_emitters();

        $priorities = array_column( $GLOBALS['_test_actions']['wp_head'], 'priority' );
        $this->assertContains( 1, $priorities );
        // WordPress fires lower priorities first: snippet bucket 1 beats the
        // theme fixture at 10.
        $this->assertLessThan( 10, min( $priorities ) );
    }

    // ── rmb_output_snippets() bucket filtering ────────────────────────────────

    public function test_each_bucket_emits_only_its_own_snippets(): void {
        $this->seed_snippet( 'early', array( 'priority' => 1 ) );
        $this->seed_snippet( 'legacy' ); // no priority -> default bucket 20

        $early_bucket = $this->render( 'head', 1 );
        $this->assertStringContainsString( 'body:early', $early_bucket );
        $this->assertStringNotContainsString( 'body:legacy', $early_bucket );

        $default_bucket = $this->render( 'head', 20 );
        $this->assertStringContainsString( 'body:legacy', $default_bucket );
        $this->assertStringNotContainsString( 'body:early', $default_bucket );
    }

    public function test_null_priority_means_the_location_default_bucket(): void {
        $this->seed_snippet( 'early', array( 'priority' => 1 ) );
        $this->seed_snippet( 'legacy' );

        $output = $this->render( 'head' );

        $this->assertStringContainsString( 'body:legacy', $output );
        $this->assertStringNotContainsString( 'body:early', $output );
    }

    public function test_snippet_with_explicit_default_priority_emits_in_default_bucket(): void {
        $this->seed_snippet( 'explicit-twenty', array( 'priority' => 20 ) );

        $output = $this->render( 'head' );

        $this->assertStringContainsString( 'body:explicit-twenty', $output );
    }
}
