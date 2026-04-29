<?php
/**
 * Module/Script Name: RRSEO_Admin
 * Path: includes/class-rrseo-admin.php
 *
 * Description:
 * Registers the RankRocket SEO admin menu and all sub-pages. Each page emits a
 * container div that admin.js populates via wp.apiFetch calls against the existing
 * rankrocket-seo/v1 REST endpoints. No new server-side data handlers are added.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-04-29
 * Last Modified Date: 2026-04-29
 *
 * Comments:
 * v1.00 - Initial release. Admin panel for viewing plugin data.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the RankRocket SEO admin menu and all sub-pages.
 */
class RRSEO_Admin {

	/**
	 * Hook suffixes returned by add_menu_page / add_submenu_page.
	 * Used to scope enqueue to only RankRocket admin pages.
	 *
	 * @var array<string, string>
	 */
	private array $page_hooks = array();

	/**
	 * Wires up WordPress admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the top-level menu and all sub-pages.
	 */
	public function register_menus(): void {
		$this->page_hooks['overview'] = add_menu_page(
			__( 'RankRocket SEO', 'rankrocket-seo' ),
			__( 'RankRocket SEO', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo',
			array( $this, 'render_overview' ),
			'dashicons-chart-line',
			58
		);

		// The first submenu entry re-labels the auto-generated duplicate of the top-level item.
		add_submenu_page(
			'rankrocket-seo',
			__( 'Overview', 'rankrocket-seo' ),
			__( 'Overview', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo',
			array( $this, 'render_overview' )
		);

		$this->page_hooks['posts'] = add_submenu_page(
			'rankrocket-seo',
			__( 'Posts & Pages — RankRocket SEO', 'rankrocket-seo' ),
			__( 'Posts & Pages', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo-posts',
			array( $this, 'render_posts' )
		);

		$this->page_hooks['images'] = add_submenu_page(
			'rankrocket-seo',
			__( 'Image ALT Text — RankRocket SEO', 'rankrocket-seo' ),
			__( 'Image ALT', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo-images',
			array( $this, 'render_images' )
		);

		$this->page_hooks['snippets'] = add_submenu_page(
			'rankrocket-seo',
			__( 'Snippets — RankRocket SEO', 'rankrocket-seo' ),
			__( 'Snippets', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo-snippets',
			array( $this, 'render_snippets' )
		);

		$this->page_hooks['llms'] = add_submenu_page(
			'rankrocket-seo',
			__( 'llms.txt — RankRocket SEO', 'rankrocket-seo' ),
			__( 'llms.txt', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo-llms',
			array( $this, 'render_llms' )
		);

		$this->page_hooks['sitemap'] = add_submenu_page(
			'rankrocket-seo',
			__( 'Sitemap Preview — RankRocket SEO', 'rankrocket-seo' ),
			__( 'Sitemap', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo-sitemap',
			array( $this, 'render_sitemap' )
		);
	}

	/**
	 * Enqueues admin JS and CSS, scoped to RankRocket pages only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_script(
			'rrseo-admin',
			plugins_url( 'includes/admin.js', RMB_PLUGIN_FILE ),
			array( 'wp-api-fetch' ),
			RMB_VERSION,
			true
		);

		wp_localize_script(
			'rrseo-admin',
			'rrSEOAdmin',
			array(
				'root'       => esc_url_raw( rest_url() ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'version'    => RMB_VERSION,
				'batch'      => RR_BATCH_MAX,
				'titleMax'   => RR_TITLE_MAX,
				'titleWarnH' => RR_TITLE_WARN_MAX,
				'titleWarnL' => RR_TITLE_WARN_MIN,
				'descMax'    => RR_DESC_MAX,
				'descWarnH'  => RR_DESC_WARN_MAX,
				'descWarnL'  => RR_DESC_WARN_MIN,
			)
		);

		wp_enqueue_style(
			'rrseo-admin',
			plugins_url( 'includes/admin.css', RMB_PLUGIN_FILE ),
			array(),
			RMB_VERSION
		);
	}

	// ── Page render callbacks ─────────────────────────────────────────────────────
	// Each emits a .wrap container with a single <div id="rrseo-page-*"> that
	// admin.js detects and populates. No server-side data is fetched here.

	/**
	 * Renders the Overview page shell.
	 */
	public function render_overview(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RankRocket SEO — Overview', 'rankrocket-seo' ); ?></h1>
			<div id="rrseo-page-overview">
				<p><?php esc_html_e( 'Loading\xe2\x80\xa6', 'rankrocket-seo' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Posts & Pages page shell.
	 */
	public function render_posts(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RankRocket SEO — Posts & Pages', 'rankrocket-seo' ); ?></h1>
			<div id="rrseo-page-posts">
				<p><?php esc_html_e( 'Loading\xe2\x80\xa6', 'rankrocket-seo' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Image ALT Text page shell.
	 */
	public function render_images(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RankRocket SEO — Image ALT Text', 'rankrocket-seo' ); ?></h1>
			<div id="rrseo-page-images">
				<p><?php esc_html_e( 'Loading\xe2\x80\xa6', 'rankrocket-seo' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Snippets page shell.
	 */
	public function render_snippets(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RankRocket SEO — Snippets', 'rankrocket-seo' ); ?></h1>
			<div id="rrseo-page-snippets">
				<p><?php esc_html_e( 'Loading\xe2\x80\xa6', 'rankrocket-seo' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the llms.txt page shell.
	 */
	public function render_llms(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RankRocket SEO — llms.txt', 'rankrocket-seo' ); ?></h1>
			<div id="rrseo-page-llms">
				<p><?php esc_html_e( 'Loading\xe2\x80\xa6', 'rankrocket-seo' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Sitemap Preview page shell.
	 */
	public function render_sitemap(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RankRocket SEO — Sitemap Preview', 'rankrocket-seo' ); ?></h1>
			<div id="rrseo-page-sitemap">
				<p><?php esc_html_e( 'Loading\xe2\x80\xa6', 'rankrocket-seo' ); ?></p>
			</div>
		</div>
		<?php
	}
}
