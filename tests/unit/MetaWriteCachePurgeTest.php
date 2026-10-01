<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #28 -- GET /get/{id} returned stale SEO meta
 * right after a write.
 *
 * The write handlers busted WordPress's own object cache but never purged the
 * page-cache copy (LiteSpeed) of the GET /get/{id} REST response, so the edge
 * kept serving the pre-write body. Every SEO meta writer must now fire the
 * same REST-endpoint purge the other write paths already use.
 */
class MetaWriteCachePurgeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_test_posts']         = array();
		$GLOBALS['_test_post_meta']     = array();
		$GLOBALS['_test_fired_actions'] = array();
		$GLOBALS['_test_cache_deletes'] = array();

		$post     = new WP_Post();
		$post->ID = 121;
		$post->post_type = 'page';
		$GLOBALS['_test_posts'][121] = $post;
	}

	/**
	 * Returns the URLs passed to litespeed_purge_url during the test.
	 *
	 * @return string[]
	 */
	private function purged_urls(): array {
		$urls = array();
		foreach ( $GLOBALS['_test_fired_actions'] as $fired ) {
			if ( 'litespeed_purge_url' === $fired['hook'] ) {
				$urls[] = $fired['args'][0];
			}
		}
		return $urls;
	}

	public function test_update_purges_get_endpoint_for_the_written_post(): void {
		$result = rmb_update_meta(
			new WP_REST_Request(
				array(
					'post_id'       => 121,
					'focus_keyword' => 'smoke-test',
				)
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertContains(
			'https://example.test/wp-json/rankrocket-seo/v1/get/121',
			$this->purged_urls()
		);
	}

	public function test_update_with_only_unset_fields_still_purges(): void {
		$result = rmb_update_meta(
			new WP_REST_Request(
				array(
					'post_id'      => 121,
					'unset_fields' => array( 'focus_keyword' ),
				)
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertContains(
			'https://example.test/wp-json/rankrocket-seo/v1/get/121',
			$this->purged_urls()
		);
	}

	public function test_failed_validation_does_not_purge(): void {
		$result = rmb_update_meta(
			new WP_REST_Request(
				array(
					'post_id'       => 999,
					'focus_keyword' => 'x',
				)
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( array(), $this->purged_urls() );
	}

	public function test_bulk_update_purges_each_written_post_but_not_on_dry_run(): void {
		$updates = array(
			array(
				'post_id'       => 121,
				'focus_keyword' => 'bulk-test',
			),
		);

		rmb_meta_bulk_update( new WP_REST_Request( array( 'updates' => $updates, 'dry_run' => true ) ) );
		$this->assertSame( array(), $this->purged_urls() );

		rmb_meta_bulk_update( new WP_REST_Request( array( 'updates' => $updates, 'dry_run' => false ) ) );
		$this->assertContains(
			'https://example.test/wp-json/rankrocket-seo/v1/get/121',
			$this->purged_urls()
		);
	}
}
