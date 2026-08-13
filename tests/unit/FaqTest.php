<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the FAQ schema module (includes/class-rrseo-faq.php, issue #22
 * Stage 1).
 *
 * Covers the pure helpers (rr_schema_graph_nodes/merge_node/remove_node_type,
 * rr_validate_faq_items, rr_faq_build_node, rr_faq_extract_items) and the
 * pipeline functions (rr_faq_get/set/delete), which are decoupled from
 * WP_REST_Request and directly testable, matching the pattern established
 * for the redirects module. REST handlers (rmb_faq_*) are thin wrappers
 * and are not exercised directly here.
 */
class FaqTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_test_options']         = array();
        $GLOBALS['_test_post_meta']       = array();
        $GLOBALS['_test_posts']           = array();
        $GLOBALS['_test_registered_meta'] = array();
    }

    private function make_post( int $id ): WP_Post {
        $post     = new WP_Post();
        $post->ID = $id;

        $GLOBALS['_test_posts'][ $id ] = $post;
        return $post;
    }

    // ── rr_schema_graph_nodes() ─────────────────────────────────────────────────

    public function test_graph_nodes_empty_for_non_array(): void {
        $this->assertSame( array(), rr_schema_graph_nodes( null ) );
        $this->assertSame( array(), rr_schema_graph_nodes( '' ) );
    }

    public function test_graph_nodes_single_node(): void {
        $node = array( '@type' => 'LocalBusiness' );
        $this->assertSame( array( $node ), rr_schema_graph_nodes( $node ) );
    }

    public function test_graph_nodes_graph_envelope(): void {
        $graph = array(
            '@graph' => array(
                array( '@type' => 'LocalBusiness' ),
                array( '@type' => 'Service' ),
            ),
        );
        $this->assertCount( 2, rr_schema_graph_nodes( $graph ) );
    }

    public function test_graph_nodes_bare_array(): void {
        $graph = array(
            array( '@type' => 'LocalBusiness' ),
            array( '@type' => 'Service' ),
        );
        $this->assertCount( 2, rr_schema_graph_nodes( $graph ) );
    }

    // ── rr_schema_merge_node() ──────────────────────────────────────────────────

    public function test_merge_node_preserves_other_nodes(): void {
        $existing = array(
            '@graph' => array(
                array( '@type' => 'LocalBusiness', 'name' => 'Acme' ),
            ),
        );
        $new_node = array( '@type' => 'FAQPage', 'mainEntity' => array() );

        $merged = rr_schema_merge_node( $existing, $new_node, 'FAQPage' );

        $this->assertCount( 2, $merged['@graph'] );
        $this->assertSame( 'https://schema.org', $merged['@context'] );
    }

    public function test_merge_node_replaces_same_type_idempotently(): void {
        $existing = array(
            '@graph' => array(
                array( '@type' => 'LocalBusiness' ),
                array( '@type' => 'FAQPage', 'mainEntity' => array( 'old' ) ),
            ),
        );
        $new_node = array( '@type' => 'FAQPage', 'mainEntity' => array( 'new' ) );

        $merged = rr_schema_merge_node( $existing, $new_node, 'FAQPage' );

        $this->assertCount( 2, $merged['@graph'] );
        $faq = array_values( array_filter( $merged['@graph'], fn( $n ) => 'FAQPage' === $n['@type'] ) );
        $this->assertSame( array( 'new' ), $faq[0]['mainEntity'] );
    }

    public function test_merge_node_onto_empty_graph(): void {
        $merged = rr_schema_merge_node( null, array( '@type' => 'FAQPage' ), 'FAQPage' );
        $this->assertCount( 1, $merged['@graph'] );
    }

    // ── rr_schema_remove_node_type() ────────────────────────────────────────────

    public function test_remove_node_type_leaves_others(): void {
        $existing = array(
            '@graph' => array(
                array( '@type' => 'LocalBusiness' ),
                array( '@type' => 'FAQPage' ),
            ),
        );
        $result = rr_schema_remove_node_type( $existing, 'FAQPage' );

        $this->assertCount( 1, $result['@graph'] );
        $this->assertSame( 'LocalBusiness', $result['@graph'][0]['@type'] );
    }

    public function test_remove_node_type_returns_null_when_nothing_remains(): void {
        $existing = array( '@graph' => array( array( '@type' => 'FAQPage' ) ) );
        $this->assertNull( rr_schema_remove_node_type( $existing, 'FAQPage' ) );
    }

    // ── rr_validate_faq_items() ─────────────────────────────────────────────────

    public function test_validate_faq_items_rejects_empty(): void {
        $v = rr_validate_faq_items( array() );
        $this->assertNotEmpty( $v['errors'] );
    }

    public function test_validate_faq_items_rejects_missing_question_or_answer(): void {
        $v = rr_validate_faq_items( array( array( 'question' => 'What?' ) ) );
        $this->assertNotEmpty( $v['errors'] );

        $v2 = rr_validate_faq_items( array( array( 'answer' => 'Because.' ) ) );
        $this->assertNotEmpty( $v2['errors'] );
    }

    public function test_validate_faq_items_accepts_valid_items(): void {
        $v = rr_validate_faq_items(
            array(
                array( 'question' => 'What credit score do I need?', 'answer' => '580 minimum.' ),
            )
        );
        $this->assertSame( array(), $v['errors'] );
        $this->assertCount( 1, $v['normalized'] );
        $this->assertSame( 'What credit score do I need?', $v['normalized'][0]['question'] );
    }

    public function test_validate_faq_items_warns_on_missing_question_mark(): void {
        $v = rr_validate_faq_items(
            array( array( 'question' => 'Tell me about rates', 'answer' => 'They vary.' ) )
        );
        $this->assertSame( array(), $v['errors'] );
        $this->assertNotEmpty( $v['warnings'] );
    }

    public function test_validate_faq_items_warns_on_length_caps(): void {
        $v = rr_validate_faq_items(
            array(
                array(
                    'question' => str_repeat( 'a', RR_FAQ_QUESTION_MAX + 1 ) . '?',
                    'answer'   => 'short',
                ),
            )
        );
        $this->assertSame( array(), $v['errors'] );
        $this->assertStringContainsString( 'question exceeds', implode( ' ', $v['warnings'] ) );
    }

    public function test_validate_faq_items_answer_is_kses_sanitized(): void {
        $v = rr_validate_faq_items(
            array( array( 'question' => 'OK?', 'answer' => '<script>alert(1)</script>Safe text' ) )
        );
        $this->assertSame( array(), $v['errors'] );
        $this->assertStringNotContainsString( '<script>', $v['normalized'][0]['answer'] );
    }

    // ── rr_faq_build_node() / rr_faq_extract_items() round trip ────────────────

    public function test_build_node_and_extract_items_round_trip(): void {
        $items = array(
            array( 'question' => 'What credit score do I need?', 'answer' => '580 minimum.' ),
            array( 'question' => 'How long does pre-approval take?', 'answer' => 'A few minutes.' ),
        );
        $node = rr_faq_build_node( 'https://example.test/page/', $items );

        $this->assertSame( 'FAQPage', $node['@type'] );
        $this->assertSame( 'https://example.test/page/#faq', $node['@id'] );
        $this->assertCount( 2, $node['mainEntity'] );

        $extracted = rr_faq_extract_items( $node );
        $this->assertSame( $items, $extracted );
    }

    public function test_extract_items_returns_empty_for_null(): void {
        $this->assertSame( array(), rr_faq_extract_items( null ) );
    }

    // ── Pipeline: rr_faq_get/set/delete ─────────────────────────────────────────

    public function test_faq_set_then_get_round_trip(): void {
        $this->make_post( 5 );
        $result = rr_faq_set( 5, array( array( 'question' => 'OK?', 'answer' => 'Yes.' ) ) );

        $this->assertSame( 'saved', $result['status'] );

        $node = rr_faq_get( 5 );
        $this->assertSame( 'FAQPage', $node['@type'] );
        $this->assertSame(
            array( array( 'question' => 'OK?', 'answer' => 'Yes.' ) ),
            rr_faq_extract_items( $node )
        );
    }

    public function test_faq_set_preserves_existing_schema_nodes(): void {
        $this->make_post( 6 );
        $GLOBALS['_test_post_meta'][6][ RR_SCHEMA_META_KEY ] = array( '@type' => 'LocalBusiness', 'name' => 'Acme' );

        rr_faq_set( 6, array( array( 'question' => 'OK?', 'answer' => 'Yes.' ) ) );

        $stored = $GLOBALS['_test_post_meta'][6][ RR_SCHEMA_META_KEY ];
        $this->assertCount( 2, $stored['@graph'] );
    }

    public function test_faq_set_invalid_does_not_write(): void {
        $this->make_post( 7 );
        $result = rr_faq_set( 7, array() );

        $this->assertSame( 'invalid', $result['status'] );
        $this->assertArrayNotHasKey( RR_SCHEMA_META_KEY, $GLOBALS['_test_post_meta'][7] ?? array() );
    }

    public function test_faq_set_dry_run_does_not_persist(): void {
        $this->make_post( 8 );
        $result = rr_faq_set( 8, array( array( 'question' => 'OK?', 'answer' => 'Yes.' ) ), true );

        $this->assertSame( 'simulated', $result['status'] );
        $this->assertNull( rr_faq_get( 8 ) );
    }

    public function test_faq_delete_removes_faq_only(): void {
        $this->make_post( 9 );
        rr_faq_set( 9, array( array( 'question' => 'OK?', 'answer' => 'Yes.' ) ) );
        $GLOBALS['_test_post_meta'][9][ RR_SCHEMA_META_KEY ]['@graph'][] = array( '@type' => 'LocalBusiness' );

        $result = rr_faq_delete( 9 );

        $this->assertSame( 'deleted', $result['status'] );
        $this->assertNull( rr_faq_get( 9 ) );
        $this->assertCount( 1, $GLOBALS['_test_post_meta'][9][ RR_SCHEMA_META_KEY ]['@graph'] );
    }

    public function test_faq_delete_clears_meta_when_faq_was_only_node(): void {
        $this->make_post( 10 );
        rr_faq_set( 10, array( array( 'question' => 'OK?', 'answer' => 'Yes.' ) ) );

        rr_faq_delete( 10 );

        $this->assertArrayNotHasKey( RR_SCHEMA_META_KEY, $GLOBALS['_test_post_meta'][10] );
    }

    public function test_faq_delete_not_found(): void {
        $this->make_post( 11 );
        $result = rr_faq_delete( 11 );
        $this->assertSame( 'not_found', $result['status'] );
    }
}
