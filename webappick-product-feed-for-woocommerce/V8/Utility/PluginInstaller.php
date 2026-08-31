<?php
/**
 * PluginInstaller — installs a specific release of the free plugin from
 * WordPress.org, replacing the current files (upgrade or rollback).
 *
 * Backs the Version Control page's "Update to X.Y.Z" and "Install X.Y.Z"
 * actions (owner-approved feature, 2026-07-28). Wraps WP core's
 * Plugin_Upgrader with `overwrite_package` so the same call serves both
 * directions; feeds, mappings and settings live in the database and are
 * untouched.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.0
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress.org release installer for this plugin.
 *
 * @since 8.0.0
 */
class PluginInstaller {

	/**
	 * WordPress.org plugin slug.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const SLUG = 'webappick-product-feed-for-woocommerce';

	/**
	 * Plugin basename used to restore the activation state after the
	 * files are replaced.
	 *
	 * @since 8.0.0
	 *
	 * @return string
	 */
	public static function basename(): string {
		/**
		 * Filter the free plugin basename used by the version installer.
		 *
		 * @since 8.0.0
		 *
		 * @param string $basename Plugin basename.
		 */
		return apply_filters( 'ctxfeed_free_plugin_basename', self::SLUG . '/woo-feed.php' );
	}

	/**
	 * Install the given release from WordPress.org over the current files.
	 *
	 * @since 8.0.0
	 *
	 * @param string $version Version string, e.g. "6.6.41".
	 * @return true|\WP_Error
	 */
	public function install( string $version ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$package    = sprintf( 'https://downloads.wordpress.org/plugin/%s.%s.zip', self::SLUG, $version );
		$was_active = is_plugin_active( self::basename() );

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );

		// overwrite_package (WP 5.5+) replaces the existing folder in place.
		$result = $upgrader->install( $package, array( 'overwrite_package' => true ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$errors = $skin->get_errors();
		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return $errors;
		}

		if ( true !== $result ) {
			return new \WP_Error(
				'ctxfeed_install_failed',
				__( 'The release could not be installed. Check that the version exists on WordPress.org and that the server can write to wp-content/plugins.', 'woo-feed' )
			);
		}

		// Overwriting keeps the same folder, but re-assert activation in
		// case core deactivated the plugin during the swap.
		if ( $was_active && ! is_plugin_active( self::basename() ) ) {
			$activated = activate_plugin( self::basename() );

			if ( is_wp_error( $activated ) ) {
				return $activated;
			}
		}

		return true;
	}
}
