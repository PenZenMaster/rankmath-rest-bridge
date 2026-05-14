<?php
/**
 * Module/Script Name: RRSEO_White_Label
 * Path: includes/class-rrseo-white-label.php
 *
 * Description:
 * White-label support for the RankRocket SEO Control Layer. All configuration
 * is via wp-config.php constants — nothing stored in the database and no UI
 * setting can override a defined constant.
 *
 * Supported constants (all optional, define in wp-config.php):
 *   RRSEO_WL_NAME          string  Display name in Plugins list and admin menu.
 *   RRSEO_WL_DESCRIPTION   string  Plugin description in Plugins list.
 *   RRSEO_WL_AUTHOR        string  Author name in Plugins list.
 *   RRSEO_WL_AUTHOR_URL    string  Author URL in Plugins list.
 *   RRSEO_WL_SUPPORT_URL   string  URL for the Support link in the plugin row.
 *   RRSEO_WL_HIDE_PLUGIN   bool    true = remove entry from Plugins screen entirely.
 *
 * Author(s):
 * Rank Rocket Co (C) Copyright 2026 - All Rights Reserved
 *
 * Created Date: 2026-05-10
 * Last Modified Date: 2026-05-12
 *
 * Comments:
 * v1.01 - Tier 2: suppress PUC update row and details modal on Dashboard > Updates.
 * v1.00 - Initial release. Tier 1 (rename) and Tier 2 (hide) white-label support.
 *
 * @package RankRocket_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles white-label filtering of the WordPress Plugins screen.
 *
 * Tier 1 (Rename): swap plugin name, description, author, and support link.
 * Tier 2 (Hide):   remove the plugin entry from the Plugins screen and suppress
 *                  its update row on Dashboard > Updates via PUC filters.
 *
 * Static helpers wl_name() and wl_hidden() are the public API for other
 * admin classes that need to read the white-label config.
 */
class RRSEO_White_Label {

	/**
	 * Plugin basename, e.g. rankmath-rest-bridge/rankmath-rest-bridge.php.
	 *
	 * @var string
	 */
	private string $basename;

	// ── Static helpers ────────────────────────────────────────────────────────────

	/**
	 * Returns the display name for the admin UI.
	 *
	 * @return string
	 */
	public static function wl_name(): string {
		return defined( 'RRSEO_WL_NAME' ) ? (string) RRSEO_WL_NAME : 'RankRocket SEO';
	}

	/**
	 * Returns true when the plugin should be hidden from the Plugins screen.
	 *
	 * @return bool
	 */
	public static function wl_hidden(): bool {
		return defined( 'RRSEO_WL_HIDE_PLUGIN' ) && true === RRSEO_WL_HIDE_PLUGIN;
	}

	// ── Instance ──────────────────────────────────────────────────────────────────

	/**
	 * Wires up WordPress Plugins-screen filters.
	 *
	 * Tier 2: also hooks PUC pre-inject filters so the plugin does not appear
	 * on Dashboard > Updates and the version-details modal is suppressed.
	 */
	public function __construct() {
		$this->basename = plugin_basename( RMB_PLUGIN_FILE );
		add_filter( 'all_plugins', array( $this, 'filter_plugins_list' ) );
		add_filter( 'plugin_row_meta', array( $this, 'filter_row_meta' ), 10, 2 );

		if ( self::wl_hidden() ) {
			add_filter( 'puc_pre_inject_update-rankmath-rest-bridge', '__return_null' );
			add_filter( 'puc_pre_inject_info-rankmath-rest-bridge', '__return_false' );
		}
	}

	/**
	 * Hides or renames the plugin entry in the Plugins list.
	 *
	 * Called on the `all_plugins` filter. When RRSEO_WL_HIDE_PLUGIN is true the
	 * entry is removed entirely (Tier 2). Otherwise any defined WL constants
	 * overwrite the corresponding metadata fields (Tier 1).
	 *
	 * @param array $plugins Associative array of all installed plugins.
	 * @return array
	 */
	public function filter_plugins_list( array $plugins ): array {
		if ( ! isset( $plugins[ $this->basename ] ) ) {
			return $plugins;
		}

		if ( self::wl_hidden() ) {
			unset( $plugins[ $this->basename ] );
			return $plugins;
		}

		if ( defined( 'RRSEO_WL_NAME' ) ) {
			$plugins[ $this->basename ]['Name']  = (string) RRSEO_WL_NAME;
			$plugins[ $this->basename ]['Title'] = (string) RRSEO_WL_NAME;
		}
		if ( defined( 'RRSEO_WL_DESCRIPTION' ) ) {
			$plugins[ $this->basename ]['Description'] = (string) RRSEO_WL_DESCRIPTION;
		}
		if ( defined( 'RRSEO_WL_AUTHOR' ) ) {
			$plugins[ $this->basename ]['Author']     = (string) RRSEO_WL_AUTHOR;
			$plugins[ $this->basename ]['AuthorName'] = (string) RRSEO_WL_AUTHOR;
		}
		if ( defined( 'RRSEO_WL_AUTHOR_URL' ) ) {
			$plugins[ $this->basename ]['AuthorURI'] = (string) RRSEO_WL_AUTHOR_URL;
		}

		return $plugins;
	}

	/**
	 * Appends a custom support link to the plugin row when RRSEO_WL_SUPPORT_URL
	 * is defined. Has no effect when the plugin is hidden (no row exists).
	 *
	 * @param string[] $meta        Existing row meta items.
	 * @param string   $plugin_file Plugin basename for the current row.
	 * @return string[]
	 */
	public function filter_row_meta( array $meta, string $plugin_file ): array {
		if ( $this->basename !== $plugin_file ) {
			return $meta;
		}
		if ( ! defined( 'RRSEO_WL_SUPPORT_URL' ) ) {
			return $meta;
		}

		$meta[] = '<a href="' . esc_url( (string) RRSEO_WL_SUPPORT_URL ) . '" target="_blank">'
			. esc_html__( 'Support', 'rankrocket-seo' ) . '</a>';

		return $meta;
	}
}
