<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the llms.txt section classifier functions.
 *
 * Covers rr_path_matches_pattern(), rr_path_is_exact(),
 * rr_auto_classify_section(), rr_classify_url_section(),
 * rr_fallback_section(), and rr_validate_llms_section().
 */
class SectionClassifierTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_posts_list'] = array();
		$GLOBALS['_test_post_meta']  = array();
		$GLOBALS['_test_options']    = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_test_posts_list'],
			$GLOBALS['_test_post_meta'],
			$GLOBALS['_test_options']
		);
	}

	private function makePost( int $id, string $slug = 'test', string $type = 'page' ): WP_Post {
		$p                    = new WP_Post();
		$p->ID                = $id;
		$p->post_name         = $slug;
		$p->post_type         = $type;
		$p->post_status       = 'publish';
		$p->post_password     = '';
		$p->post_title        = 'Test Post ' . $id;
		$p->post_excerpt      = '';
		$p->post_content      = '';
		$p->post_modified_gmt = '2026-04-29 10:00:00';
		return $p;
	}

	// ── rr_path_matches_pattern() ─────────────────────────────────────────────

	public function test_prefix_match_matches_exact_directory(): void {
		$this->assertTrue( rr_path_matches_pattern( '/services/', '/services/' ) );
	}

	public function test_prefix_match_matches_child_path(): void {
		$this->assertTrue( rr_path_matches_pattern( '/services/residential/', '/services/' ) );
	}

	public function test_prefix_match_does_not_match_unrelated_path(): void {
		$this->assertFalse( rr_path_matches_pattern( '/blog/best-services/', '/services/' ) );
	}

	public function test_prefix_match_does_not_match_containing_path(): void {
		$this->assertFalse( rr_path_matches_pattern( '/about-our-services/', '/services/' ) );
	}

	public function test_prefix_match_stem_pattern_without_trailing_slash(): void {
		// Partial slug stems work because patterns are NOT trailing-slash normalised.
		$this->assertTrue( rr_path_matches_pattern( '/house-painter-near-me-in-ann-arbor/', '/house-painter-near-me-in-' ) );
	}

	public function test_prefix_match_is_case_insensitive(): void {
		$this->assertTrue( rr_path_matches_pattern( '/Services/residential/', '/services/' ) );
	}

	// ── rr_path_is_exact() ────────────────────────────────────────────────────

	public function test_exact_match_same_path(): void {
		$this->assertTrue( rr_path_is_exact( '/about/', '/about/' ) );
	}

	public function test_exact_match_normalises_trailing_slash(): void {
		$this->assertTrue( rr_path_is_exact( '/about/', '/about' ) );
	}

	public function test_exact_match_does_not_match_child(): void {
		$this->assertFalse( rr_path_is_exact( '/about/team/', '/about/' ) );
	}

	// ── rr_fallback_section() ─────────────────────────────────────────────────

	public function test_fallback_post_type_goes_to_educational_articles(): void {
		$post   = $this->makePost( 1, 'slug', 'post' );
		$result = rr_fallback_section( $post );
		$this->assertSame( 'educational_articles', $result['section'] );
		$this->assertSame( 'fallback', $result['method'] );
	}

	public function test_fallback_page_type_goes_to_core_business_pages(): void {
		$post   = $this->makePost( 2, 'about', 'page' );
		$result = rr_fallback_section( $post );
		$this->assertSame( 'core_business_pages', $result['section'] );
	}

	// ── rr_auto_classify_section() ────────────────────────────────────────────

	private function sampleSectionsConfig(): array {
		return array(
			'core_business_pages'  => array( 'label' => 'Core', 'order' => 1, 'exact_paths' => array( '/about/', '/contact/' ) ),
			'service_pages'        => array( 'label' => 'Services', 'order' => 2, 'url_patterns' => array( '/services/' ) ),
			'location_pages'       => array( 'label' => 'Locations', 'order' => 3, 'url_patterns' => array( '/locations/' ) ),
			'educational_articles' => array( 'label' => 'Articles', 'order' => 4, 'post_types' => array( 'post' ) ),
		);
	}

	public function test_exact_path_match_wins_over_prefix(): void {
		$post   = $this->makePost( 10, 'about', 'page' );
		$result = rr_auto_classify_section( $post, '/about/', $this->sampleSectionsConfig() );
		$this->assertSame( 'core_business_pages', $result['section'] );
		$this->assertSame( 'exact_paths', $result['method'] );
	}

	public function test_url_pattern_prefix_match(): void {
		$post   = $this->makePost( 11, 'residential', 'page' );
		$result = rr_auto_classify_section( $post, '/services/residential/', $this->sampleSectionsConfig() );
		$this->assertSame( 'service_pages', $result['section'] );
		$this->assertSame( 'url_patterns', $result['method'] );
	}

	public function test_post_type_match_for_posts(): void {
		$post   = $this->makePost( 12, 'article', 'post' );
		$result = rr_auto_classify_section( $post, '/article/', $this->sampleSectionsConfig() );
		$this->assertSame( 'educational_articles', $result['section'] );
		$this->assertSame( 'post_types', $result['method'] );
	}

	public function test_empty_config_falls_back_to_default(): void {
		$post   = $this->makePost( 13, 'random', 'page' );
		$result = rr_auto_classify_section( $post, '/random/', array() );
		$this->assertSame( 'core_business_pages', $result['section'] );
		$this->assertSame( 'fallback', $result['method'] );
	}

	public function test_lowest_order_wins_on_multiple_matches(): void {
		$config = array(
			'high_priority' => array( 'label' => 'HP', 'order' => 1, 'url_patterns' => array( '/services/' ) ),
			'low_priority'  => array( 'label' => 'LP', 'order' => 5, 'url_patterns' => array( '/services/' ) ),
		);
		$post   = $this->makePost( 14, 'test', 'page' );
		$result = rr_auto_classify_section( $post, '/services/test/', $config );
		$this->assertSame( 'high_priority', $result['section'] );
	}

	// ── rr_classify_url_section() — meta override ─────────────────────────────

	public function test_valid_meta_override_is_used(): void {
		$post = $this->makePost( 20, 'test', 'page' );
		$GLOBALS['_test_post_meta'][20][ META_LLMS_SECTION ] = 'service_pages';

		$result = rr_classify_url_section( $post, '/test/', $this->sampleSectionsConfig() );
		$this->assertSame( 'service_pages', $result['section'] );
		$this->assertSame( 'meta', $result['method'] );
		$this->assertEmpty( $result['warnings'] );
	}

	public function test_stale_meta_triggers_warning_and_auto_classify(): void {
		$post = $this->makePost( 21, 'test', 'post' );
		$GLOBALS['_test_post_meta'][21][ META_LLMS_SECTION ] = 'old_removed_section';

		$result = rr_classify_url_section( $post, '/test/', $this->sampleSectionsConfig() );
		$this->assertSame( 'old_removed_section', $result['section'] );
		$this->assertSame( 'educational_articles', $result['effective_section'] );
		$this->assertSame( 'meta_stale', $result['method'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertSame( 'stale_section_key', $result['warnings'][0]['code'] );
	}

	// ── rr_validate_llms_section() ────────────────────────────────────────────

	public function test_valid_section_key_returns_true(): void {
		$GLOBALS['_test_options'][ RR_LLMS_CONFIG_KEY ] = array(
			'sections' => $this->sampleSectionsConfig(),
		);
		$this->assertTrue( rr_validate_llms_section( 'service_pages' ) );
	}

	public function test_invalid_section_key_returns_wp_error(): void {
		$GLOBALS['_test_options'][ RR_LLMS_CONFIG_KEY ] = array(
			'sections' => $this->sampleSectionsConfig(),
		);
		$result = rr_validate_llms_section( 'nonexistent_section' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_empty_config_accepts_any_key(): void {
		// No sections configured — any value is valid.
		$this->assertTrue( rr_validate_llms_section( 'anything' ) );
	}
}
