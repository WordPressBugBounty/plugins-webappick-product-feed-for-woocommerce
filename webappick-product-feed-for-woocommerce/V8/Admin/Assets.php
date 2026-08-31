<?php
/**
 * Assets.
 *
 * Enqueues V8 admin CSS and JavaScript on CTX Feed pages only.
 * Loads the React admin app and passes localised data via wp_localize_script.
 *
 * @package    CTXFeed
 * @subpackage V8\Admin
 * @since      8.0.0
 * @implements ADM-FRD-3.1, ADM-FRD-3.2, ADM-FRD-3.3, ADM-FRD-3.4
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CTXFeed\V8\Core\FeatureGate;

/**
 * Class Assets
 *
 * Enqueues the React bundle and stylesheet on CTX Feed admin pages,
 * and localises configuration data for the front-end app.
 *
 * @since 8.0.0
 */
class Assets {

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * Hooked to `admin_enqueue_scripts` by AdminServiceProvider::boot().
	 * Only loads assets when the current screen belongs to CTX Feed.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 *
	 * @hook filter ctxfeed_admin_localize_data — Filter data passed to React.
	 */
	public function enqueue(): void {
		$screen = get_current_screen();

		// Page guard: only load on CTX Feed admin pages.
		// Implements ADM-FRD-3.1.
		if ( ! $screen || false === strpos( $screen->id, AdminPage::MENU_SLUG ) ) {
			return;
		}

		$asset_path = plugin_dir_path( dirname( __DIR__ ) ) . 'V8/Admin/UI/build/';
		$asset_url  = plugin_dir_url( dirname( __DIR__ ) ) . 'V8/Admin/UI/build/';

		// Bail early if build files don't exist (not yet compiled).
		if ( ! file_exists( $asset_path . 'index.js' ) ) {
			return;
		}

		// Enqueue WordPress's bundled CodeMirror 5 for the Custom Template 2
		// (XML) editor in the Feed Config tab. `wp_enqueue_code_editor()`
		// loads CodeMirror + the XML mode + the linter; it returns the
		// settings object needed by `wp.codeEditor.initialize()`. We stash
		// those settings in localised data so the React XmlEditor
		// component can pass them through verbatim. PROD-FRD-10.6.
		//
		// `wp.codeEditor` may be unavailable on older WP / unusual setups
		// (function returns false when CodeMirror was filtered off). The
		// React component falls back to a plain <textarea> in that case,
		// so an absent settings key is non-fatal.
		$code_editor_settings = false;
		if ( function_exists( 'wp_enqueue_code_editor' ) ) {
			$code_editor_settings = wp_enqueue_code_editor(
				array(
					'type' => 'application/xml',
				) 
			);
			// `wp_enqueue_code_editor` only enqueues `code-editor` /
			// `wp-codemirror` automatically when the user hasn't disabled
			// syntax highlighting in their profile. Force-enqueue the
			// linter assets we need for XML so the editor lights up
			// regardless of that user preference.
			wp_enqueue_script( 'htmlhint' );
			wp_enqueue_script( 'csslint' );
		}

		// Enqueue React app bundle.
		// Implements ADM-FRD-3.2.
		// V8 bundles its own React 18 — do NOT depend on wp-element
		// (which ships React 17). Two React instances break hooks.
		// Also depend on `wp-codemirror` so CodeMirror's globals are on
		// the page before React mounts. The dependency is a no-op when
		// CodeMirror was filtered off (the handle simply doesn't exist).
		$react_deps = array( 'wp-i18n' );
		if ( false !== $code_editor_settings && wp_script_is( 'wp-codemirror', 'registered' ) ) {
			$react_deps[] = 'wp-codemirror';
		}

		wp_enqueue_script(
			'ctxfeed-v8-admin',
			$asset_url . 'index.js',
			$react_deps,
			filemtime( $asset_path . 'index.js' ),
			true
		);

		// Enqueue styles.
		// Implements ADM-FRD-3.3.
		if ( file_exists( $asset_path . 'index.css' ) ) {
			wp_enqueue_style(
				'ctxfeed-v8-admin',
				$asset_url . 'index.css',
				array(),
				filemtime( $asset_path . 'index.css' )
			);
		}

		// Build localised data for the React app.
		// Implements ADM-FRD-3.4.
		$data = array(
			'rest_url'     => rest_url( 'ctxfeed/v8/' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'features'     => FeatureGate::get_all_features(),
			'is_pro'       => FeatureGate::is_pro(),
			// Installed plugin version — shown on the Version Control page.
			'version'      => defined( 'WOO_FEED_FREE_VERSION' ) ? WOO_FEED_FREE_VERSION : '',
			'admin_url'    => admin_url(),
			'plugin_url'   => plugin_dir_url( dirname( __DIR__ ) ),
			// CodeMirror init settings for the Custom Template 2 (XML)
			// editor. `false` when the WP user disabled syntax highlighting
			// in their profile or when wp_enqueue_code_editor isn't
			// available — XmlEditor falls back to a plain <textarea>.
			'code_editor'  => $code_editor_settings,
			// Server FTP/SFTP capability so the Make Feed FTP tab can warn
			// when the required PHP extension is missing — V5 parity with
			// checkFTP_connection() / checkSFTP_connection() in
			// includes/helper.php.
			'ftp_enabled'  => ( extension_loaded( 'ftp' ) || function_exists( 'ftp_connect' ) ),
			'ssh2_enabled' => ( extension_loaded( 'ssh2' ) || function_exists( 'ssh2_connect' ) ),
			// First-install Setup wizard state. `pending` is set by the
			// installer on genuinely fresh installs only and cleared by
			// POST /setup/complete; the two capability/env flags let the
			// wizard render honest requirement rows (no dead buttons).
			'setup'        => array(
				'pending'           => ( 'yes' === get_option( 'ctxfeed_setup_pending' ) ),
				'woocommerceActive' => class_exists( 'WooCommerce' ),
				'canInstallPlugins' => current_user_can( 'install_plugins' ),
			),
		);

		/**
		 * Filter the data passed to the React admin app via wp_localize_script.
		 *
		 * Pro plugins can add licence data, custom settings, or feature flags.
		 *
		 * @since 8.0.0
		 *
		 * @param array $data Associative array of data for window.ctxfeedV8.
		 */
		$data = apply_filters( 'ctxfeed_admin_localize_data', $data );

		wp_localize_script( 'ctxfeed-v8-admin', 'ctxfeedV8', $data );
	}
}
