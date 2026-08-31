<?php
/**
 * UpsellPages — the V5 "Premium" and "Our Plugins" marketing menu pages,
 * ported natively into V8.
 *
 * Self-contained: the view partials (V8/Admin/views/), styles (V8/Admin/css/),
 * scripts (V8/Admin/js/), and images (V8/Admin/images/) all live under V8, so
 * the pages keep working after admin/ + V5 are removed. The plugin-install AJAX
 * handler (previously includes/helper.php) is ported here too.
 *
 * Registered by AdminServiceProvider in V8 mode. Uses the V5 submenu slugs so
 * the URLs carry over on upgrade.
 *
 * @package    CTXFeed
 * @subpackage V8/Admin
 * @since      8.0.0
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Premium + Our Plugins admin pages.
 *
 * @since 8.0.0
 */
final class UpsellPages {

	const PREMIUM_SLUG     = 'webappick-feed-pro-vs-free';   // Matches V5.
	const OUR_PLUGINS_SLUG = 'webappick-feed-our-plugins';   // Matches V5.

	/**
	 * Registered page hook suffixes, keyed by page.
	 *
	 * @since 8.0.0
	 * @var array<string,string>
	 */
	private $hooks = array();

	/**
	 * Attach WordPress hooks.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_pages' ), 20 ); // After AdminPage (10).
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_woo_feed_plugin_installing', array( $this, 'ajax_install_plugin' ) );
	}

	/**
	 * Register the Premium + Our Plugins pages WITHOUT menu items.
	 *
	 * Single-menu design (owner decision, 2026-07): WordPress shows one
	 * "CTX Feed" entry only, so these pages hang off a hidden parent
	 * (`options.php` — the standard no-menu-item pattern). Their URLs
	 * (`admin.php?page=…`) keep working; the React app's sidebar links here.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function register_pages(): void {
		$this->hooks['premium'] = (string) add_submenu_page(
			'options.php',
			__( 'Premium', 'woo-feed' ),
			__( 'Premium', 'woo-feed' ),
			'manage_woocommerce',
			self::PREMIUM_SLUG,
			array( $this, 'render_premium' )
		);

		$this->hooks['our_plugins'] = (string) add_submenu_page(
			'options.php',
			__( 'Our Plugins', 'woo-feed' ),
			__( 'Our Plugins', 'woo-feed' ),
			'manage_woocommerce',
			self::OUR_PLUGINS_SLUG,
			array( $this, 'render_our_plugins' )
		);
	}

	/**
	 * Enqueue the marketing styles (both pages) + install script (Our Plugins).
	 *
	 * @since 8.0.0
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}

		$base = defined( 'CTXFEED_V8_URL' ) ? CTXFEED_V8_URL : '';
		$ver  = defined( 'CTXFEED_V8_VERSION' ) ? CTXFEED_V8_VERSION : false;

		wp_enqueue_style( 'ctxfeed-v8-marketing', $base . 'Admin/css/woo-feed-admin.min.css', array(), $ver );
		wp_enqueue_style( 'ctxfeed-v8-marketing-pro', $base . 'Admin/css/woo-feed-admin-pro.min.css', array(), $ver );

		if ( isset( $this->hooks['our_plugins'] ) && $hook === $this->hooks['our_plugins'] ) {
			wp_enqueue_script( 'ctxfeed-v8-our-plugins', $base . 'Admin/js/woo-feed-our-plugins.min.js', array( 'jquery' ), $ver, true );
			wp_localize_script(
				'ctxfeed-v8-our-plugins',
				'woo_feed_our_plugins_info',
				array(
					'url'   => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'woo-feed-our-plugins-nonce' ),
				)
			);
		}
	}

	/**
	 * Render the Premium (pro-vs-free) page.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function render_premium(): void {
		require __DIR__ . '/views/premium.php';
	}

	/**
	 * Render the Our Plugins page.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function render_our_plugins(): void {
		require __DIR__ . '/views/our-plugins.php';
	}

	/**
	 * AJAX: install + activate a WebAppick plugin from wp.org.
	 *
	 * Ported from the V5 woo_feed_plugin_installing() handler.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function ajax_install_plugin(): void {
		check_ajax_referer( 'woo-feed-our-plugins-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json(
				array(
					'status' => 401,
					'result' => 'Unauthorized',
				) 
			);
		}

		$plugin_slug = isset( $_POST['data'] ) ? sanitize_text_field( wp_unslash( $_POST['data'] ) ) : '';
		$result      = $this->install_and_activate( $plugin_slug );

		wp_send_json(
			array(
				'status' => 'failed' === $result ? 403 : 200,
				'result' => $result,
			) 
		);
	}

	/**
	 * Install and activate a plugin by wp.org slug.
	 *
	 * Ported from the V5 woo_feed_install_and_activate_plugin() helper.
	 *
	 * @since 8.0.0
	 *
	 * @param string $plugin_slug wp.org plugin slug.
	 * @return string 'activated' | 'installed' | 'failed'.
	 */
	private function install_and_activate( string $plugin_slug ): string {
		if ( ! current_user_can( 'manage_options' ) || '' === $plugin_slug ) {
			return 'failed';
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';

		$api = plugins_api( 'plugin_information', array( 'slug' => $plugin_slug ) );
		if ( is_wp_error( $api ) ) {
			return 'failed';
		}

		$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			return 'failed';
		}

		$plugin_index = ( 'webappick-pdf-invoice-for-woocommerce' === $plugin_slug ) ? 'woo-invoice' : $plugin_slug;
		$plugin_path  = WP_PLUGIN_DIR . "/{$plugin_slug}/{$plugin_index}.php";

		if ( file_exists( $plugin_path ) ) {
			activate_plugin( "{$plugin_slug}/{$plugin_index}.php" );
			return 'activated';
		}

		return 'installed';
	}
}
