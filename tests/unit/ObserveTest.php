<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the v3.0 Bite 1 observation helpers (includes/class-rrseo-observe.php).
 *
 * Covers the pure helpers only: rr_observe_parse_headings(),
 * rr_observe_build_heading_tree(), rr_observe_heading_warnings(),
 * rr_observe_extract_links(), rr_observe_extract_markdown_urls(),
 * rr_observe_normalize_compare_url(), and rr_observe_diff_url_sets().
 * REST handlers require a live WP environment and are exercised by the
 * staging validation pass.
 */
class ObserveTest extends TestCase {

	// ── rr_observe_parse_headings() ───────────────────────────────────────────

	public function test_parse_headings_returns_empty_for_empty_html(): void {
		$this->assertSame( array(), rr_observe_parse_headings( '' ) );
		$this->assertSame( array(), rr_observe_parse_headings( '<p>No headings here.</p>' ) );
	}

	public function test_parse_headings_extracts_levels_and_text(): void {
		$html = '<h1>Title</h1><p>x</p><h2 class="sub">Section <em>One</em></h2>';
		$flat = rr_observe_parse_headings( $html );

		$this->assertCount( 2, $flat );
		$this->assertSame( 1, $flat[0]['level'] );
		$this->assertSame( 'Title', $flat[0]['text'] );
		$this->assertSame( 2, $flat[1]['level'] );
		$this->assertSame( 'Section One', $flat[1]['text'] );
	}

	public function test_parse_headings_decodes_entities_and_collapses_whitespace(): void {
		$html = "<h2>Fish &amp; Chips\n\t Special</h2>";
		$flat = rr_observe_parse_headings( $html );

		$this->assertSame( 'Fish & Chips Special', $flat[0]['text'] );
	}

	public function test_parse_headings_ignores_mismatched_close_tag(): void {
		// h2 opened but closed as h3 — the regex backreference must not match.
		$flat = rr_observe_parse_headings( '<h2>Broken</h3><h4>Valid</h4>' );

		$this->assertCount( 1, $flat );
		$this->assertSame( 4, $flat[0]['level'] );
	}

	// ── rr_observe_build_heading_tree() ───────────────────────────────────────

	public function test_build_tree_nests_children_under_lower_levels(): void {
		$flat = array(
			array( 'level' => 1, 'text' => 'A' ),
			array( 'level' => 2, 'text' => 'B' ),
			array( 'level' => 3, 'text' => 'C' ),
			array( 'level' => 2, 'text' => 'D' ),
		);
		$tree = rr_observe_build_heading_tree( $flat );

		$this->assertCount( 1, $tree );
		$this->assertSame( 'h1', $tree[0]['tag'] );
		$this->assertCount( 2, $tree[0]['children'] );
		$this->assertSame( 'B', $tree[0]['children'][0]['text'] );
		$this->assertSame( 'C', $tree[0]['children'][0]['children'][0]['text'] );
		$this->assertSame( 'D', $tree[0]['children'][1]['text'] );
	}

	public function test_build_tree_handles_document_starting_below_h1(): void {
		$flat = array(
			array( 'level' => 3, 'text' => 'Orphan' ),
			array( 'level' => 2, 'text' => 'Higher' ),
		);
		$tree = rr_observe_build_heading_tree( $flat );

		// Both become roots: the h2 is not a child of the preceding h3.
		$this->assertCount( 2, $tree );
		$this->assertSame( 'h3', $tree[0]['tag'] );
		$this->assertSame( 'h2', $tree[1]['tag'] );
	}

	public function test_build_tree_sibling_h1s_are_both_roots(): void {
		$flat = array(
			array( 'level' => 1, 'text' => 'First' ),
			array( 'level' => 1, 'text' => 'Second' ),
		);
		$tree = rr_observe_build_heading_tree( $flat );

		$this->assertCount( 2, $tree );
		$this->assertSame( array(), $tree[0]['children'] );
	}

	// ── rr_observe_heading_warnings() ─────────────────────────────────────────

	public function test_warnings_flags_missing_h1(): void {
		$flat = array( array( 'level' => 2, 'text' => 'Only H2' ) );
		$this->assertContains( 'no_h1', rr_observe_heading_warnings( $flat ) );
	}

	public function test_warnings_flags_multiple_h1_and_skipped_level(): void {
		$flat = array(
			array( 'level' => 1, 'text' => 'One' ),
			array( 'level' => 4, 'text' => 'Jumped' ),
			array( 'level' => 1, 'text' => 'Two' ),
		);
		$warnings = rr_observe_heading_warnings( $flat );

		$this->assertContains( 'multiple_h1', $warnings );
		$this->assertContains( 'skipped_level', $warnings );
	}

	public function test_warnings_empty_for_clean_hierarchy(): void {
		$flat = array(
			array( 'level' => 1, 'text' => 'Title' ),
			array( 'level' => 2, 'text' => 'Section' ),
		);
		$this->assertSame( array(), rr_observe_heading_warnings( $flat ) );
	}

	public function test_warnings_empty_for_no_headings_at_all(): void {
		// A post with zero headings gets no no_h1 warning — nothing to structure.
		$this->assertSame( array(), rr_observe_heading_warnings( array() ) );
	}

	// ── rr_observe_extract_links() ────────────────────────────────────────────

	public function test_extract_links_returns_href_and_anchor_text(): void {
		$html  = '<p><a href="https://example.test/about/">About <strong>Us</strong></a></p>';
		$links = rr_observe_extract_links( $html );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://example.test/about/', $links[0]['url'] );
		$this->assertSame( 'About Us', $links[0]['anchor_text'] );
	}

	public function test_extract_links_skips_non_navigational_schemes(): void {
		$html  = '<a href="mailto:x@y.z">Mail</a><a href="tel:+15551234">Call</a>'
			. '<a href="#section">Jump</a><a href="javascript:void(0)">JS</a>'
			. '<a href="/contact/">Contact</a>';
		$links = rr_observe_extract_links( $html );

		$this->assertCount( 1, $links );
		$this->assertSame( '/contact/', $links[0]['url'] );
	}

	public function test_extract_links_handles_single_quoted_href(): void {
		$links = rr_observe_extract_links( "<a href='/services/'>Services</a>" );

		$this->assertCount( 1, $links );
		$this->assertSame( '/services/', $links[0]['url'] );
	}

	// ── rr_observe_extract_markdown_urls() ────────────────────────────────────

	public function test_extract_markdown_urls_finds_link_targets(): void {
		$md   = "## Pages\n- [Home](https://example.test/): Welcome\n- [About](https://example.test/about/)";
		$urls = rr_observe_extract_markdown_urls( $md );

		$this->assertSame(
			array( 'https://example.test/', 'https://example.test/about/' ),
			$urls
		);
	}

	public function test_extract_markdown_urls_ignores_plain_text_urls(): void {
		$this->assertSame( array(), rr_observe_extract_markdown_urls( 'Visit https://example.test/ today' ) );
	}

	// ── rr_observe_diff_url_sets() ────────────────────────────────────────────

	public function test_diff_reports_full_sync(): void {
		$llms      = array( 'https://example.test/a/', 'https://example.test/b/' );
		$canonical = array( 'https://example.test/a/', 'https://example.test/b/' );
		$diff      = rr_observe_diff_url_sets( $llms, $canonical, 'example.test' );

		$this->assertCount( 2, $diff['in_both'] );
		$this->assertSame( array(), $diff['in_llms_not_canonical'] );
		$this->assertSame( array(), $diff['in_canonical_not_llms'] );
	}

	public function test_diff_normalizes_trailing_slash_and_case(): void {
		$llms      = array( 'https://Example.test/a' );
		$canonical = array( 'https://example.test/a/' );
		$diff      = rr_observe_diff_url_sets( $llms, $canonical, 'example.test' );

		$this->assertCount( 1, $diff['in_both'] );
		$this->assertSame( array(), $diff['in_llms_not_canonical'] );
	}

	public function test_diff_reports_drift_in_both_directions(): void {
		$llms      = array( 'https://example.test/only-in-llms/' );
		$canonical = array( 'https://example.test/only-in-canonical/' );
		$diff      = rr_observe_diff_url_sets( $llms, $canonical, 'example.test' );

		$this->assertSame( array( 'https://example.test/only-in-llms/' ), $diff['in_llms_not_canonical'] );
		$this->assertSame( array( 'https://example.test/only-in-canonical/' ), $diff['in_canonical_not_llms'] );
	}

	public function test_diff_ignores_off_host_and_sitemap_links_on_llms_side(): void {
		$llms      = array(
			'https://other.example/external/',
			'https://example.test/sitemap_index.xml',
			'https://example.test/a/',
		);
		$canonical = array( 'https://example.test/a/' );
		$diff      = rr_observe_diff_url_sets( $llms, $canonical, 'example.test' );

		$this->assertCount( 1, $diff['in_both'] );
		$this->assertSame( array(), $diff['in_llms_not_canonical'] );
	}

	// ── rr_observe_normalize_compare_url() ────────────────────────────────────

	public function test_normalize_compare_url(): void {
		$this->assertSame(
			'https://example.test/page',
			rr_observe_normalize_compare_url( 'https://Example.test/Page/' )
		);
	}
}
