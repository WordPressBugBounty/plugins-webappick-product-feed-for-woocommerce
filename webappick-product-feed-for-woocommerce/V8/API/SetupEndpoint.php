<?php
/**
 * SetupEndpoint — REST API for the first-install Setup wizard
 * (owner-approved feature, 2026-07-29).
 *
 * POST /setup/complete records the whole wizard outcome in one call:
 * clears the fresh-install flag, applies the usage-data choice through
 * the AppServices tracker (whose optIn()/optOut() also hide the SDK's
 * legacy opt-in admin notice so users answer exactly once), and stores
 * the selected channels for reference.
 *
 * POST /setup/install-woocommerce installs and/or activates WooCommerce
 * from WordPress.org using WP core's Plugin_Upgrader (same machinery as
 * Utility\PluginInstaller). The slug is hardcoded — the route never
 * accepts an arbitrary package.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 */

namespace CTXFeed\V8\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup wizard REST endpoint.
 *
 * @since 8.0.0
 */
class SetupEndpoint extends RestController {

	/**
	 * Channel slugs the wizard may store — anything else is dropped.
	 *
	 * These match the MakeFeed template/merchant keys (Common\
	 * DropdownRegistry) so the Finish step can deep-link into
	 * #/new-feed?template={slug}. `other` is a wizard-only marker.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	const CHANNELS = array( 'google', 'facebook', 'tiktok', 'pinterest', 'bing', 'snapchat', 'other' );

	/**
	 * WooCommerce plugin slug on WordPress.org (hardcoded — never
	 * caller-supplied).
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const WC_SLUG = 'woocommerce';

	/**
	 * WooCommerce plugin basename.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const WC_BASENAME = 'woocommerce/woocommerce.php';

	/**
	 * Register the setup routes.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/setup/complete',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_setup' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		register_rest_route(
			$this->namespace,
			'/setup/install-woocommerce',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_woocommerce' ),
				'permission_callback' => array( $this, 'install_permission_check' ),
			) 
		);
	}

	/**
	 * Installing plugins needs `install_plugins` (administrators).
	 *
	 * Deliberately NOT stacked on the module-wide `manage_woocommerce`
	 * check: that capability does not exist until WooCommerce has been
	 * installed, and this route is precisely the one that fixes a
	 * WooCommerce-less site. `install_plugins` is the stricter,
	 * admin-only gate.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return bool
	 */
	public function install_permission_check( $request ): bool { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		return current_user_can( 'install_plugins' );
	}

	/**
	 * Record the wizard outcome (finish or skip).
	 *
	 * POST /ctxfeed/v8/setup/complete
	 * Body: { telemetry?: bool, channels?: string[], skipped?: bool }
	 *
	 * Always clears `ctxfeed_setup_pending` so the wizard never re-opens.
	 * `telemetry`, when present, is routed through the AppServices
	 * tracker (opt-in or opt-out — either way the SDK marks its legacy
	 * notice as dismissed, so the user is never re-nagged). `channels`,
	 * when present, is sanitized against the known-slug whitelist and
	 * stored as `ctxfeed_setup_channels` (informational only).
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function complete_setup( \WP_REST_Request $request ): \WP_REST_Response {
		delete_option( 'ctxfeed_setup_pending' );

		$telemetry = $request->get_param( 'telemetry' );
		if ( null !== $telemetry ) {
			$this->set_tracking( rest_sanitize_boolean( $telemetry ) );
		}

		$channels = $request->get_param( 'channels' );
		if ( null !== $channels ) {
			$channels = array_values(
				array_unique(
					array_intersect(
						array_map( 'sanitize_key', (array) $channels ),
						self::CHANNELS
					)
				)
			);
			update_option( 'ctxfeed_setup_channels', $channels, false );
		}

		return $this->success(
			array(
				'completed' => true,
				'skipped'   => (bool) rest_sanitize_boolean( $request->get_param( 'skipped' ) ),
			) 
		);
	}

	/**
	 * Install and/or activate WooCommerce.
	 *
	 * POST /ctxfeed/v8/setup/install-woocommerce
	 *
	 * Already active → no-op success. Not installed → install the latest
	 * stable release from WordPress.org, then activate. Installed but
	 * inactive → just activate (requires `activate_plugins`).
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function install_woocommerce( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		if ( $this->is_woocommerce_active() ) {
			return $this->success(
				array(
					'installed' => true,
					'active'    => true,
				) 
			);
		}

		if ( ! $this->is_woocommerce_installed() ) {
			$result = $this->run_woocommerce_install();

			if ( is_wp_error( $result ) ) {
				return $this->error( $result->get_error_message(), 500 );
			}
		}

		// Installed (just now or previously) but not active — activate.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return $this->error(
				__( 'WooCommerce is installed but you do not have permission to activate plugins. Ask an administrator to activate it.', 'woo-feed' ),
				403
			);
		}

		$activated = $this->run_woocommerce_activate();

		if ( is_wp_error( $activated ) ) {
			return $this->error( $activated->get_error_message(), 500 );
		}

		return $this->success(
			array(
				'installed' => true,
				'active'    => true,
			) 
		);
	}

	// ── Seams (protected so Layer-1 tests can substitute the WP-heavy parts) ──

	/**
	 * Apply the usage-data choice via the AppServices tracker.
	 *
	 * Insights::optIn()/optOut() write `{slug}_allow_tracking`
	 * ('yes'/'no') AND `{slug}_tracking_notice` ('hide') — the latter is
	 * the SDK's own dismissed-notice mechanism, so answering the wizard
	 * silences the legacy admin notice permanently either way.
	 *
	 * @since 8.0.0
	 *
	 * @param bool $allow True to opt in, false to opt out.
	 * @return void
	 */
	protected function set_tracking( bool $allow ): void {
		$app = \CTXFeed\V8\AppServices\AppServices::instance();

		if ( $allow ) {
			$app->opt_in();
		} else {
			$app->opt_out();
		}
	}

	/**
	 * Whether WooCommerce is loaded or active.
	 *
	 * @since 8.0.0
	 *
	 * @return bool
	 */
	protected function is_woocommerce_active(): bool {
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::WC_BASENAME );
	}

	/**
	 * Whether the WooCommerce plugin files are present.
	 *
	 * @since 8.0.0
	 *
	 * @return bool
	 */
	protected function is_woocommerce_installed(): bool {
		return file_exists( WP_PLUGIN_DIR . '/' . self::WC_BASENAME );
	}

	/**
	 * Install the latest stable WooCommerce release from WordPress.org.
	 *
	 * Mirrors Utility\PluginInstaller's upgrader/skin/error handling, but
	 * with the hardcoded WooCommerce slug and no overwrite (the plugin is
	 * known to be absent when this runs).
	 *
	 * @since 8.0.0
	 *
	 * @return true|\WP_Error
	 */
	protected function run_woocommerce_install() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$package = sprintf( 'https://downloads.wordpress.org/plugin/%s.latest-stable.zip', self::WC_SLUG );

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$errors = $skin->get_errors();
		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return $errors;
		}

		if ( true !== $result ) {
			return new \WP_Error(
				'ctxfeed_wc_install_failed',
				__( 'WooCommerce could not be installed. Check that the server can reach WordPress.org and write to wp-content/plugins.', 'woo-feed' )
			);
		}

		return true;
	}

	/**
	 * Activate the WooCommerce plugin.
	 *
	 * @since 8.0.0
	 *
	 * @return null|\WP_Error Null on success (activate_plugin() contract).
	 */
	protected function run_woocommerce_activate() {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return activate_plugin( self::WC_BASENAME );
	}
}
