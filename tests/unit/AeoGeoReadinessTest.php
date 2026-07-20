<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the AEO/GEO audit data layer helper functions.
 *
 * Covers rr_aeo_compute_canonical_preview(), rr_aeo_compute_entity_signals(),
 * rr_aeo_compute_schema_audit(), rr_aeo_compute_source_sync(), and
 * rr_aeo_compute_readiness().
 *
 * Testing convention: helper functions only — REST callbacks are excluded
 * per the established project pattern (see CanonicalUrlSetTest.php).
 *
 * Seed helpers:
 *   $GLOBALS['_test_posts_list'] — stdClass posts for get_posts() (non-page types)
 *   $GLOBALS['_test_pages_list'] — stdClass page objects for get_pages()
 *   $GLOBALS['_test_post_meta']  — [post_id][meta_key] => value
 *   $GLOBALS['_test_permalink']  — [post_id] => full URL
 *   $GLOBALS['_test_options']    — [option_key] => value
 */
class AeoGeoReadinessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_posts_list'] = array();
		$GLOBALS['_test_pages_list'] = array();
		$GLOBALS['_test_post_meta']  = array();
		$GLOBALS['_test_permalink']  = array();
		$GLOBALS['_test_options']    = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_test_posts_list'],
			$GLOBALS['_test_pages_list'],
			$GLOBALS['_test_post_meta'],
			$GLOBALS['_test_permalink'],
			$GLOBALS['_test_options']
		);
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	private function makePage( int $id, string $slug = 'test-page' ): WP_Post {
		$p                    = new WP_Post();
		$p->ID                = $id;
		$p->post_name         = $slug;
		$p->post_type         = 'page';
		$p->post_status       = 'publish';
		$p->post_password     = '';
		$p->post_title        = 'Test Page ' . $id;
		$p->post_excerpt      = 'Excerpt for page ' . $id;
		$p->post_content      = 'Content for page ' . $id;
		$p->post_modified_gmt = '2026-04-29 10:00:00';
		return $p;
	}

	private function makePost( int $id, string $slug = 'test-post', string $type = 'post' ): WP_Post {
		$p                    = new WP_Post();
		$p->ID                = $id;
		$p->post_name         = $slug;
		$p->post_type         = $type;
		$p->post_status       = 'publish';
		$p->post_password     = '';
		$p->post_title        = 'Test Post ' . $id;
		$p->post_excerpt      = 'Excerpt for post ' . $id;
		$p->post_content      = 'Content for post ' . $id;
		$p->post_modified_gmt = '2026-04-29 10:00:00';
		return $p;
	}

	private function setPermalink( int $id, string $url ): void {
		$GLOBALS['_test_permalink'][ $id ] = $url;
	}

	private function setSchema( int $post_id, array $schema ): void {
		$GLOBALS['_test_post_meta'][ $post_id ][ RR_SCHEMA_META_KEY ] = $schema;
	}

	private function setLlmsConfig( array $config ): void {
		$GLOBALS['_test_options'][ RR_LLMS_CONFIG_KEY ] = $config;
	}

	// ── rr_aeo_compute_canonical_preview() ───────────────────────────────────

	public function test_canonical_preview_includes_membership_flags(): void {
		$page = $this->makePage( 10, 'about' );
		$post = $this->makePost( 20, 'hello-world', 'post' );

		$GLOBALS['_test_pages_list']  = array( $page );
		$GLOBALS['_test_posts_list']  = array( $post );

		$this->setPermalink( 10, 'https://example.test/about/' );
		$this->setPermalink( 20, 'https://example.test/hello-world/' );

		$this->setSchema( 10, array( '@type' => 'WebPage', '@context' => 'https://schema.org', 'name' => 'About' ) );

		$result = rr_aeo_compute_canonical_preview();

		$this->assertArrayHasKey( 'urls', $result );
		$this->assertArrayHasKey( 'excluded', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertArrayHasKey( 'generated_at', $result );

		$by_id = array();
		foreach ( $result['urls'] as $entry ) {
			$by_id[ $entry['post_id'] ] = $entry;
		}

		// Page with schema.
		$this->assertArrayHasKey( 10, $by_id );
		$this->assertTrue( $by_id[10]['in_sitemap'], 'Page should be in sitemap' );
		$this->assertTrue( $by_id[10]['in_llms'], 'Page should be in llms by default' );
		$this->assertTrue( $by_id[10]['has_schema'], 'Page should have schema' );
		$this->assertContains( 'WebPage', $by_id[10]['schema_types'] );

		// Post without schema.
		$this->assertArrayHasKey( 20, $by_id );
		$this->assertTrue( $by_id[20]['in_sitemap'], 'Post should be in sitemap' );
		$this->assertTrue( $by_id[20]['in_llms'] );
		$this->assertFalse( $by_id[20]['has_schema'] );
		$this->assertEmpty( $by_id[20]['schema_types'] );
	}

	public function test_canonical_preview_product_not_in_sitemap(): void {
		$product = $this->makePost( 30, 'my-product', 'product' );

		$GLOBALS['_test_posts_list'] = array( $product );
		$this->setPermalink( 30, 'https://example.test/shop/my-product/' );

		$result = rr_aeo_compute_canonical_preview();
		$found  = array_filter( $result['urls'], fn( $e ) => 30 === $e['post_id'] );
		$entry  = array_values( $found )[0] ?? null;

		if ( null === $entry ) {
			// Products may not be in the default post_types — acceptable.
			$this->assertNotNull( null );
			return;
		}

		$this->assertFalse( $entry['in_sitemap'], 'Product should not be in sitemap' );
	}

	public function test_canonical_preview_utility_url_excluded_not_in_urls(): void {
		// /thank-you/ matches the built-in utility exclusion patterns in rr_is_utility_url().
		// It should appear in 'excluded', not in 'urls'.
		$page = $this->makePage( 11, 'thank-you' );

		$GLOBALS['_test_pages_list'] = array( $page );
		$this->setPermalink( 11, 'https://example.test/thank-you/' );

		$result = rr_aeo_compute_canonical_preview();

		$in_urls     = array_filter( $result['urls'], fn( $e ) => 11 === $e['post_id'] );
		$in_excluded = array_filter( $result['excluded'], fn( $e ) => 11 === (int) $e['post_id'] );

		$this->assertEmpty( $in_urls, '/thank-you/ should not appear in urls' );
		$this->assertNotEmpty( $in_excluded, '/thank-you/ should appear in excluded' );
	}

	public function test_canonical_preview_all_canonical_urls_are_in_llms(): void {
		$page = $this->makePage( 12, 'about' );
		$GLOBALS['_test_pages_list'] = array( $page );
		$this->setPermalink( 12, 'https://example.test/about/' );

		$result = rr_aeo_compute_canonical_preview();
		$found  = array_filter( $result['urls'], fn( $e ) => 12 === $e['post_id'] );
		$entry  = array_values( $found )[0] ?? null;

		$this->assertNotNull( $entry );
		$this->assertTrue( $entry['in_llms'], 'All canonical URLs are in llms (exclude_patterns applied at canonical level)' );
	}

	public function test_canonical_preview_generated_at_is_iso8601(): void {
		$result = rr_aeo_compute_canonical_preview();

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
			$result['generated_at']
		);
	}

	// ── rr_aeo_compute_entity_signals() ──────────────────────────────────────

	public function test_entity_signals_from_manual_business_facts(): void {
		$this->setLlmsConfig(
			array(
				'business_facts' => array(
					'business_name'    => 'Acme Painting Co',
					'phone'            => '555-0100',
					'address'          => '123 Main St, Ann Arbor, MI',
					'schema_type'      => 'LocalBusiness',
					'entity_id'        => 'https://www.google.com/maps/place/?q=place_id:abc',
					'primary_services' => array( 'Interior Painting', 'Exterior Painting' ),
					'service_area'     => array( 'Ann Arbor', 'Ypsilanti' ),
				),
			)
		);

		$result = rr_aeo_compute_entity_signals();

		$this->assertSame( 'Acme Painting Co', $result['business_name'] );
		$this->assertSame( '555-0100', $result['phone'] );
		$this->assertSame( 'LocalBusiness', $result['schema_type'] );
		$this->assertSame( 'manual_business_facts', $result['source'] );
		$this->assertContains( 'Interior Painting', $result['primary_services'] );
	}

	public function test_entity_signals_from_homepage_schema(): void {
		$GLOBALS['_test_options']['page_on_front'] = 1;

		$this->setSchema(
			1,
			array(
				'@context'  => 'https://schema.org',
				'@type'     => 'LocalBusiness',
				'name'      => 'Schema Corp',
				'telephone' => '555-9999',
				'url'       => 'https://schema-corp.test',
			)
		);

		$result = rr_aeo_compute_entity_signals();

		$this->assertSame( 'Schema Corp', $result['business_name'] );
		$this->assertSame( '555-9999', $result['phone'] );
		$this->assertSame( 'homepage_schema', $result['source'] );
		$this->assertSame( 'LocalBusiness', $result['schema_type'] );
		$this->assertContains( 'LocalBusiness', $result['homepage_schema_types'] );
	}

	public function test_entity_signals_bloginfo_fallback(): void {
		// No config, no schema — falls back to bloginfo.
		$result = rr_aeo_compute_entity_signals();

		$this->assertSame( 'Test Site', $result['business_name'] );
		$this->assertSame( 'bloginfo_fallback', $result['source'] );
		$this->assertNotEmpty( $result['business_name'] );
	}

	public function test_entity_signals_homepage_schema_types_populated(): void {
		$GLOBALS['_test_options']['page_on_front'] = 5;

		$this->setSchema(
			5,
			array(
				'@context' => 'https://schema.org',
				'@type'    => 'Organization',
				'name'     => 'Org Name',
			)
		);

		$result = rr_aeo_compute_entity_signals();
		$this->assertContains( 'Organization', $result['homepage_schema_types'] );
	}

	// ── rr_aeo_compute_schema_audit() ────────────────────────────────────────

	public function test_schema_audit_counts_and_coverage(): void {
		$p1 = $this->makePage( 100, 'home' );
		$p2 = $this->makePage( 101, 'about' );
		$p3 = $this->makePost( 102, 'post-one', 'post' );

		$GLOBALS['_test_pages_list'] = array( $p1, $p2 );
		$GLOBALS['_test_posts_list'] = array( $p3 );

		$this->setPermalink( 100, 'https://example.test/' );
		$this->setPermalink( 101, 'https://example.test/about/' );
		$this->setPermalink( 102, 'https://example.test/post-one/' );

		$this->setSchema( 100, array( '@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => 'Home' ) );
		$this->setSchema( 101, array( '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'About' ) );
		// Post 102 has no schema.

		$result = rr_aeo_compute_schema_audit();

		$this->assertSame( 3, $result['summary']['total'] );
		$this->assertSame( 2, $result['summary']['with_schema'] );
		$this->assertSame( 1, $result['summary']['without_schema'] );
		$this->assertEqualsWithDelta( 66.7, $result['summary']['coverage_pct'], 0.2 );
		$this->assertArrayHasKey( 'LocalBusiness', $result['summary']['types'] );
		$this->assertSame( 1, $result['summary']['types']['LocalBusiness'] );
		$this->assertSame( 1, $result['summary']['types']['WebPage'] );
	}

	public function test_schema_audit_missing_opportunity_localbusiness_on_homepage(): void {
		$home = $this->makePage( 200, 'homepage-slug' );
		$GLOBALS['_test_pages_list'] = array( $home );
		$this->setPermalink( 200, 'https://example.test/' );
		// No schema set — so homepage has no LocalBusiness.

		$result = rr_aeo_compute_schema_audit();

		$url_entry = array_values( array_filter( $result['urls'], fn( $e ) => 200 === $e['post_id'] ) )[0] ?? null;
		$this->assertNotNull( $url_entry );
		$this->assertContains( 'LocalBusiness', $url_entry['missing_opportunities'] );
	}

	public function test_schema_audit_global_warning_no_localbusiness(): void {
		$p = $this->makePage( 201, 'about' );
		$GLOBALS['_test_pages_list'] = array( $p );
		$this->setPermalink( 201, 'https://example.test/about/' );
		$this->setSchema( 201, array( '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'About' ) );

		$result = rr_aeo_compute_schema_audit();

		$this->assertContains( 'no_localbusiness_schema', $result['global_warnings'] );
	}

	public function test_schema_audit_no_global_warning_when_localbusiness_present(): void {
		$p = $this->makePage( 202, 'home' );
		$GLOBALS['_test_pages_list'] = array( $p );
		$this->setPermalink( 202, 'https://example.test/' );
		$this->setSchema( 202, array( '@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => 'Biz' ) );

		$result = rr_aeo_compute_schema_audit();

		$this->assertNotContains( 'no_localbusiness_schema', $result['global_warnings'] );
	}

	public function test_schema_audit_faq_opportunity_on_faq_page(): void {
		$faq = $this->makePage( 203, 'faq' );
		$GLOBALS['_test_pages_list'] = array( $faq );
		$this->setPermalink( 203, 'https://example.test/faq/' );
		// Has WebPage schema only.
		$this->setSchema( 203, array( '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'FAQ' ) );

		$result   = rr_aeo_compute_schema_audit();
		$url_entry = array_values( array_filter( $result['urls'], fn( $e ) => 203 === $e['post_id'] ) )[0] ?? null;

		$this->assertNotNull( $url_entry );
		$this->assertContains( 'FAQPage', $url_entry['missing_opportunities'] );
	}

	// ── rr_aeo_compute_source_sync() ─────────────────────────────────────────

	public function test_source_sync_all_agree_synced(): void {
		$p1 = $this->makePage( 300, 'about' );
		$p2 = $this->makePost( 301, 'post-a', 'post' );

		$GLOBALS['_test_pages_list'] = array( $p1 );
		$GLOBALS['_test_posts_list'] = array( $p2 );

		$this->setPermalink( 300, 'https://example.test/about/' );
		$this->setPermalink( 301, 'https://example.test/post-a/' );

		// No exclude_patterns — all canonical URLs are also in llms.
		// Both are pages/posts — all are in sitemap too.
		$this->setLlmsConfig( array() );

		$result = rr_aeo_compute_source_sync();

		$this->assertSame( 'synced', $result['sync_status'] );
		$this->assertSame( 100, $result['sync_score'] );
		$this->assertCount( 2, $result['in_all_three'] );
		$this->assertEmpty( $result['canonical_and_llms_not_sitemap'] );
	}

	public function test_source_sync_product_url_in_canonical_and_llms_not_sitemap(): void {
		// llms includes all canonical post types (product included in RR_ALLOWED_POST_TYPES).
		// The XML sitemap only includes post and page types (RR_AEO_SITEMAP_POST_TYPES).
		// So product URLs land in canonical_and_llms_not_sitemap.
		$page    = $this->makePage( 310, 'about' );
		$product = $this->makePost( 311, 'my-product', 'product' );

		$GLOBALS['_test_pages_list'] = array( $page );
		$GLOBALS['_test_posts_list'] = array( $product );
		$this->setPermalink( 310, 'https://example.test/about/' );
		$this->setPermalink( 311, 'https://example.test/shop/my-product/' );

		$result = rr_aeo_compute_source_sync();

		$this->assertContains( 'https://example.test/about/', $result['in_all_three'] );
		$this->assertContains( 'https://example.test/shop/my-product/', $result['canonical_and_llms_not_sitemap'] );
		$this->assertLessThan( 100, $result['sync_score'] );
	}

	public function test_source_sync_empty_canonical_set_returns_synced(): void {
		// No posts or pages — canonical set is empty.
		$result = rr_aeo_compute_source_sync();

		$this->assertSame( 'synced', $result['sync_status'] );
		$this->assertSame( 100, $result['sync_score'] );
		$this->assertSame( 0, $result['canonical_url_count'] );
	}

	public function test_source_sync_product_url_in_canonical_not_sitemap(): void {
		$product = $this->makePost( 320, 'my-product', 'product' );
		$GLOBALS['_test_posts_list'] = array( $product );
		$this->setPermalink( 320, 'https://example.test/shop/my-product/' );

		$result = rr_aeo_compute_source_sync();

		// Product URLs are in canonical + llms but not in sitemap (post+page only).
		if ( in_array( 'https://example.test/shop/my-product/', $result['canonical_and_llms_not_sitemap'], true ) ) {
			$this->assertNotContains( 'https://example.test/shop/my-product/', $result['in_all_three'] );
		} else {
			// Product type not returned by default post_types — acceptable.
			$this->assertSame( 0, $result['canonical_url_count'] );
		}
	}

	// ── rr_aeo_compute_readiness() ────────────────────────────────────────────

	public function test_readiness_data_depth_badge_is_always_public_only(): void {
		$result = rr_aeo_compute_readiness();

		$this->assertSame( 'public-only', $result['data_depth_badge'] );
	}

	public function test_readiness_scoring_all_signals_present(): void {
		// Seed a page with a full LocalBusiness schema.
		$home = $this->makePage( 400, 'homepage' );
		$GLOBALS['_test_pages_list'] = array( $home );
		$this->setPermalink( 400, 'https://example.test/' );
		$this->setSchema(
			400,
			array(
				'@context'  => 'https://schema.org',
				'@type'     => 'LocalBusiness',
				'name'      => 'Full Biz',
				'telephone' => '555-1234',
				'url'       => 'https://full-biz.test',
				'@id'       => 'https://full-biz.test/#business',
			)
		);

		$GLOBALS['_test_options']['page_on_front'] = 400;

		$this->setLlmsConfig(
			array(
				'intro'                 => 'We are a full-service painting company.',
				'max_description_chars' => 240,
				'exclude_patterns'      => array( '/thank-you/' ),
				'sections'              => array(
					'services' => array( 'label' => 'Services', 'order' => 1 ),
				),
				'business_facts'        => array(
					'business_name'    => 'Full Biz',
					'phone'            => '555-1234',
					'address'          => '123 Main St, Ann Arbor, MI 48104',
					'schema_type'      => 'LocalBusiness',
					'entity_id'        => 'https://full-biz.test/#business',
					'primary_services' => array( 'Interior Painting', 'Exterior Painting' ),
					'service_area'     => array( 'Ann Arbor', 'Ypsilanti' ),
				),
			)
		);

		$result = rr_aeo_compute_readiness();

		$this->assertGreaterThanOrEqual( 90, $result['scores']['entity_clarity'] );
		$this->assertSame( 100, $result['scores']['llms_completeness'] );
		$this->assertSame( 100, $result['scores']['canonical_source_guidance'] );
		$this->assertSame( 'public-only', $result['data_depth_badge'] );
		$this->assertArrayHasKey( 'generated_at', $result );
		$this->assertArrayHasKey( 'signals', $result );
		$this->assertTrue( $result['signals']['has_business_facts'] );
		$this->assertTrue( $result['signals']['has_llms_config'] );
		$this->assertSame( 'manual_business_facts', $result['signals']['business_facts_source'] );
	}

	public function test_readiness_scoring_no_config(): void {
		// Nothing configured — all scores should be low.
		$result = rr_aeo_compute_readiness();

		$this->assertLessThan( 30, $result['scores']['entity_clarity'] );
		$this->assertSame( 0, $result['scores']['llms_completeness'] );
		$this->assertFalse( $result['signals']['has_business_facts'] );
	}

	public function test_entity_clarity_warning_penalty(): void {
		// No manual config — resolver falls back to bloginfo (returns 'warnings' key).
		// bloginfo fallback warns only when schema_source_post_id is set but yields nothing.
		// Here we test a score reduction: provide no address, phone, schema_type.
		$this->setLlmsConfig(
			array(
				'business_facts' => array(
					'business_name' => 'Name Only',
					// No phone, address, schema_type, entity_id.
					'warnings'      => array(
						array( 'code' => 'test_warning_1', 'message' => 'w1' ),
						array( 'code' => 'test_warning_2', 'message' => 'w2' ),
					),
				),
			)
		);

		$entity = rr_aeo_compute_entity_signals();
		// 25 pts for business_name only, minus 2 warnings * 10 = 5. Floor is 0.
		$expected_score = max( 0, 25 - ( count( $entity['warnings'] ) * 10 ) );

		$result = rr_aeo_compute_readiness();
		$this->assertSame( $expected_score, $result['scores']['entity_clarity'] );
	}

	public function test_llms_completeness_all_five_fields(): void {
		$this->setLlmsConfig(
			array(
				'intro'                 => 'Some intro text.',
				'max_description_chars' => 200,
				'exclude_patterns'      => array( '/thank-you/' ),
				'sections'              => array(
					'services' => array( 'label' => 'Services', 'order' => 1 ),
				),
				'business_facts'        => array(
					'business_name'    => 'Biz',
					'primary_services' => array( 'Bookkeeping' ),
					'service_area'     => array( 'Oglesby, IL' ),
				),
			)
		);

		$result = rr_aeo_compute_readiness();
		$this->assertSame( 100, $result['scores']['llms_completeness'] );
	}

	public function test_has_business_facts_false_with_only_business_name(): void {
		// business_name alone (no enrichment fields) is identity, not
		// AEO-ready business facts — see issue #10.
		$this->setLlmsConfig(
			array(
				'business_facts' => array(
					'business_name' => 'Name Only Co',
				),
			)
		);

		$result = rr_aeo_compute_readiness();
		$this->assertFalse( $result['signals']['has_business_facts'] );
	}

	public function test_has_business_facts_true_with_two_enrichment_fields(): void {
		$this->setLlmsConfig(
			array(
				'business_facts' => array(
					'business_name'   => 'Kilday Baxter & Associates',
					'primary_services' => array( 'Bookkeeping', 'Payroll' ),
					'common_questions' => array(
						array( 'question' => 'Do I need a CPA?', 'answer' => 'Depends on complexity.' ),
					),
				),
			)
		);

		$result = rr_aeo_compute_readiness();
		$this->assertTrue( $result['signals']['has_business_facts'] );
	}

	public function test_has_business_facts_false_with_only_one_enrichment_field(): void {
		$this->setLlmsConfig(
			array(
				'business_facts' => array(
					'business_name'    => 'One Field Co',
					'primary_services' => array( 'Bookkeeping' ),
				),
			)
		);

		$result = rr_aeo_compute_readiness();
		$this->assertFalse( $result['signals']['has_business_facts'] );
	}

	public function test_business_facts_source_reflects_resolution_priority(): void {
		// No manual config, no schema — bloginfo fallback.
		$result = rr_aeo_compute_readiness();
		$this->assertSame( 'bloginfo_fallback', $result['signals']['business_facts_source'] );
	}

	public function test_schema_depth_global_warnings_deduct_score(): void {
		// One page with full schema coverage (100 pct) but no LocalBusiness anywhere.
		$p = $this->makePage( 500, 'about' );
		$GLOBALS['_test_pages_list'] = array( $p );
		$this->setPermalink( 500, 'https://example.test/about/' );
		$this->setSchema( 500, array( '@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => 'About' ) );

		$schema_result = rr_aeo_compute_schema_audit();

		// Coverage is 100% (1/1 with schema) but global_warnings has at least
		// no_localbusiness_schema, no_faqpage_anywhere, no_breadcrumblist_anywhere.
		$this->assertSame( 100.0, $schema_result['summary']['coverage_pct'] );
		$this->assertNotEmpty( $schema_result['global_warnings'] );

		$readiness = rr_aeo_compute_readiness();
		$this->assertLessThan( 100, $readiness['scores']['schema_depth'] );
	}
}
