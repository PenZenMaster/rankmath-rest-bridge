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
 * Last Modified Date: 2026-07-06
 *
 * Comments:
 * v1.00 - Initial release. Admin panel for viewing plugin data.
 * v1.01 - I18n: deactivation dialog strings wrapped; repaired literal \x
 *         escape sequences in Loading placeholders (ASCII ellipsis).
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
	 * Returns a white-label-aware page title for the browser tab and <h1> headings.
	 *
	 * @param string $sub_page Sub-page label, e.g. 'Overview'. Empty string returns the
	 *                         plugin name alone.
	 * @return string
	 */
	private function page_title( string $sub_page = '' ): string {
		$name = RRSEO_White_Label::wl_name();
		return '' === $sub_page ? $name : $name . ' - ' . $sub_page;
	}

	/**
	 * Wires up WordPress admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( $this, 'deactivation_warning_script' ) );
	}

	/**
	 * Registers the top-level menu and all sub-pages.
	 */
	public function register_menus(): void {
		$this->page_hooks['overview'] = add_menu_page(
			RRSEO_White_Label::wl_name(),
			RRSEO_White_Label::wl_name(),
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
			$this->page_title( __( 'Posts & Pages', 'rankrocket-seo' ) ),
			__( 'Posts & Pages', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo-posts',
			array( $this, 'render_posts' )
		);

		$this->page_hooks['images'] = add_submenu_page(
			'rankrocket-seo',
			$this->page_title( __( 'Image ALT Text', 'rankrocket-seo' ) ),
			__( 'Image ALT', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo-images',
			array( $this, 'render_images' )
		);

		$this->page_hooks['snippets'] = add_submenu_page(
			'rankrocket-seo',
			$this->page_title( __( 'Snippets', 'rankrocket-seo' ) ),
			__( 'Snippets', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo-snippets',
			array( $this, 'render_snippets' )
		);

		$this->page_hooks['llms'] = add_submenu_page(
			'rankrocket-seo',
			$this->page_title( __( 'llms.txt', 'rankrocket-seo' ) ),
			__( 'llms.txt', 'rankrocket-seo' ),
			'manage_options',
			'rankrocket-seo-llms',
			array( $this, 'render_llms' )
		);

		$this->page_hooks['sitemap'] = add_submenu_page(
			'rankrocket-seo',
			$this->page_title( __( 'Sitemap Preview', 'rankrocket-seo' ) ),
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
				'batch'      => rrseo_batch_max(),
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
			<h1><?php echo esc_html( $this->page_title( __( 'Overview', 'rankrocket-seo' ) ) ); ?></h1>
			<div id="rrseo-page-overview">
				<p><?php esc_html_e( 'Loading...', 'rankrocket-seo' ); ?></p>
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
			<h1><?php echo esc_html( $this->page_title( __( 'Posts & Pages', 'rankrocket-seo' ) ) ); ?></h1>
			<div id="rrseo-page-posts">
				<p><?php esc_html_e( 'Loading...', 'rankrocket-seo' ); ?></p>
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
			<h1><?php echo esc_html( $this->page_title( __( 'Image ALT Text', 'rankrocket-seo' ) ) ); ?></h1>
			<div id="rrseo-page-images">
				<p><?php esc_html_e( 'Loading...', 'rankrocket-seo' ); ?></p>
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
			<h1><?php echo esc_html( $this->page_title( __( 'Snippets', 'rankrocket-seo' ) ) ); ?></h1>
			<div id="rrseo-page-snippets">
				<p><?php esc_html_e( 'Loading...', 'rankrocket-seo' ); ?></p>
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
			<h1><?php echo esc_html( $this->page_title( __( 'llms.txt', 'rankrocket-seo' ) ) ); ?></h1>
			<div id="rrseo-page-llms">
				<p><?php esc_html_e( 'Loading...', 'rankrocket-seo' ); ?></p>
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
			<h1><?php echo esc_html( $this->page_title( __( 'Sitemap Preview', 'rankrocket-seo' ) ) ); ?></h1>
			<div id="rrseo-page-sitemap">
				<p><?php esc_html_e( 'Loading...', 'rankrocket-seo' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Injects a deactivation confirmation dialog on the Plugins screen.
	 *
	 * Intercepts clicks on the Deactivate link for this plugin and presents a
	 * native confirm() dialog listing which features will stop working. The
	 * physical robots.txt file is explicitly noted as persisting. The dialog
	 * does not block deactivation — it only ensures the admin is informed.
	 */
	public function deactivation_warning_script(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
		// When the plugin is hidden from the Plugins screen there is no row or
		// Deactivate link to intercept — skip the script entirely.
		if ( RRSEO_White_Label::wl_hidden() ) {
			return;
		}
		$plugin_slug    = esc_js( plugin_basename( RMB_PLUGIN_FILE ) );
		$plugin_name_js = wp_json_encode( RRSEO_White_Label::wl_name() );
		$stops_js       = wp_json_encode(
			array(
				'- ' . __( 'Schema JSON-LD injection into page <head>', 'rankrocket-seo' ),
				'- ' . __( 'Custom page title overrides', 'rankrocket-seo' ),
				'- ' . __( 'XML sitemap endpoint (/sitemap_index.xml)', 'rankrocket-seo' ),
				'- ' . __( 'llms.txt endpoint', 'rankrocket-seo' ),
			)
		);
		$persists_js    = wp_json_encode(
			array(
				'- ' . __( 'All SEO metadata (stored in post meta - rr_seo_* keys)', 'rankrocket-seo' ),
				'- ' . __( 'robots.txt (physical file at webroot - web server serves it directly)', 'rankrocket-seo' ),
			)
		);
		$labels_js      = wp_json_encode(
			array(
				'deactivating' => __( 'Deactivating', 'rankrocket-seo' ),
				'stops'        => __( 'Will STOP working:', 'rankrocket-seo' ),
				'persists'     => __( 'Will PERSIST after deactivation:', 'rankrocket-seo' ),
				'proceed'      => __( 'Proceed with deactivation?', 'rankrocket-seo' ),
			)
		);
		?>
		<script>
		( function () {
			'use strict';
			var row = document.querySelector( '[data-plugin="<?php echo $plugin_slug; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"]' );
			if ( ! row ) { return; }
			var link = row.querySelector( '.deactivate a' );
			if ( ! link ) { return; }
			link.addEventListener( 'click', function ( e ) {
				var stops    = <?php echo $stops_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
				var persists = <?php echo $persists_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
				var labels   = <?php echo $labels_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
				var msg = labels.deactivating + ' ' + <?php echo $plugin_name_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> + '\n\n'
					+ labels.stops + '\n' + stops.join( '\n' ) + '\n\n'
					+ labels.persists + '\n' + persists.join( '\n' ) + '\n\n'
					+ labels.proceed;
				if ( ! window.confirm( msg ) ) {
					e.preventDefault();
				}
			} );
		}() );
		</script>
		<?php
	}
}
