<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for issue #30 -- graph-aware schema audits.
 *
 * Covers the shared walker (rr_schema_inventory()), JSON-LD block extraction,
 * static snippet applicability, and the per-source behaviour of
 * rr_aeo_compute_schema_audit() (stored schema, applicable snippets and
 * optional public frontend evidence).
 */
class SchemaInventoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_posts']          = array();
		$GLOBALS['_test_posts_list']     = array();
		$GLOBALS['_test_pages_list']     = array();
		$GLOBALS['_test_post_meta']      = array();
		$GLOBALS['_test_permalink']      = array();
		$GLOBALS['_test_options']        = array();
		$GLOBALS['_test_http']           = new WP_Error( 'no_seed', 'no http seeded' );
		$GLOBALS['_test_http_requests']  = array();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function page( int $id, string $slug, string $url ): void {
		$p                    = new WP_Post();
		$p->ID                = $id;
		$p->post_name         = $slug;
		$p->post_type         = 'page';
		$p->post_status       = 'publish';
		$p->post_password     = '';
		$p->post_title        = 'Page ' . $id;
		$p->post_excerpt      = '';
		$p->post_content      = 'Content';
		$p->post_modified_gmt = '2026-04-29 10:00:00';

		$GLOBALS['_test_pages_list'][]     = $p;
		$GLOBALS['_test_posts'][ $id ]     = $p;
		$GLOBALS['_test_permalink'][ $id ] = $url;
	}

	private function stored( int $post_id, array $schema ): void {
		$GLOBALS['_test_post_meta'][ $post_id ][ RR_SCHEMA_META_KEY ] = $schema;
	}

	private function snippets( array $snippets ): void {
		$GLOBALS['_test_options'][ RMB_SNIPPETS_KEY ] = $snippets;
	}

	private function jsonld_snippet( array $node, array $overrides = array() ): array {
		return array_merge(
			array(
				'title'      => 'ld',
				'content'    => '<script type="application/ld+json">' . wp_json_encode_for_tests( $node ) . '</script>',
				'location'   => 'head',
				'display_on' => 'sitewide',
				'status'     => 'active',
			),
			$overrides
		);
	}

	private function url_entry( array $audit, int $post_id ): array {
		foreach ( $audit['urls'] as $entry ) {
			if ( $post_id === $entry['post_id'] ) {
				return $entry;
			}
		}
		$this->fail( 'url entry not found: ' . $post_id );
	}

	// ── rr_schema_inventory() ─────────────────────────────────────────────────

	public function test_single_node_bare_array_and_graph_envelope_yield_the_same_types(): void {
		$org = array( '@type' => 'Organization', '@id' => 'https://example.test/#org', 'name' => 'Acme' );
		$web = array( '@type' => 'WebSite', '@id' => 'https://example.test/#web' );

		$single = rr_schema_inventory( $org );
		$array  = rr_schema_inventory( array( $org, $web ) );
		$graph  = rr_schema_inventory( array( '@context' => 'https://schema.org', '@graph' => array( $org, $web ) ) );

		$this->assertSame( array( 'Organization' ), $single['types'] );
		$this->assertEqualsCanonicalizing( array( 'Organization', 'WebSite' ), $array['types'] );
		$this->assertEqualsCanonicalizing( $array['types'], $graph['types'] );
	}

	public function test_array_valued_type_and_namespace_prefixes_are_normalized(): void {
		$inv = rr_schema_inventory(
			array( '@type' => array( 'LocalBusiness', 'https://schema.org/Store', 'schema:Organization' ) )
		);

		$this->assertEqualsCanonicalizing( array( 'LocalBusiness', 'Store', 'Organization' ), $inv['types'] );
	}

	public function test_nested_typed_entities_are_reported_with_the_parent(): void {
		$inv = rr_schema_inventory(
			array(
				'@type'            => 'Service',
				'name'             => 'Website Design',
				'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => 'https://example.test/website-design/' ),
				'provider'         => array( '@id' => 'https://example.test/#organization' ),
			)
		);

		$this->assertEqualsCanonicalizing( array( 'Service', 'WebPage' ), $inv['types'] );
		$this->assertSame( array( 'https://example.test/#organization' ), $inv['references'] );
	}

	public function test_id_only_reference_is_not_a_type_or_a_definition(): void {
		$inv = rr_schema_inventory( array( '@type' => 'Service', 'provider' => array( '@id' => 'https://example.test/#org' ) ) );

		$this->assertSame( array( 'Service' ), $inv['types'] );
		$this->assertCount( 1, $inv['entities'] );
	}

	public function test_repeated_id_references_do_not_create_duplicate_entity_warnings(): void {
		$inv = rr_schema_inventory(
			array(
				'@graph' => array(
					array( '@type' => 'Organization', '@id' => 'https://example.test/#org' ),
					array( '@type' => 'Service', 'provider' => array( '@id' => 'https://example.test/#org' ) ),
					array( '@type' => 'WebPage', 'publisher' => array( '@id' => 'https://example.test/#org' ) ),
				),
			)
		);

		$this->assertSame( array(), $inv['duplicate_ids'] );
	}

	public function test_same_id_defined_twice_is_a_duplicate_entity(): void {
		$inv = rr_schema_inventory(
			array(
				array( '@type' => 'Organization', '@id' => 'https://example.test/#org' ),
				array( '@type' => 'LocalBusiness', '@id' => 'https://example.test/#org' ),
			)
		);

		$this->assertSame( array( 'https://example.test/#org' ), $inv['duplicate_ids'] );
	}

	public function test_non_array_and_empty_input_yield_an_empty_inventory(): void {
		foreach ( array( '', null, 5, array(), array( 'name' => 'untyped' ) ) as $input ) {
			$inv = rr_schema_inventory( $input );
			$this->assertSame( array(), $inv['types'] );
		}
	}

	public function test_recursion_depth_is_bounded(): void {
		$node = array( '@type' => 'Thing' );
		for ( $i = 0; $i < 40; $i++ ) {
			$node = array( 'child' => $node );
		}

		$this->assertSame( array(), rr_schema_inventory( $node )['types'] );
	}

	public function test_observe_extract_schema_types_uses_the_shared_walker(): void {
		$types = rr_observe_extract_schema_types(
			array( '@type' => 'Service', 'mainEntityOfPage' => array( '@type' => 'WebPage' ) )
		);

		$this->assertEqualsCanonicalizing( array( 'Service', 'WebPage' ), $types );
	}

	// ── rr_schema_extract_jsonld_blocks() ─────────────────────────────────────

	public function test_invalid_jsonld_is_reported_per_block_while_valid_blocks_remain(): void {
		$html   = '<script type="application/ld+json">{"@type":"Organization"}</script>'
			. '<script type="application/ld+json">{ not json </script>'
			. "<script type='application/ld+json'>{\"@type\":\"WebSite\"}</script>"
			. '<script type="text/javascript">var a = {"@type":"Nope"};</script>';
		$blocks = rr_schema_extract_jsonld_blocks( $html );

		$this->assertCount( 3, $blocks );
		$this->assertTrue( $blocks[0]['valid'] );
		$this->assertFalse( $blocks[1]['valid'] );
		$this->assertSame( 'invalid_json', $blocks[1]['error'] );
		$this->assertTrue( $blocks[2]['valid'] );
	}

	// ── rr_snippet_applies_to_post() ──────────────────────────────────────────

	private function ctx( array $over = array() ): array {
		return array_merge(
			array(
				'post_id'   => 10,
				'post_type' => 'page',
				'is_front'  => false,
				'path'      => '/website-design/',
			),
			$over
		);
	}

	public function test_snippet_targeting_matches_the_emitter_semantics(): void {
		$base = array( 'content' => 'x', 'location' => 'head', 'status' => 'active' );

		$this->assertTrue( rr_snippet_applies_to_post( $base + array( 'display_on' => 'sitewide' ), $this->ctx() ) );
		$this->assertTrue( rr_snippet_applies_to_post( $base + array( 'display_on' => 'page_id:10' ), $this->ctx() ) );
		$this->assertFalse( rr_snippet_applies_to_post( $base + array( 'display_on' => 'page_id:11' ), $this->ctx() ) );
		$this->assertTrue( rr_snippet_applies_to_post( $base + array( 'display_on' => '10' ), $this->ctx() ) );
		$this->assertTrue( rr_snippet_applies_to_post( $base + array( 'display_on' => 'post_type:page' ), $this->ctx() ) );
		$this->assertFalse( rr_snippet_applies_to_post( $base + array( 'display_on' => 'post_type:post' ), $this->ctx() ) );
		$this->assertTrue( rr_snippet_applies_to_post( $base + array( 'display_on' => 'url:/website-design' ), $this->ctx() ) );
		$this->assertFalse( rr_snippet_applies_to_post( $base + array( 'display_on' => 'home' ), $this->ctx() ) );
		$this->assertTrue( rr_snippet_applies_to_post( $base + array( 'display_on' => 'home' ), $this->ctx( array( 'is_front' => true ) ) ) );
		$this->assertTrue( rr_snippet_applies_to_post( $base + array( 'display_on' => 'all_pages' ), $this->ctx() ) );
		$this->assertFalse( rr_snippet_applies_to_post( $base + array( 'display_on' => 'all_posts' ), $this->ctx() ) );
		// Archive-only and unknown targeting never apply to a post.
		$this->assertFalse( rr_snippet_applies_to_post( $base + array( 'display_on' => 'tax:category' ), $this->ctx() ) );
		$this->assertFalse( rr_snippet_applies_to_post( $base + array( 'display_on' => 'term:category:news' ), $this->ctx() ) );
		$this->assertFalse( rr_snippet_applies_to_post( $base + array( 'display_on' => 'mystery' ), $this->ctx() ) );
	}

	public function test_inactive_empty_bad_location_and_logged_in_only_snippets_do_not_apply(): void {
		$ok = array( 'content' => 'x', 'location' => 'head', 'status' => 'active', 'display_on' => 'sitewide' );

		$this->assertTrue( rr_snippet_applies_to_post( $ok, $this->ctx() ) );
		$this->assertFalse( rr_snippet_applies_to_post( array_merge( $ok, array( 'status' => 'inactive' ) ), $this->ctx() ) );
		$this->assertFalse( rr_snippet_applies_to_post( array_merge( $ok, array( 'content' => '' ) ), $this->ctx() ) );
		$this->assertFalse( rr_snippet_applies_to_post( array_merge( $ok, array( 'location' => 'sidebar' ) ), $this->ctx() ) );
		$this->assertFalse( rr_snippet_applies_to_post( array_merge( $ok, array( 'display_on_user' => 'logged_in' ) ), $this->ctx() ) );
		$this->assertTrue( rr_snippet_applies_to_post( array_merge( $ok, array( 'display_on_user' => 'anonymous' ) ), $this->ctx() ) );
	}

	// ── rr_aeo_compute_schema_audit() ─────────────────────────────────────────

	public function test_graph_stored_schema_is_counted_not_reported_as_absent(): void {
		$this->page( 100, 'home', 'https://example.test/' );
		$this->stored(
			100,
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array(
					array( '@type' => 'Organization', '@id' => 'https://example.test/#organization' ),
					array( '@type' => 'WebSite' ),
				),
			)
		);

		$audit = rr_aeo_compute_schema_audit();

		$this->assertSame( 1, $audit['summary']['with_schema'] );
		$this->assertEqualsCanonicalizing( array( 'Organization', 'WebSite' ), $this->url_entry( $audit, 100 )['schema_types'] );
		$this->assertContains( 'native', array_column( $this->url_entry( $audit, 100 )['schema_sources'], 'source' ) );
	}

	public function test_service_with_nested_webpage_reports_both_types_and_satisfies_service_check(): void {
		$this->page( 200, 'service', 'https://example.test/service/website-design/' );
		$this->stored(
			200,
			array(
				'@type'            => 'Service',
				'mainEntityOfPage' => array( '@type' => 'WebPage' ),
			)
		);

		$entry = $this->url_entry( rr_aeo_compute_schema_audit(), 200 );

		$this->assertEqualsCanonicalizing( array( 'Service', 'WebPage' ), $entry['schema_types'] );
		$this->assertNotContains( 'Service', $entry['missing_opportunities'] );
	}

	public function test_page_scoped_snippet_counts_only_on_its_target(): void {
		$this->page( 300, 'a', 'https://example.test/a/' );
		$this->page( 301, 'b', 'https://example.test/b/' );
		$this->snippets(
			array(
				'svc-a' => $this->jsonld_snippet( array( '@type' => 'Service' ), array( 'display_on' => 'page_id:300' ) ),
			)
		);

		$audit = rr_aeo_compute_schema_audit();

		$this->assertTrue( $this->url_entry( $audit, 300 )['has_schema'] );
		$this->assertSame( array( 'Service' ), $this->url_entry( $audit, 300 )['schema_types'] );
		$this->assertFalse( $this->url_entry( $audit, 301 )['has_schema'] );
		$this->assertSame( 1, $audit['summary']['with_schema'] );
		$source = $this->url_entry( $audit, 300 )['schema_sources'][0];
		$this->assertSame( 'snippet', $source['source'] );
		$this->assertSame( 'svc-a', $source['snippet_id'] );
	}

	public function test_inactive_snippet_and_disabled_emission_do_not_count_as_live_coverage(): void {
		$this->page( 310, 'a', 'https://example.test/a/' );
		$this->snippets(
			array(
				'off' => $this->jsonld_snippet( array( '@type' => 'Service' ), array( 'status' => 'inactive' ) ),
			)
		);
		$this->assertFalse( $this->url_entry( rr_aeo_compute_schema_audit(), 310 )['has_schema'] );

		$this->snippets( array( 'on' => $this->jsonld_snippet( array( '@type' => 'Service' ) ) ) );
		$this->assertTrue( $this->url_entry( rr_aeo_compute_schema_audit(), 310 )['has_schema'] );

		$GLOBALS['_test_options']['rrseo_emit_snippets'] = false;
		$this->assertFalse( $this->url_entry( rr_aeo_compute_schema_audit(), 310 )['has_schema'] );
	}

	public function test_invalid_snippet_jsonld_is_reported_while_valid_blocks_stay_in_the_inventory(): void {
		$this->page( 320, 'a', 'https://example.test/a/' );
		$this->snippets(
			array(
				'mixed' => array(
					'content'    => '<script type="application/ld+json">{"@type":"Service"}</script>'
						. '<script type="application/ld+json">{broken</script>',
					'location'   => 'head',
					'display_on' => 'sitewide',
					'status'     => 'active',
				),
			)
		);

		$entry = $this->url_entry( rr_aeo_compute_schema_audit(), 320 );

		$this->assertSame( array( 'Service' ), $entry['schema_types'] );
		$this->assertSame( 1, $entry['invalid_jsonld_blocks'] );
		$this->assertSame( 1, $entry['schema_sources'][0]['invalid_blocks'] );
	}

	public function test_public_schema_is_marked_not_inspected_by_default_and_never_fetched(): void {
		$this->page( 400, 'home', 'https://example.test/home/' );

		$audit = rr_aeo_compute_schema_audit();
		$entry = $this->url_entry( $audit, 400 );

		$this->assertSame( 'not_inspected', $entry['public_schema'] );
		$this->assertSame( array( 'native', 'snippets' ), $audit['summary']['sources_inspected'] );
		$this->assertFalse( $audit['summary']['complete'] );
		$this->assertStringContainsString( 'public HTML was not inspected', $audit['note'] );
		$this->assertSame( array(), $GLOBALS['_test_http_requests'] );
	}

	public function test_public_schema_outside_native_storage_is_observed_when_requested(): void {
		$this->page( 410, 'home', 'https://example.test/home/' );
		$GLOBALS['_test_http'] = array(
			'code' => 200,
			'type' => 'text/html',
			'url'  => 'https://example.test/home/',
			'body' => '<html><head><script type="application/ld+json">'
				. '{"@graph":[{"@type":"Organization","@id":"https://example.test/#org"},{"@type":"WebSite"}]}'
				. '</script></head></html>',
		);

		$audit = rr_aeo_compute_schema_audit( array(), null, array( 'inspect_public' => true ) );
		$entry = $this->url_entry( $audit, 410 );

		$this->assertSame( 'inspected', $entry['public_schema'] );
		$this->assertTrue( $entry['has_schema'] );
		$this->assertEqualsCanonicalizing( array( 'Organization', 'WebSite' ), $entry['schema_types'] );
		$this->assertContains( 'public', $audit['summary']['sources_inspected'] );
		$this->assertTrue( $audit['summary']['complete'] );
	}

	public function test_blocked_fetch_cannot_erase_stored_evidence(): void {
		$this->page( 420, 'home', 'https://example.test/home/' );
		$this->stored( 420, array( '@type' => 'Organization' ) );
		$GLOBALS['_test_http'] = array(
			'code' => 403,
			'type' => 'text/html',
			'url'  => 'https://example.test/home/',
			'body' => 'challenge',
		);

		$audit = rr_aeo_compute_schema_audit( array(), null, array( 'inspect_public' => true ) );
		$entry = $this->url_entry( $audit, 420 );

		$this->assertTrue( $entry['has_schema'] );
		$this->assertSame( array( 'Organization' ), $entry['schema_types'] );
		$this->assertSame( 'unavailable', $entry['public_schema'] );
		$this->assertSame( 'http_status_403', $entry['public_schema_error'] );
		$this->assertFalse( $audit['summary']['complete'] );
	}

	public function test_public_inspection_is_bounded_by_offset_and_limit(): void {
		foreach ( array( 500, 501, 502, 503 ) as $id ) {
			$this->page( $id, 'p' . $id, 'https://example.test/p' . $id . '/' );
		}
		$GLOBALS['_test_http'] = array(
			'code' => 200,
			'type' => 'text/html',
			'url'  => 'https://example.test/x/',
			'body' => '<html></html>',
		);

		$audit = rr_aeo_compute_schema_audit(
			array(),
			null,
			array(
				'inspect_public' => true,
				'public_offset'  => 1,
				'public_limit'   => 2,
			)
		);

		$this->assertSame( 2, $audit['summary']['public_requested_count'] );
		$this->assertCount( 2, $GLOBALS['_test_http_requests'] );
		$statuses = array_column( $audit['urls'], 'public_schema' );
		$this->assertSame( array( 'not_inspected', 'inspected', 'inspected', 'not_inspected' ), $statuses );
		$this->assertFalse( $audit['summary']['complete'] );
	}

	public function test_same_entity_defined_in_two_sources_is_reported_as_duplicate(): void {
		$this->page( 600, 'home', 'https://example.test/' );
		$node = array( '@type' => 'Organization', '@id' => 'https://example.test/#org' );
		$this->stored( 600, $node );
		$this->snippets( array( 'org' => $this->jsonld_snippet( $node ) ) );

		$entry = $this->url_entry( rr_aeo_compute_schema_audit(), 600 );

		$this->assertSame( array( 'https://example.test/#org' ), $entry['duplicate_entity_ids'] );
	}

	public function test_reference_in_a_second_source_is_not_a_duplicate(): void {
		$this->page( 610, 'home', 'https://example.test/' );
		$this->stored( 610, array( '@type' => 'Organization', '@id' => 'https://example.test/#org' ) );
		$this->snippets(
			array(
				'svc' => $this->jsonld_snippet(
					array( '@type' => 'Service', 'provider' => array( '@id' => 'https://example.test/#org' ) )
				),
			)
		);

		$entry = $this->url_entry( rr_aeo_compute_schema_audit(), 610 );

		$this->assertSame( array(), $entry['duplicate_entity_ids'] );
	}

	public function test_legacy_single_node_stored_schema_still_works(): void {
		$this->page( 700, 'about', 'https://example.test/about/' );
		$this->stored( 700, array( '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'About' ) );

		$audit = rr_aeo_compute_schema_audit();

		$this->assertSame( 1, $audit['summary']['with_schema'] );
		$this->assertSame( 1, $audit['summary']['types']['WebPage'] );
	}

	// ── Entity signals and business facts (graph-aware) ───────────────────────

	public function test_business_facts_are_extracted_from_a_graph_envelope(): void {
		$this->stored(
			800,
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array(
					array( '@type' => 'WebSite', 'name' => 'Not the business' ),
					array(
						'@type'     => array( 'LocalBusiness', 'ProfessionalService' ),
						'@id'       => 'https://example.test/#business',
						'name'      => 'Acme HVAC',
						'telephone' => '555-0100',
					),
				),
			)
		);

		$facts = rr_extract_business_facts_from_schema( 800 );

		$this->assertSame( 'Acme HVAC', $facts['business_name'] );
		$this->assertSame( '555-0100', $facts['phone'] );
		$this->assertSame( 'LocalBusiness', $facts['schema_type'] );
		$this->assertSame( 'https://example.test/#business', $facts['entity_id'] );
	}

	public function test_business_facts_ignore_schema_without_a_business_entity(): void {
		$this->stored( 801, array( '@graph' => array( array( '@type' => 'WebSite', 'name' => 'Site' ) ) ) );

		$this->assertSame( array(), rr_extract_business_facts_from_schema( 801 ) );
	}

	public function test_homepage_entity_signal_types_include_graph_and_snippet_schema(): void {
		$this->page( 900, 'home', 'https://example.test/' );
		$GLOBALS['_test_options']['page_on_front'] = 900;
		$this->stored(
			900,
			array( '@graph' => array( array( '@type' => 'Organization' ), array( '@type' => 'WebSite' ) ) )
		);
		$this->snippets( array( 'svc' => $this->jsonld_snippet( array( '@type' => 'Service' ), array( 'display_on' => 'home' ) ) ) );

		$signals = rr_aeo_compute_entity_signals();

		$this->assertEqualsCanonicalizing( array( 'Organization', 'WebSite', 'Service' ), $signals['homepage_schema_types'] );
	}
}

/**
 * JSON-encodes a fixture without depending on WordPress being loaded.
 *
 * @param array $data Fixture data.
 * @return string
 */
function wp_json_encode_for_tests( array $data ): string {
	return (string) json_encode( $data );
}
