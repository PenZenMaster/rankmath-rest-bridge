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
		// Document scope: the headings are the whole page, so this is a real no_h1.
		$flat = array( array( 'level' => 2, 'text' => 'Only H2' ) );
		$this->assertContains( 'no_h1', rr_observe_heading_warnings( $flat, 'document' ) );
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
		// A fragment with zero headings gets no warning -- nothing to structure.
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

	// ── rr_observe_extract_schema_types() (issue #24) ─────────────────────────

	public function test_extract_schema_types_empty_for_non_array(): void {
		$this->assertSame( array(), rr_observe_extract_schema_types( null ) );
		$this->assertSame( array(), rr_observe_extract_schema_types( '' ) );
		$this->assertSame( array(), rr_observe_extract_schema_types( array() ) );
	}

	public function test_extract_schema_types_single_node(): void {
		$graph = array( '@type' => 'LocalBusiness' );
		$this->assertSame( array( 'LocalBusiness' ), rr_observe_extract_schema_types( $graph ) );
	}

	public function test_extract_schema_types_graph_envelope_deduplicated(): void {
		$graph = array(
			'@graph' => array(
				array( '@type' => 'LocalBusiness' ),
				array( '@type' => array( 'Service', 'LocalBusiness' ) ),
				array( 'no_type_here' => true ),
			),
		);
		$this->assertSame( array( 'LocalBusiness', 'Service' ), rr_observe_extract_schema_types( $graph ) );
	}

	// ── rr_observe_check_primary_action() (issue #24 check #1) ────────────────

	public function test_primary_action_passes_on_form(): void {
		$check = rr_observe_check_primary_action( '<p>text</p><form action="/apply"><input></form>' );
		$this->assertSame( 'pass', $check['status'] );
	}

	public function test_primary_action_passes_on_aria_labelled_button(): void {
		$check = rr_observe_check_primary_action( '<button aria-label="Start application">Go</button>' );
		$this->assertSame( 'pass', $check['status'] );
	}

	public function test_primary_action_passes_on_unambiguous_link_text(): void {
		$check = rr_observe_check_primary_action( '<a href="/apply">Get Pre-Approved in Minutes</a>' );
		$this->assertSame( 'pass', $check['status'] );
	}

	public function test_primary_action_fails_on_vague_link_text_only(): void {
		$check = rr_observe_check_primary_action( '<p>Some text.</p><a href="/x">Click here</a><a href="/y">Learn more</a>' );
		$this->assertSame( 'fail', $check['status'] );
	}

	public function test_primary_action_fails_on_empty_content(): void {
		$check = rr_observe_check_primary_action( '' );
		$this->assertSame( 'fail', $check['status'] );
	}

	// ── rr_observe_check_schema_completeness() (issue #24 check #2) ───────────

	public function test_schema_completeness_fails_when_no_types(): void {
		$check = rr_observe_check_schema_completeness( array() );
		$this->assertSame( 'fail', $check['status'] );
		$this->assertSame( 'add_schema_via_post_schema_endpoint', $check['remediation_hint'] );
	}

	public function test_schema_completeness_passes_when_types_present(): void {
		$check = rr_observe_check_schema_completeness( array( 'LocalBusiness' ) );
		$this->assertSame( 'pass', $check['status'] );
		$this->assertNull( $check['remediation_hint'] );
	}

	// ── rr_observe_check_breadcrumb_navigation() (issue #24 check #3) ─────────

	public function test_breadcrumb_navigation_passes_when_breadcrumblist_present(): void {
		$check = rr_observe_check_breadcrumb_navigation( array( 'LocalBusiness', 'BreadcrumbList' ) );
		$this->assertSame( 'pass', $check['status'] );
	}

	public function test_breadcrumb_navigation_fails_when_absent(): void {
		$check = rr_observe_check_breadcrumb_navigation( array( 'LocalBusiness' ) );
		$this->assertSame( 'fail', $check['status'] );
		$this->assertSame( 'add_breadcrumblist_via_post_schema_endpoint', $check['remediation_hint'] );
	}

	// ── Link classification (issue #31) ───────────────────────────────────────

	private function link( string $url = 'https://example.test/contact' ): array {
		return array(
			'url'         => $url,
			'anchor_text' => 'Contact',
		);
	}

	private function redirect_rule( string $source, string $target ): array {
		return array(
			'id'          => 'r1',
			'enabled'     => true,
			'source'      => $source,
			'match_type'  => 'exact',
			'target'      => $target,
			'status_code' => 301,
		);
	}

	public function test_status_to_resolution_only_public_statuses_are_ok(): void {
		$this->assertSame( 'ok', rr_observe_status_to_resolution( 'publish' ) );
		$this->assertSame( 'ok', rr_observe_status_to_resolution( 'inherit' ) );
		foreach ( array( 'draft', 'private', 'pending', 'future', 'trash' ) as $status ) {
			$this->assertSame( 'not_public', rr_observe_status_to_resolution( $status ), $status );
		}
	}

	public function test_healthy_internal_link_is_not_inventoried(): void {
		$this->assertNull( rr_observe_internal_link_item( $this->link(), 'https://example.test/contact', 1, 'ok', array() ) );
	}

	public function test_local_miss_is_an_unverified_candidate_not_a_measured_404(): void {
		$item = rr_observe_internal_link_item( $this->link(), 'https://example.test/contact', 3609, 'not_found', array() );

		$this->assertNull( $item['status_code'] );
		$this->assertFalse( $item['checked'] );
		$this->assertSame( 'unverified', $item['verification'] );
		$this->assertSame( 'not_found', $item['resolution'] );
		$this->assertSame( 3609, $item['source_post_id'] );
		$this->assertSame( 'internal', $item['scope'] );
	}

	public function test_local_miss_matching_a_registered_redirect_is_not_a_broken_candidate(): void {
		$rules = array( $this->redirect_rule( '/contact', '/contact-rank-rocket/' ) );
		$item  = rr_observe_internal_link_item( $this->link(), 'https://example.test/contact', 1, 'not_found', $rules );

		$this->assertSame( 'redirect_registered', $item['resolution'] );
		$this->assertSame( '/contact-rank-rocket/', $item['redirect_target'] );
		$this->assertNull( $item['status_code'] );
		$this->assertFalse( $item['checked'] );
	}

	public function test_registered_redirect_match_ignores_query_string(): void {
		$rules = array( $this->redirect_rule( '/contact', '/contact-rank-rocket/' ) );
		$item  = rr_observe_internal_link_item( $this->link(), 'https://example.test/contact?utm_source=x', 1, 'not_found', $rules );

		$this->assertSame( 'redirect_registered', $item['resolution'] );
	}

	public function test_disabled_redirect_rule_does_not_hide_a_local_miss(): void {
		$rule            = $this->redirect_rule( '/contact', '/contact-rank-rocket/' );
		$rule['enabled'] = false;
		$item            = rr_observe_internal_link_item( $this->link(), 'https://example.test/contact', 1, 'not_found', array( $rule ) );

		$this->assertSame( 'not_found', $item['resolution'] );
		$this->assertArrayNotHasKey( 'redirect_target', $item );
	}

	public function test_non_public_and_unverified_resolutions_pass_through_without_redirect_lookup(): void {
		$rules = array( $this->redirect_rule( '/contact', '/elsewhere/' ) );
		foreach ( array( 'not_public', 'unverified' ) as $resolution ) {
			$item = rr_observe_internal_link_item( $this->link(), 'https://example.test/contact', 1, $resolution, $rules );
			$this->assertSame( $resolution, $item['resolution'] );
			$this->assertNull( $item['status_code'] );
			$this->assertFalse( $item['checked'] );
		}
	}

	public function test_link_summary_counts_occurrences_and_unique_destinations_separately(): void {
		$items = array();
		foreach ( array( 10, 11, 12 ) as $source ) {
			$items[] = rr_observe_internal_link_item( $this->link(), 'https://example.test/contact', $source, 'not_found', array() );
		}
		$items[] = rr_observe_internal_link_item( $this->link( 'https://example.test/tos' ), 'https://example.test/tos', 10, 'not_found', array() );

		$summary = rr_observe_link_summary( $items );

		$this->assertSame( 4, $summary['occurrences'] );
		$this->assertSame( 2, $summary['unique_urls'] );
		$this->assertSame( array( 10, 11, 12 ), array_column( array_slice( $items, 0, 3 ), 'source_post_id' ) );
	}

	public function test_link_summary_of_empty_inventory_is_zero(): void {
		$this->assertSame(
			array(
				'occurrences' => 0,
				'unique_urls' => 0,
			),
			rr_observe_link_summary( array() )
		);
	}

	// ── rr_observe_resolve_internal_url() (issue #31) ────────────────────────

	protected function setUp(): void {
		$GLOBALS['_test_posts']          = array();
		$GLOBALS['_test_url_to_postid']  = array();
		$GLOBALS['_test_pages_by_path']  = array();
		$GLOBALS['_test_permalink']      = array();
		$GLOBALS['_test_http']           = new WP_Error( 'no_seed', 'no http seeded' );
		$GLOBALS['_test_http_requests']  = array();
	}

	private function seed_post( int $id, string $status ): WP_Post {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_status = $status;
		$GLOBALS['_test_posts'][ $id ] = $post;
		return $post;
	}

	public function test_resolve_published_post_is_ok(): void {
		$this->seed_post( 5, 'publish' );
		$GLOBALS['_test_url_to_postid']['https://example.test/about/'] = 5;

		$this->assertSame( 'ok', rr_observe_resolve_internal_url( 'https://example.test/about/' ) );
	}

	public function test_resolve_draft_and_private_posts_are_not_public(): void {
		$this->seed_post( 6, 'draft' );
		$this->seed_post( 7, 'private' );
		$GLOBALS['_test_url_to_postid']['https://example.test/?p=6'] = 6;
		$GLOBALS['_test_url_to_postid']['https://example.test/?p=7'] = 7;

		$this->assertSame( 'not_public', rr_observe_resolve_internal_url( 'https://example.test/?p=6' ) );
		$this->assertSame( 'not_public', rr_observe_resolve_internal_url( 'https://example.test/?p=7' ) );
	}

	public function test_resolve_draft_page_found_by_path_is_not_public(): void {
		$GLOBALS['_test_pages_by_path']['services/plumbing'] = $this->seed_post( 8, 'draft' );

		$this->assertSame( 'not_public', rr_observe_resolve_internal_url( 'https://example.test/services/plumbing/' ) );
	}

	public function test_resolve_published_page_found_by_path_is_ok(): void {
		$GLOBALS['_test_pages_by_path']['services/plumbing'] = $this->seed_post( 9, 'publish' );

		$this->assertSame( 'ok', rr_observe_resolve_internal_url( 'https://example.test/services/plumbing/' ) );
	}

	public function test_resolve_unknown_path_is_a_local_miss_and_site_root_is_ok(): void {
		$this->assertSame( 'not_found', rr_observe_resolve_internal_url( 'https://example.test/contact' ) );
		$this->assertSame( 'ok', rr_observe_resolve_internal_url( 'https://example.test/' ) );
	}

	public function test_resolve_archive_shaped_url_is_unverified(): void {
		$this->assertSame( 'unverified', rr_observe_resolve_internal_url( 'https://example.test/category/news/' ) );
	}

	// ── Source-aware heading observation (issue #29) ──────────────────────────

	public function test_document_scope_reports_no_h1_even_with_zero_headings(): void {
		$this->assertContains( 'no_h1', rr_observe_heading_warnings( array(), 'document' ) );
	}

	public function test_document_scope_with_a_single_h1_has_no_h1_warning(): void {
		$flat = array(
			array( 'level' => 1, 'text' => 'Title' ),
			array( 'level' => 2, 'text' => 'Section' ),
		);
		$this->assertNotContains( 'no_h1', rr_observe_heading_warnings( $flat, 'document' ) );
		$this->assertNotContains( 'no_h1_in_fragment', rr_observe_heading_warnings( $flat, 'document' ) );
	}

	public function test_fragment_scope_never_claims_whole_page_h1_absence(): void {
		$flat     = array( array( 'level' => 2, 'text' => 'Only H2' ) );
		$warnings = rr_observe_heading_warnings( $flat, 'fragment' );

		$this->assertContains( 'no_h1_in_fragment', $warnings );
		$this->assertNotContains( 'no_h1', $warnings );
	}

	public function test_fragment_scope_with_no_headings_has_no_warning(): void {
		$this->assertSame( array(), rr_observe_heading_warnings( array(), 'fragment' ) );
	}

	public function test_empty_headings_remain_discoverable_in_document_scope(): void {
		$html  = '<h1>Title</h1><h2></h2><h2>  </h2>';
		$flat  = rr_observe_parse_headings( $html );
		$warns = rr_observe_heading_warnings( $flat, 'document' );

		$this->assertContains( 'empty_heading', $warns );
		$this->assertNotContains( 'no_h1', $warns );
	}

	public function test_strip_inert_markup_ignores_headings_in_scripts_styles_templates_and_comments(): void {
		$html = '<h1>Real</h1>'
			. '<script type="application/ld+json">{"x":"<h1>Fake A</h1>"}</script>'
			. '<style>/* <h1>Fake B</h1> */</style>'
			. '<template><h1>Fake C</h1></template>'
			. '<noscript><h1>Fake D</h1></noscript>'
			. '<!-- <h1>Fake E</h1> -->';
		$flat = rr_observe_parse_headings( rr_observe_strip_inert_markup( $html ) );

		$this->assertCount( 1, $flat );
		$this->assertSame( 'Real', $flat[0]['text'] );
	}

	public function test_classify_frontend_response_accepts_same_host_html(): void {
		$result = rr_observe_classify_frontend_response( 200, 'text/html; charset=UTF-8', 'example.test', 'example.test', '<h1>x</h1>' );

		$this->assertSame( '<h1>x</h1>', $result['html'] );
		$this->assertNull( $result['error'] );
	}

	public function test_classify_frontend_response_rejects_failures_without_returning_html(): void {
		$cases = array(
			'fetch_failed'        => array( 0, 'text/html', 'example.test', '<h1>x</h1>' ),
			'redirected_off_host' => array( 200, 'text/html', 'evil.test', '<h1>x</h1>' ),
			'http_status_403'     => array( 403, 'text/html', 'example.test', '<h1>x</h1>' ),
			'http_status_503'     => array( 503, 'text/html', 'example.test', '<h1>x</h1>' ),
			'not_html'            => array( 200, 'application/json', 'example.test', '{"a":1}' ),
		);
		foreach ( $cases as $expected => $c ) {
			$result = rr_observe_classify_frontend_response( $c[0], $c[1], $c[2], 'example.test', $c[3] );
			$this->assertNull( $result['html'], $expected );
			$this->assertSame( $expected, $result['error'] );
		}
	}

	public function test_classify_frontend_response_rejects_empty_body(): void {
		$result = rr_observe_classify_frontend_response( 200, 'text/html', 'example.test', 'example.test', '   ' );
		$this->assertSame( 'not_html', $result['error'] );
	}

	private function seed_heading_post( int $id, string $content ): WP_Post {
		$post               = new WP_Post();
		$post->ID           = $id;
		$post->post_status  = 'publish';
		$post->post_title   = 'Home';
		$post->post_content = $content;
		$GLOBALS['_test_posts'][ $id ] = $post;
		$GLOBALS['_test_permalink'][ $id ] = 'https://example.test/home/';
		return $post;
	}

	private function observe_headings( int $id, string $source = 'auto' ): array {
		$response = rmb_observe_heading_hierarchy(
			new WP_REST_Request(
				array(
					'post_id' => $id,
					'source'  => $source,
				)
			)
		);
		return $response->get_data();
	}

	public function test_handler_document_source_sees_theme_h1_outside_post_content(): void {
		// Issue #29: the H1 lives in the theme/template, not in post_content.
		$this->seed_heading_post( 2604, '<h2>Services</h2><h2>About</h2>' );
		$GLOBALS['_test_http'] = array(
			'code' => 200,
			'type' => 'text/html',
			'url'  => 'https://example.test/home/',
			'body' => '<html><body><h1>SEO Agency</h1><h2>Services</h2><h2>About</h2></body></html>',
		);

		$data = $this->observe_headings( 2604 );

		$this->assertSame( 'document', $data['scope'] );
		$this->assertSame( 'frontend_html', $data['source'] );
		$this->assertTrue( $data['complete'] );
		$this->assertNull( $data['fallback_reason'] );
		$this->assertSame( 3, $data['heading_count'] );
		$this->assertNotContains( 'no_h1', $data['warnings'] );
		$this->assertSame( array( 'https://example.test/home/' ), $GLOBALS['_test_http_requests'] );
	}

	public function test_handler_document_source_detects_a_genuinely_h1_free_page(): void {
		$this->seed_heading_post( 11, '<p>No headings</p>' );
		$GLOBALS['_test_http'] = array(
			'code' => 200,
			'type' => 'text/html',
			'url'  => 'https://example.test/home/',
			'body' => '<html><body><p>nothing</p></body></html>',
		);

		$data = $this->observe_headings( 11 );

		$this->assertSame( 0, $data['heading_count'] );
		$this->assertContains( 'no_h1', $data['warnings'] );
	}

	public function test_handler_auto_falls_back_to_fragment_and_says_so(): void {
		$this->seed_heading_post( 12, '<h2>Services</h2>' );
		$GLOBALS['_test_http'] = new WP_Error( 'http_request_failed', 'timeout' );

		$data = $this->observe_headings( 12 );

		$this->assertSame( 'fragment', $data['scope'] );
		$this->assertSame( 'post_content', $data['source'] );
		$this->assertFalse( $data['complete'] );
		$this->assertSame( 'fetch_failed', $data['fallback_reason'] );
		$this->assertContains( 'no_h1_in_fragment', $data['warnings'] );
		$this->assertNotContains( 'no_h1', $data['warnings'] );
	}

	public function test_handler_document_source_failure_is_unverified_not_empty(): void {
		$this->seed_heading_post( 13, '<h1>Has H1</h1>' );
		$GLOBALS['_test_http'] = array(
			'code' => 503,
			'type' => 'text/html',
			'url'  => 'https://example.test/home/',
			'body' => 'challenge',
		);

		$data = $this->observe_headings( 13, 'document' );

		$this->assertFalse( $data['complete'] );
		$this->assertSame( 'unverified', $data['verification'] );
		$this->assertSame( 'http_status_503', $data['error'] );
		$this->assertNull( $data['heading_count'] );
		$this->assertSame( array(), $data['warnings'] );
	}

	public function test_handler_content_source_never_fetches(): void {
		$this->seed_heading_post( 14, '<h1>Title</h1><h2>Section</h2>' );

		$data = $this->observe_headings( 14, 'content' );

		$this->assertSame( 'fragment', $data['scope'] );
		$this->assertFalse( $data['complete'] );
		$this->assertSame( array(), $GLOBALS['_test_http_requests'] );
	}

	public function test_handler_rejects_off_host_permalink_without_fetching(): void {
		$this->seed_heading_post( 15, '<h2>x</h2>' );
		$GLOBALS['_test_permalink'][15] = 'https://evil.test/home/';

		$data = $this->observe_headings( 15 );

		$this->assertSame( 'bad_permalink', $data['fallback_reason'] );
		$this->assertSame( array(), $GLOBALS['_test_http_requests'] );
	}

	public function test_handler_sequential_posts_do_not_leak_state(): void {
		$this->seed_heading_post( 16, '<h2>A</h2>' );
		$this->seed_heading_post( 17, '<h1>B</h1>' );
		$GLOBALS['_test_http'] = new WP_Error( 'http_request_failed', 'timeout' );

		$first  = $this->observe_headings( 16 );
		$second = $this->observe_headings( 17 );

		$this->assertContains( 'no_h1_in_fragment', $first['warnings'] );
		$this->assertSame( array(), $second['warnings'] );
		$this->assertSame( 17, $second['post_id'] );
	}

	public function test_handler_unpublished_post_is_not_found(): void {
		$post              = $this->seed_heading_post( 18, '<h1>x</h1>' );
		$post->post_status = 'draft';

		$result = rmb_observe_heading_hierarchy( new WP_REST_Request( array( 'post_id' => 18 ) ) );

		$this->assertTrue( is_wp_error( $result ) );
	}
}
