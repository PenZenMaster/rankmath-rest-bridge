<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #4 — double <link rel="canonical"> emission.
 *
 * The plugin emits its canonical on wp_head:1 but never unhooked WP core's
 * rel_canonical (wp_head:10), so every singular page carried two canonical
 * tags. rr_emit_singular_canonical() must remove core's emitter exactly when
 * it writes a tag itself, and leave core's emitter in place on every bail
 * path so those pages still get a canonical.
 */
class CanonicalEmissionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_post_meta']          = array();
		$GLOBALS['_test_removed_actions']    = array();
		$GLOBALS['_test_is_singular']        = true;
		$GLOBALS['_test_queried_object_id']  = 12;

		$post              = new WP_Post();
		$post->ID          = 12;
		$post->post_type   = 'page';
		$post->post_status = 'publish';

		$GLOBALS['_test_posts']     = array( 12 => $post );
		$GLOBALS['_test_permalink'] = array( 12 => 'https://example.test/home/' );

		// Fresh simulated core emitter for every test.
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', 'rel_canonical' );
	}

	private function core_canonical_hooked(): bool {
		foreach ( $GLOBALS['_test_actions']['wp_head'] ?? array() as $entry ) {
			if ( 'rel_canonical' === $entry['callback'] ) {
				return true;
			}
		}
		return false;
	}

	private function run_emitter(): string {
		ob_start();
		rr_emit_singular_canonical();
		return (string) ob_get_clean();
	}

	// ── Emission path ─────────────────────────────────────────────────────────

	public function test_emits_single_tag_and_unhooks_core(): void {
		$out = $this->run_emitter();

		$this->assertSame( 1, substr_count( $out, 'rel="canonical"' ) );
		$this->assertStringContainsString( 'https://example.test/home/', $out );
		$this->assertFalse( $this->core_canonical_hooked() );
	}

	public function test_per_post_override_wins_over_permalink(): void {
		$GLOBALS['_test_post_meta'][12]['_rr_seo_canonical'] = 'https://example.test/preferred/';

		$out = $this->run_emitter();

		$this->assertStringContainsString( 'https://example.test/preferred/', $out );
		$this->assertStringNotContainsString( '/home/', $out );
		$this->assertFalse( $this->core_canonical_hooked() );
	}

	// ── Bail paths keep core's canonical ──────────────────────────────────────

	public function test_not_singular_bails_and_leaves_core_hooked(): void {
		$GLOBALS['_test_is_singular'] = false;

		$out = $this->run_emitter();

		$this->assertSame( '', $out );
		$this->assertTrue( $this->core_canonical_hooked() );
	}

	public function test_noindex_bails_and_leaves_core_hooked(): void {
		$robots_key = RR_SEO_META_KEYS['robots'];
		$GLOBALS['_test_post_meta'][12][ $robots_key ] = 'noindex,nofollow';

		$out = $this->run_emitter();

		$this->assertSame( '', $out );
		$this->assertTrue( $this->core_canonical_hooked() );
	}

	public function test_disallowed_post_type_bails_and_leaves_core_hooked(): void {
		$GLOBALS['_test_posts'][12]->post_type = 'attachment';

		$out = $this->run_emitter();

		$this->assertSame( '', $out );
		$this->assertTrue( $this->core_canonical_hooked() );
	}
}
