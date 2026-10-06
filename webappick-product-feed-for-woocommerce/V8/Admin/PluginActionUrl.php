<?php
/**
 * PluginActionUrl — nonced WordPress plugin activate / update links for the
 * React admin.
 *
 * Built exactly like WordPress core's own links, and never with
 * wp_nonce_url(): that returns an HTML-escaped URL ("&amp;"), which React
 * puts into the href verbatim, so WordPress receives "amp;plugin" and
 * answers "The link you followed has expired" (CBT-686, CBT-687).
 *
 * Callers decide whether the user may follow the link (capability checks).
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
 * Class PluginActionUrl
 *
 * @since 8.0.31
 */
class PluginActionUrl {

	/**
	 * One-click activation link (plugins.php, nonce action activate-plugin_{basename}).
	 *
	 * @since 8.0.31
	 *
	 * @param string $basename Plugin basename ("folder/main-file.php").
	 *
	 * @return string Raw URL.
	 */
	public static function activate( string $basename ): string {
		return self::build( 'plugins.php?action=activate', 'activate-plugin_', $basename );
	}

	/**
	 * One-click update link (update.php, nonce action upgrade-plugin_{basename}).
	 *
	 * @since 8.0.31
	 *
	 * @param string $basename Plugin basename ("folder/main-file.php").
	 *
	 * @return string Raw URL.
	 */
	public static function upgrade( string $basename ): string {
		return self::build( 'update.php?action=upgrade-plugin', 'upgrade-plugin_', $basename );
	}

	/**
	 * Admin URL with the plugin and its nonce appended.
	 *
	 * @param string $path     Admin path with the action query.
	 * @param string $nonce    Nonce action prefix.
	 * @param string $basename Plugin basename.
	 *
	 * @return string
	 */
	private static function build( string $path, string $nonce, string $basename ): string {
		return self_admin_url(
			$path . '&plugin=' . rawurlencode( $basename )
			. '&_wpnonce=' . wp_create_nonce( $nonce . $basename )
		);
	}
}
