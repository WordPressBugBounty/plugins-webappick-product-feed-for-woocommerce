<?php
/**
 * Legacy (pre-8.0) CTX Feed Pro detection and the "finish the upgrade" action.
 *
 * @package CTXFeed\V8\Status
 * @since   8.0.10
 */

namespace CTXFeed\V8\Status;

/**
 * An OLD CTX Feed Pro (< 8.0.0) beside this V8 Free bundles its own V5 engine,
 * so both engines hook feed generation at once and the site can break until
 * Pro is updated (support #68928). Pro loads before Free in the same request,
 * so Free cannot stop it — it can only steer the admin to the fix:
 *
 * - license active + WordPress already lists the Pro update → one-click
 *   "Update now" through the standard plugin upgrader;
 * - otherwise → the WebAppick account downloads page.
 *
 * Used by NoticeProvider (notice slider on every CTX Feed page) and by the
 * System status report (CTX Feed section, red row + banner).
 *
 * @since 8.0.10
 */
final class LegacyPro {

	/**
	 * First Pro generation that shares the V8 engine instead of bundling V5.
	 *
	 * @var string
	 */
	const MIN_VERSION = '8.0.0';

	/**
	 * WebAppick account page listing the purchased plugin downloads.
	 *
	 * @var string
	 */
	const DOWNLOAD_URL = 'https://webappick.com/my-account/api-downloads/';

	/**
	 * Pro plugin basename when the old Pro did not define WOO_FEED_PRO_FILE.
	 *
	 * @var string
	 */
	const DEFAULT_BASENAME = 'webappick-product-feed-for-woocommerce-pro/webappick-product-feed-for-woocommerce-pro.php';

	/**
	 * Whether an old (< 8.0.0) Pro is loaded in this request.
	 *
	 * Reads the constant the Pro defines at include time, so callers must run
	 * after plugins_loaded (admin_notices, REST callbacks, init).
	 *
	 * @since 8.0.10
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( 'WOO_FEED_PRO_VERSION' )
			&& version_compare( (string) WOO_FEED_PRO_VERSION, self::MIN_VERSION, '<' );
	}

	/**
	 * The loaded Pro version ('' when Pro is not loaded).
	 *
	 * @since 8.0.10
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'WOO_FEED_PRO_VERSION' ) ? (string) WOO_FEED_PRO_VERSION : '';
	}

	/**
	 * Pro plugin basename ("folder/main-file.php") as WordPress knows it.
	 *
	 * @since 8.0.10
	 *
	 * @return string
	 */
	public static function basename(): string {
		if ( defined( 'WOO_FEED_PRO_FILE' ) && function_exists( 'plugin_basename' ) ) {
			$basename = (string) plugin_basename( (string) WOO_FEED_PRO_FILE );
			if ( '' !== $basename ) {
				return $basename;
			}
		}

		return self::DEFAULT_BASENAME;
	}

	/**
	 * Whether the old Pro's WebAppick license is active on this site.
	 *
	 * The old AppServices SDK stores the license under
	 * `WebAppick_{md5(plugin folder)}_manage_license` with a `status` key.
	 *
	 * @since 8.0.10
	 *
	 * @return bool
	 */
	public static function license_active(): bool {
		$slug    = dirname( self::basename() );
		$license = get_option( 'WebAppick_' . md5( $slug ) . '_manage_license', array() );

		return is_array( $license )
			&& isset( $license['status'] )
			&& 'active' === $license['status'];
	}

	/**
	 * Whether WordPress currently lists an update for the Pro plugin.
	 *
	 * The old Pro's own updater injects the package into the update_plugins
	 * transient while its license is active, so the standard plugin upgrader
	 * can install Pro 8 in one click.
	 *
	 * @since 8.0.10
	 *
	 * @return bool
	 */
	public static function update_available(): bool {
		$transient = get_site_transient( 'update_plugins' );

		return is_object( $transient )
			&& ! empty( $transient->response )
			&& ! empty( $transient->response[ self::basename() ] );
	}

	/**
	 * Whether the one-click WordPress update can be offered right now.
	 *
	 * Refreshes the update list once (WordPress throttles the remote check
	 * itself) when the license is active but no package is listed yet.
	 *
	 * @since 8.0.10
	 *
	 * @return bool
	 */
	public static function can_update_in_place(): bool {
		if ( ! self::is_active() || ! self::license_active() ) {
			return false;
		}

		if ( ! self::update_available() && function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		return self::update_available();
	}

	/**
	 * The action for the notice / status banner.
	 *
	 * @since 8.0.10
	 *
	 * @return array{label:string,url:string,target:string}
	 */
	public static function action(): array {
		if ( self::can_update_in_place() ) {
			$basename = self::basename();

			return array(
				'label'  => __( 'Update CTX Feed Pro now', 'woo-feed' ),
				'url'    => wp_nonce_url(
					self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $basename ) ),
					'upgrade-plugin_' . $basename
				),
				'target' => '_self',
			);
		}

		return array(
			'label'  => __( 'Download CTX Feed Pro 8', 'woo-feed' ),
			'url'    => self::DOWNLOAD_URL,
			'target' => '_blank',
		);
	}

	/**
	 * The explanation shown under the notice title.
	 *
	 * @since 8.0.10
	 *
	 * @param bool $can_update Whether the one-click update is on offer.
	 * @return string
	 */
	public static function message( bool $can_update ): string {
		$intro = sprintf(
			/* translators: %s: the outdated CTX Feed Pro version. */
			__( 'CTX Feed Pro %s is from the previous generation and runs its own feed engine beside CTX Feed 8. Feeds and the site itself can break until it is updated to 8.0 or higher.', 'woo-feed' ),
			self::version()
		);

		if ( $can_update ) {
			return $intro . ' ' . __( 'WordPress already has the update ready — install it now.', 'woo-feed' );
		}

		return $intro . ' ' . __( 'The Pro license is not active on this site, so WordPress cannot update it automatically: download CTX Feed Pro 8 from your WebAppick account and upload it under Plugins → Add New → Upload Plugin.', 'woo-feed' );
	}
}
