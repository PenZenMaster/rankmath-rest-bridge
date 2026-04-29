<?php
/**
 * Module/Script Name: RRSEO_MetaBox
 * Path: includes/class-rrseo-metabox.php
 *
 * Description:
 * Registers a read-only sidebar meta box on Edit Post and Edit Page screens.
 * The PHP callback emits a shell div; metabox.js fetches the actual SEO meta,
 * schema type, and last 3 audit log entries via the REST API and injects the
 * rendered HTML into the div. No save_post hook is registered — the box is
 * intentionally read-only (the pipeline is the write path for SEO data).
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-04-29
 * Last Modified Date: 2026-04-29
 *
 * Comments:
 * v1.00 - Initial release. Read-only sidebar meta box for post/page edit screens.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders a read-only RankRocket SEO sidebar meta box.
 */
class RRSEO_MetaBox {

	/**
	 * Wires up WordPress admin hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Deliberately NO save_post hook — this meta box has no <input name="...">
		// elements, so nothing posts back to WordPress on save.
	}

	/**
	 * Registers the meta box on all allowed post types.
	 */
	public function register(): void {
		/**
		 * Filters the post types that show the RankRocket SEO meta box.
		 *
		 * @param string[] $post_types Default: ['post', 'page'].
		 */
		$post_types = apply_filters( 'rrseo_metabox_post_types', array( 'post', 'page' ) );

		add_meta_box(
			'rrseo-meta-readonly',
			__( 'RankRocket SEO', 'rankrocket-seo' ),
			array( $this, 'render' ),
			$post_types,
			'side',
			'high'
		);
	}

	/**
	 * Enqueues metabox.js on Edit Post / Edit Page screens only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen     = get_current_screen();
		$post_types = apply_filters( 'rrseo_metabox_post_types', array( 'post', 'page' ) );

		if ( ! $screen || ! in_array( $screen->post_type, $post_types, true ) ) {
			return;
		}

		wp_enqueue_script(
			'rrseo-metabox',
			plugins_url( 'includes/metabox.js', RMB_PLUGIN_FILE ),
			array( 'wp-api-fetch' ),
			RMB_VERSION,
			true
		);

		wp_localize_script(
			'rrseo-metabox',
			'rrSEOMeta',
			array(
				'root'       => esc_url_raw( rest_url() ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'titleMax'   => RR_TITLE_MAX,
				'titleWarnH' => RR_TITLE_WARN_MAX,
				'titleWarnL' => RR_TITLE_WARN_MIN,
				'descMax'    => RR_DESC_MAX,
				'descWarnH'  => RR_DESC_WARN_MAX,
				'descWarnL'  => RR_DESC_WARN_MIN,
			)
		);

		wp_enqueue_style(
			'rrseo-metabox',
			plugins_url( 'includes/admin.css', RMB_PLUGIN_FILE ),
			array(),
			RMB_VERSION
		);
	}

	/**
	 * Renders the meta box shell. metabox.js populates it after DOMContentLoaded.
	 *
	 * @param WP_Post $post The post being edited.
	 */
	public function render( WP_Post $post ): void {
		?>
		<div id="rrseo-meta-box" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<p class="rrseo-loading"><?php esc_html_e( 'Loading SEO data\xe2\x80\xa6', 'rankrocket-seo' ); ?></p>
		</div>
		<?php
	}
}
