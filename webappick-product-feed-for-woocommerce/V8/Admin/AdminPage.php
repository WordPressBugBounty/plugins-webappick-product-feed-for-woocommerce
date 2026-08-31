<?php
/**
 * Admin Page.
 *
 * Registers V8 admin menu pages and renders the React app shell.
 * Serves as the bridge between WordPress admin and the modern React-based UI.
 *
 * @package    CTXFeed
 * @subpackage V8\Admin
 * @since      8.0.0
 * @implements ADM-FRD-1.1, ADM-FRD-1.2, ADM-FRD-1.3
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminPage
 *
 * Registers the top-level CTX Feed menu and submenu pages.
 * All submenus use hash-based routing so the React single-page app
 * handles internal navigation.
 *
 * @since 8.0.0
 */
class AdminPage {

	/**
	 * WordPress admin menu slug.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	// Matches the V5 top-level menu slug so the admin URL, menu position, and
	// bookmarks carry over unchanged on upgrade (admin.php?page=webappick-manage-feeds).
	const MENU_SLUG = 'webappick-manage-feeds';

	/**
	 * Register the top-level menu and submenu pages.
	 *
	 * Hooked to `admin_menu` by AdminServiceProvider::boot().
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 *
	 * @hook filter ctxfeed_admin_submenus — Filter submenu array before registration.
	 */
	public function register_pages(): void {
		// `manage_woocommerce` is the normal gate, but the capability only
		// exists once WooCommerce has been installed. On a site where it
		// never was (WP <6.5 ignores `Requires Plugins`, or WC was removed)
		// even administrators lack it, which would hide this page — and with
		// it the Setup wizard whose first step installs WooCommerce. Fall
		// back to `manage_options` (administrators only, i.e. stricter) so
		// the page stays reachable; shop managers keep their access whenever
		// WooCommerce is present.
		$capability = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';

		add_menu_page(
			__( 'CTX Feed', 'woo-feed' ),
			__( 'CTX Feed', 'woo-feed' ),
			$capability,
			self::MENU_SLUG,
			array( $this, 'render_app' ),
			// A dashicon fills the icon slot so the .wp-menu-image ELEMENT has no
			// SVG background — that keeps WordPress's svg-painter.js (which
			// recolours SVG element backgrounds to a single scheme colour, and
			// would flatten our multi-colour logo to a white silhouette) away
			// from it. The real CTX Feed logo is painted on the ::before pseudo
			// -element in print_menu_icon_css(), which the painter never touches.
			'dashicons-rss',
			58
		);

		// Single-menu design (owner decision, 2026-07): the redesigned React
		// app ships its own in-app sidebar navigation, so WordPress gets ONE
		// top-level "CTX Feed" item and no submenus. Page routing stays
		// hash-based (#/feeds, #/new-feed, …) inside the app. The old
		// `ctxfeed_admin_submenus` filter is intentionally no longer applied —
		// Pro-only pages (e.g. License) appear in the in-app sidebar via the
		// localized `pro_installed` / `license_url` data instead.
	}

	/**
	 * Render the React app mount point.
	 *
	 * Outputs a single div that the React app mounts to.
	 * All UI rendering is handled client-side.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function render_app(): void {
		echo '<div id="ctxfeed-v8-app"></div>';
	}

	/**
	 * The CTX Feed brand logo as a base64 data-URI SVG (for use as a CSS
	 * background), or '' if the bundled asset can't be read.
	 *
	 * It is painted on the menu item's ::before pseudo-element in
	 * print_menu_icon_css() rather than passed to add_menu_page(): WordPress's
	 * svg-painter.js recolours any SVG set as the `.wp-menu-image` *element*
	 * background to a single scheme colour (flattening our multi-colour logo to
	 * a solid silhouette — white on the active item), but it never touches a
	 * ::before background, so the logo keeps its real colours on every state.
	 *
	 * @since 8.0.0
	 *
	 * @return string
	 */
	private static function logo_data_uri(): string {
		$path = __DIR__ . '/images/woo-feed-icon.svg';
		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin asset from a local __DIR__ path, never a remote URL.
		$svg = file_get_contents( $path );
		if ( ! is_string( $svg ) || '' === $svg ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Building a data: URI for the admin-menu icon, not obfuscating code.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Print the admin-menu icon CSS globally (every admin page).
	 *
	 * Paints the full-colour CTX Feed logo onto the top-level menu item's
	 * `wp-menu-image::before` as a centred background image, sized by HEIGHT
	 * (the logo is portrait, so a width-based size would make it taller than the
	 * neighbouring icons). Using ::before is deliberate: it keeps the logo out
	 * of reach of WordPress's svg-painter.js, which recolours SVG *element*
	 * backgrounds to a single scheme colour and would otherwise turn the logo
	 * into a white silhouette on the active item (the earlier symptom). If the
	 * bundled asset can't be read, nothing is printed and the registered
	 * dashicon-rss fallback shows instead.
	 *
	 * Hooked to `admin_head` by AdminServiceProvider so it applies on every
	 * screen (the left menu renders everywhere, not just the plugin's pages).
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function print_menu_icon_css(): void {
		$uri = self::logo_data_uri();
		if ( '' === $uri ) {
			return;
		}

		$sel = '#adminmenu #toplevel_page_' . self::MENU_SLUG . ' div.wp-menu-image::before';

		$style = '<style id="ctxfeed-v8-menu-icon">'
			. $sel . '{'
			. 'content:"";display:block;width:100%;height:100%;padding:0;'
			. 'background:url("' . $uri . '") no-repeat center;background-size:auto 22px;'
			. '}</style>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS; the only interpolation is a base64 data-URI built from a bundled asset.
		echo $style;
	}
}
