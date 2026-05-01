<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Canonical URL Set helper functions.
 *
 * Covers rr_is_url_allowed_for_discovery(), rr_get_canonical_url_set(),
 * rr_normalize_url_path(), rr_has_noindex(), rr_check_numeric_suffix(),
 * rr_normalize_description_text(), and rr_truncate_description().
 *
 * Seed helpers:
 *   $GLOBALS['_test_posts_list'] — array of stdClass post objects for get_posts()
 *   $GLOBALS['_test_pages_list'] — array of stdClass page objects for get_pages()
 *   $GLOBALS['_test_post_meta']  — [post_id][meta_key] => value for get_post_meta()
 *   $GLOBALS['_test_permalink']  — [post_id] => full URL
 *   $GLOBALS['_test_options']    — [option_key] => value for get_option()
 */
class CanonicalUrlSetTest extends TestCase {

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

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Build a minimal published post stdClass.
	 */
	private function makePost( int $id, string $slug = 'test-post', string $type = 'post', string $status = 'publish' ): WP_Post {
		$p                    = new WP_Post();
		$p->ID                = $id;
		$p->post_name         = $slug;
		$p->post_type         = $type;
		$p->post_status       = $status;
		$p->post_password     = '';
		$p->post_title        = 'Test Post ' . $id;
		$p->post_excerpt      = '';
		$p->post_content      = '';
		$p->post_modified_gmt = '2026-04-29 12:00:00';
		return $p;
	}

	private function setPermalink( int $id, string $url ): void {
		$GLOBALS['_test_permalink'][ $id ] = $url;
	}

	// ── rr_has_noindex() ──────────────────────────────────────────────────────

	public function test_has_noindex_returns_false_for_empty(): void {
		$this->assertFalse( rr_has_noindex( '' ) );
		$this->assertFalse( rr_has_noindex( null ) );
		$this->assertFalse( rr_has_noindex( array() ) );
	}

	public function test_has_noindex_detects_string_noindex(): void {
		$this->assertTrue( rr_has_noindex( 'noindex' ) );
		$this->assertTrue( rr_has_noindex( 'noindex,nofollow' ) );
	}

	public function test_has_noindex_detects_array_noindex(): void {
		$this->assertTrue( rr_has_noindex( array( 'noindex', 'nofollow' ) ) );
	}

	public function test_has_noindex_returns_false_for_index_only(): void {
		$this->assertFalse( rr_has_noindex( 'index' ) );
		$this->assertFalse( rr_has_noindex( array( 'index', 'follow' ) ) );
	}

	// ── rr_normalize_url_path() ───────────────────────────────────────────────

	public function test_normalize_path_adds_leading_slash(): void {
		$this->assertSame( '/services/', rr_normalize_url_path( 'services/' ) );
	}

	public function test_normalize_path_adds_trailing_slash(): void {
		$this->assertSame( '/services/', rr_normalize_url_path( '/services' ) );
	}

	public function test_normalize_path_lowercases(): void {
		$this->assertSame( '/services/residential/', rr_normalize_url_path( '/Services/Residential/' ) );
	}

	public function test_normalize_path_root_stays_single_slash(): void {
		$this->assertSame( '/', rr_normalize_url_path( '/' ) );
	}

	// ── rr_check_numeric_suffix() ─────────────────────────────────────────────

	public function test_numeric_suffix_ok_for_normal_slug(): void {
		$post = $this->makePost( 1, 'about-us' );
		$this->assertSame( 'ok', rr_check_numeric_suffix( $post ) );
	}

	public function test_numeric_suffix_ok_for_number_in_middle(): void {
		// /top-10-tips/ — '10' is part of the real title, not a WP collision suffix.
		$post = $this->makePost( 2, 'top-10-tips' );
		// 'top-10' would need to resolve to an existing 'top-10' slug — not seeded.
		// 'top-10-tips' ends with '-tips' (non-integer) so regex doesn't match.
		$this->assertSame( 'ok', rr_check_numeric_suffix( $post ) );
	}

	public function test_numeric_suffix_duplicate_when_base_exists(): void {
		$base         = $this->makePost( 10, 'best-painter-ann-arbor', 'post' );
		$duplicate    = $this->makePost( 11, 'best-painter-ann-arbor-2', 'post' );
		$GLOBALS['_test_posts_list'] = array( $base );

		$this->assertSame( 'duplicate', rr_check_numeric_suffix( $duplicate ) );
	}

	public function test_numeric_suffix_orphan_when_base_absent(): void {
		$orphan = $this->makePost( 12, 'some-page-2', 'post' );
		// _test_posts_list is empty — no base 'some-page' exists.
		$this->assertSame( 'orphan', rr_check_numeric_suffix( $orphan ) );
	}

	// ── rr_is_url_allowed_for_discovery() ────────────────────────────────────

	public function test_published_post_without_noindex_is_allowed(): void {
		$post = $this->makePost( 20, 'published-page' );
		$this->setPermalink( 20, 'https://example.test/published-page/' );
		$result = rr_is_url_allowed_for_discovery( $post );
		$this->assertTrue( $result['allowed'] );
		$this->assertNull( $result['reason'] );
	}

	public function test_draft_post_is_excluded(): void {
		$post = $this->makePost( 21, 'draft-post', 'post', 'draft' );
		$result = rr_is_url_allowed_for_discovery( $post );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'not_published', $result['reason'] );
	}

	public function test_password_protected_post_is_excluded(): void {
		$post               = $this->makePost( 22, 'protected' );
		$post->post_password = 'secret123';
		$this->setPermalink( 22, 'https://example.test/protected/' );
		$result = rr_is_url_allowed_for_discovery( $post );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'password_protected', $result['reason'] );
	}

	public function test_noindex_post_is_excluded(): void {
		$post = $this->makePost( 23, 'noindex-page' );
		$this->setPermalink( 23, 'https://example.test/noindex-page/' );
		$GLOBALS['_test_post_meta'][23]['rr_seo_robots'] = 'noindex';
		$result = rr_is_url_allowed_for_discovery( $post );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'noindex', $result['reason'] );
	}

	public function test_utility_page_is_excluded(): void {
		$post = $this->makePost( 24, 'thank-you' );
		$this->setPermalink( 24, 'https://example.test/thank-you/' );
		$result = rr_is_url_allowed_for_discovery( $post );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'utility_page', $result['reason'] );
	}

	public function test_test_placeholder_slug_is_excluded(): void {
		$post = $this->makePost( 25, 'please-do-not-delete-this-page' );
		$this->setPermalink( 25, 'https://example.test/please-do-not-delete-this-page/' );
		$result = rr_is_url_allowed_for_discovery( $post );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'test_placeholder', $result['reason'] );
	}

	public function test_numeric_suffix_duplicate_is_excluded(): void {
		$base      = $this->makePost( 30, 'service-page', 'post' );
		$duplicate = $this->makePost( 31, 'service-page-2', 'post' );
		$this->setPermalink( 31, 'https://example.test/service-page-2/' );
		$GLOBALS['_test_posts_list'] = array( $base );

		$result = rr_is_url_allowed_for_discovery( $duplicate );
		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'duplicate_numeric_suffix', $result['reason'] );
	}

	public function test_orphan_numeric_suffix_is_allowed_with_warning(): void {
		$orphan = $this->makePost( 32, 'orphan-post-2', 'post' );
		$this->setPermalink( 32, 'https://example.test/orphan-post-2/' );
		// No base 'orphan-post' in _test_posts_list.
		$result = rr_is_url_allowed_for_discovery( $orphan );
		$this->assertTrue( $result['allowed'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertSame( 'orphan_numeric_suffix', $result['warnings'][0]['code'] );
	}

	// ── rr_get_canonical_url_set() ────────────────────────────────────────────

	public function test_canonical_set_includes_only_published_posts(): void {
		$published = $this->makePost( 40, 'good-post' );
		$draft     = $this->makePost( 41, 'draft-post', 'post', 'draft' );
		$this->setPermalink( 40, 'https://example.test/good-post/' );
		$GLOBALS['_test_posts_list'] = array( $published, $draft );

		$result = rr_get_canonical_url_set( array( 'post_types' => array( 'post' ) ) );
		$this->assertCount( 1, $result['urls'] );
		$this->assertSame( 40, $result['urls'][0]['post_id'] );
		$this->assertCount( 1, $result['excluded'] );
	}

	public function test_canonical_set_excludes_noindex_posts(): void {
		$visible = $this->makePost( 50, 'visible' );
		$noindex = $this->makePost( 51, 'hidden' );
		$this->setPermalink( 50, 'https://example.test/visible/' );
		$this->setPermalink( 51, 'https://example.test/hidden/' );
		$GLOBALS['_test_posts_list']           = array( $visible, $noindex );
		$GLOBALS['_test_post_meta'][51]['rr_seo_robots'] = 'noindex';

		$result = rr_get_canonical_url_set( array( 'post_types' => array( 'post' ) ) );
		$this->assertCount( 1, $result['urls'] );
		$this->assertSame( 'noindex', $result['excluded'][0]['reason'] );
	}

	public function test_canonical_set_excludes_utility_pages(): void {
		$normal  = $this->makePost( 60, 'services', 'page' );
		$utility = $this->makePost( 61, 'thank-you', 'page' );
		$this->setPermalink( 60, 'https://example.test/services/' );
		$this->setPermalink( 61, 'https://example.test/thank-you/' );
		$GLOBALS['_test_pages_list'] = array( $normal, $utility );

		$result = rr_get_canonical_url_set( array( 'post_types' => array( 'page' ) ) );
		$this->assertCount( 1, $result['urls'] );
		$this->assertSame( 'utility_page', $result['excluded'][0]['reason'] );
	}

	public function test_canonical_set_excludes_numeric_suffix_duplicates(): void {
		$base      = $this->makePost( 70, 'our-services' );
		$duplicate = $this->makePost( 71, 'our-services-2' );
		$this->setPermalink( 70, 'https://example.test/our-services/' );
		$this->setPermalink( 71, 'https://example.test/our-services-2/' );
		$GLOBALS['_test_posts_list'] = array( $base, $duplicate );

		$result = rr_get_canonical_url_set( array( 'post_types' => array( 'post' ) ) );
		// base included, duplicate excluded
		$this->assertCount( 1, $result['urls'] );
		$this->assertSame( 70, $result['urls'][0]['post_id'] );
		$this->assertSame( 'duplicate_numeric_suffix', $result['excluded'][0]['reason'] );
	}

	public function test_canonical_set_includes_orphan_suffix_with_site_warning(): void {
		$orphan = $this->makePost( 80, 'article-2' );
		$this->setPermalink( 80, 'https://example.test/article-2/' );
		$GLOBALS['_test_posts_list'] = array( $orphan );

		$result = rr_get_canonical_url_set( array( 'post_types' => array( 'post' ) ) );
		$this->assertCount( 1, $result['urls'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertSame( 'orphan_numeric_suffix', $result['warnings'][0]['code'] );
	}

	public function test_canonical_set_returns_correct_lastmod_utc(): void {
		$post                     = $this->makePost( 90, 'dated-post' );
		$post->post_modified_gmt  = '2026-04-15 10:30:00';
		$this->setPermalink( 90, 'https://example.test/dated-post/' );
		$GLOBALS['_test_posts_list'] = array( $post );

		$result = rr_get_canonical_url_set( array( 'post_types' => array( 'post' ) ) );
		$this->assertSame( '2026-04-15T10:30:00+00:00', $result['urls'][0]['lastmod'] );
	}

	// ── rr_normalize_description_text() ──────────────────────────────────────

	public function test_normalize_strips_html_tags(): void {
		$result = rr_normalize_description_text( '<p>Hello <strong>world</strong></p>' );
		$this->assertSame( 'Hello world', $result );
	}

	public function test_normalize_decodes_html_entities(): void {
		$result = rr_normalize_description_text( 'Homeowners &amp; businesses.' );
		$this->assertSame( 'Homeowners & businesses.', $result );
	}

	public function test_normalize_collapses_line_breaks_to_spaces(): void {
		$result = rr_normalize_description_text( "Line one\r\nLine two\nLine three" );
		$this->assertSame( 'Line one Line two Line three', $result );
	}

	public function test_normalize_collapses_repeated_whitespace(): void {
		$result = rr_normalize_description_text( 'Too   many    spaces' );
		$this->assertSame( 'Too many spaces', $result );
	}

	// ── rr_truncate_description() ─────────────────────────────────────────────

	public function test_truncate_leaves_short_text_unchanged(): void {
		$text = 'Short description.';
		$this->assertSame( $text, rr_truncate_description( $text, 240 ) );
	}

	public function test_truncate_cuts_at_word_boundary(): void {
		$text   = str_repeat( 'word ', 60 ); // 300 chars
		$result = rr_truncate_description( $text, 50 );
		$this->assertLessThanOrEqual( 53, strlen( $result ) ); // 50 + '...'
		$this->assertStringEndsWith( '...', $result );
		// Must end cleanly on a word — no mid-word cut.
		$this->assertStringNotContainsString( 'ord...', $result );
	}

	public function test_truncate_appends_ascii_ellipsis_not_unicode(): void {
		$text   = str_repeat( 'a ', 200 );
		$result = rr_truncate_description( $text, 30 );
		$this->assertStringEndsWith( '...', $result );
		$this->assertStringNotContainsString( "\xe2\x80\xa6", $result ); // UTF-8 ellipsis
	}

	// ── rr_get_discovery_description() — first-paragraph fallback ────────────────

	/**
	 * Regression test for the first-paragraph fallback bug:
	 * rr_normalize_description_text() collapses whitespace before paragraph splitting,
	 * so splitting on double-space after normalization always returns one element.
	 * The fix splits on paragraph boundaries in the RAW content first.
	 */
	public function test_first_paragraph_uses_content_when_no_seo_desc_or_excerpt(): void {
		$post              = $this->makePost( 200, 'content-post' );
		$post->post_content = "First paragraph with enough useful text here.\n\nSecond paragraph that should not be used.";
		$post->post_excerpt = '';

		$result = rr_get_discovery_description( $post );
		$this->assertSame( 'first_paragraph', $result['source'] );
		$this->assertStringContainsString( 'First paragraph', $result['description'] );
		$this->assertStringNotContainsString( 'Second', $result['description'] );
		$this->assertNull( $result['warning'] );
	}

	public function test_first_paragraph_works_with_html_paragraph_tags(): void {
		$post              = $this->makePost( 201, 'html-post' );
		$post->post_content = '<p>First paragraph from block editor content.</p><p>Second block content here.</p>';
		$post->post_excerpt = '';

		$result = rr_get_discovery_description( $post );
		$this->assertSame( 'first_paragraph', $result['source'] );
		$this->assertStringContainsString( 'First paragraph', $result['description'] );
		$this->assertNull( $result['warning'] );
	}

	public function test_thin_description_warning_when_content_is_empty(): void {
		$post               = $this->makePost( 202, 'empty-post' );
		$post->post_content = '';
		$post->post_excerpt = '';
		$post->post_title   = 'My Page Title';

		$result = rr_get_discovery_description( $post );
		$this->assertSame( 'title', $result['source'] );
		$this->assertSame( 'thin_description', $result['warning'] );
	}

	public function test_seo_description_takes_priority_over_content(): void {
		$post              = $this->makePost( 203, 'full-post' );
		$post->post_content = "Long content that should not be used.\n\nSecond paragraph.";
		$post->post_excerpt = 'Excerpt should also not be used.';
		$GLOBALS['_test_post_meta'][203]['rr_seo_description'] = 'Explicit SEO description wins.';

		$result = rr_get_discovery_description( $post );
		$this->assertSame( 'rrseo_description', $result['source'] );
		$this->assertSame( 'Explicit SEO description wins.', $result['description'] );
	}

	public function test_excerpt_takes_priority_over_content(): void {
		$post              = $this->makePost( 204, 'excerpt-post' );
		$post->post_content = "Content paragraph that should not be used.\n\nSecond.";
		$post->post_excerpt = 'This excerpt should be used instead of content.';

		$result = rr_get_discovery_description( $post );
		$this->assertSame( 'excerpt', $result['source'] );
		$this->assertStringContainsString( 'excerpt should be used', $result['description'] );
	}
}
