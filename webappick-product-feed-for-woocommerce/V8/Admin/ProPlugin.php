<?php
/**
 * ProPlugin — is CTX Feed Pro active, installed but inactive, or missing?
 *
 * The free License page (CBT-686) shows the customer the next step before a
 * license key can be entered: download + install Pro, or activate the Pro
 * plugin that is already installed. Pro injects its own `pro_installed`
 * flag only while it runs, so the free plugin has to look for an inactive
 * copy itself.
 *
 * @package    CTXFeed
 * @subpackage V8\Admin
 * @since      8.0.31
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProPlugin
 *
 * @since 8.0.31
 */
class ProPlugin {

	/**
	 * Main file name of the Pro plugin, whatever folder it was unzipped into.
	 *
	 * @var string
	 */
	const MAIN_FILE = 'webappick-product-feed-for-woocommerce-pro.php';

	/**
	 * Plugin Name header of the Pro plugin.
	 *
	 * @var string
	 */
	const PLUGIN_NAME = 'CTX Feed Pro';

	/**
	 * Pro plugin state for the admin app (`window.ctxfeedV8.pro_plugin`).
	 *
	 * Only scans the installed plugins when Pro is not running, and only on
	 * CTX Feed admin pages (called from Assets::enqueue()).
	 *
	 * @since 8.0.31
	 *
	 * @return array{status:string,activate_url:string,upload_url:string}
	 */
	public static function state(): array {
		$loaded = defined( 'WOO_FEED_PRO_VERSION' );
		if ( ! $loaded && ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return self::resolve( $loaded, $loaded ? array() : (array) get_plugins() );
	}

	/**
	 * Build the state from whether Pro is loaded and the installed plugins.
	 *
	 * @since 8.0.31
	 *
	 * @param bool                $loaded  Whether Pro runs in this request.
	 * @param array<string,array> $plugins get_plugins() result (basename => headers).
	 *
	 * @return array{status:string,activate_url:string,upload_url:string}
	 */
	public static function resolve( bool $loaded, array $plugins ): array {
		if ( $loaded ) {
			// upload_url still matters when the running Pro is an old 7.x:
			// the License page then asks for the new Pro to be uploaded.
			return array(
				'status'       => 'active',
				'activate_url' => '',
				'upload_url'   => self::upload_url(),
			);
		}

		$basename = self::find( $plugins );
		if ( '' === $basename ) {
			return array(
				'status'       => 'missing',
				'activate_url' => '',
				'upload_url'   => self::upload_url(),
			);
		}

		return array(
			'status'       => 'inactive',
			'activate_url' => self::activate_url( $basename ),
			'upload_url'   => '',
		);
	}

	/**
	 * Basename of an installed Pro plugin, or '' when there is none.
	 *
	 * Matches the main file name first (the WebAppick zip always ships it,
	 * even when unzipped into a renamed folder), then the Plugin Name header.
	 *
	 * @since 8.0.31
	 *
	 * @param array<string,array> $plugins get_plugins() result.
	 *
	 * @return string
	 */
	private static function find( array $plugins ): string {
		foreach ( array_keys( $plugins ) as $basename ) {
			if ( self::MAIN_FILE === basename( (string) $basename ) ) {
				return (string) $basename;
			}
		}
		foreach ( $plugins as $basename => $headers ) {
			if ( is_array( $headers ) && isset( $headers['Name'] ) && self::PLUGIN_NAME === $headers['Name'] ) {
				return (string) $basename;
			}
		}

		return '';
	}

	/**
	 * Nonced one-click activation link, or '' when the user may not activate it.
	 *
	 * Also used by the "Activate CTX Feed Pro" notice (Status\NoticeProvider):
	 * both are rendered by React, which needs the raw URL.
	 *
	 * @since 8.0.31
	 *
	 * @param string $basename Pro plugin basename.
	 *
	 * @return string
	 */
	public static function activate_url( string $basename ): string {
		if ( ! current_user_can( 'activate_plugin', $basename ) ) {
			return '';
		}

		return PluginActionUrl::activate( $basename );
	}

	/**
	 * The "Upload Plugin" screen, or '' when the user may not upload plugins.
	 *
	 * On multisite, plugins are uploaded from the network admin.
	 *
	 * @since 8.0.31
	 *
	 * @return string
	 */
	private static function upload_url(): string {
		if ( ! current_user_can( 'upload_plugins' ) ) {
			return '';
		}

		return is_multisite()
			? network_admin_url( 'plugin-install.php?tab=upload' )
			: admin_url( 'plugin-install.php?tab=upload' );
	}
}
