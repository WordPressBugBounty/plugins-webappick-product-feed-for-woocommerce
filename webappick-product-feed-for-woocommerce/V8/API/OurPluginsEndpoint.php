<?php
/**
 * OurPluginsEndpoint — live WordPress.org data for the "Our plugins" page.
 *
 * Fetches the public plugin information (rating, review count, active
 * installs, version, last-updated, icon, short description and the
 * "tested up to" WordPress version) for the WebAppick plugins straight
 * from the WordPress.org Plugins API via `plugins_api()`, and caches the
 * combined result in a transient so the API is queried at most twice a
 * day. The React page overlays this over its curated card copy and falls
 * back to that copy whenever WordPress.org is unreachable.
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
 * "Our plugins" REST endpoint.
 *
 * @since 8.0.0
 */
class OurPluginsEndpoint extends RestController {

	/**
	 * Transient key for the cached WordPress.org payload.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'ctxfeed_our_plugins_wporg';

	/**
	 * The WebAppick plugins to surface, in display order. Slugs are the
	 * WordPress.org repository slugs.
	 *
	 * @var array
	 */
	const PLUGIN_SLUGS = array(
		'webappick-product-feed-for-woocommerce',
		'webappick-pdf-invoice-for-woocommerce',
		'disco',
	);

	/**
	 * Register the /our-plugins route.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/our-plugins',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_plugins' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		// One-click in-place install of a WebAppick free plugin from
		// WordPress.org (so "Install now" installs here instead of opening the
		// .org page).
		register_rest_route(
			$this->namespace,
			'/our-plugins/install',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install_plugin' ),
				'permission_callback' => array( $this, 'install_permission' ),
			)
		);
	}

	/**
	 * Install requires the plugin-install capability on top of the base feed
	 * management check.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return bool
	 */
	public function install_permission( \WP_REST_Request $request ): bool {
		return $this->permission_check( $request ) && current_user_can( 'install_plugins' );
	}

	/**
	 * Install (if needed) and activate one of the WebAppick free plugins from
	 * WordPress.org. The slug is allow-listed to PLUGIN_SLUGS, so this can never
	 * be used to install an arbitrary plugin.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function install_plugin( \WP_REST_Request $request ): \WP_REST_Response {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );
		if ( ! in_array( $slug, self::PLUGIN_SLUGS, true ) ) {
			return $this->error( __( 'Unknown plugin.', 'woo-feed' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$map  = $this->installed_files_by_slug();
		$file = isset( $map[ $slug ] ) ? $map[ $slug ] : '';

		// Not installed yet → download + install from WordPress.org.
		if ( '' === $file ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);
			if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
				return $this->error( __( 'Could not fetch the plugin from WordPress.org.', 'woo-feed' ), 502 );
			}

			$skin      = new \Automatic_Upgrader_Skin();
			$upgrader  = new \Plugin_Upgrader( $skin );
			$installed = $upgrader->install( (string) $api->download_link );

			if ( is_wp_error( $installed ) ) {
				return $this->error( $installed->get_error_message(), 500 );
			}
			if ( true !== $installed ) {
				return $this->error( __( 'The plugin could not be installed.', 'woo-feed' ), 500 );
			}

			$file = (string) $upgrader->plugin_info();
			if ( '' === $file ) {
				$map  = $this->installed_files_by_slug();
				$file = isset( $map[ $slug ] ) ? $map[ $slug ] : '';
			}
		}

		if ( '' === $file ) {
			return $this->error( __( 'The plugin could not be located after install.', 'woo-feed' ), 500 );
		}

		if ( ! is_plugin_active( $file ) ) {
			$activated = activate_plugin( $file );
			if ( is_wp_error( $activated ) ) {
				return $this->error( $activated->get_error_message(), 500 );
			}
		}

		return $this->success(
			array(
				'slug'  => $slug,
				'local' => $this->local_state(),
			)
		);
	}

	/**
	 * Return live WordPress.org data for the WebAppick plugins.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_plugins( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		$wp_version = get_bloginfo( 'version' );

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $this->success(
				array(
					'plugins'    => $cached,
					'wp_version' => $wp_version,
					'local'      => $this->local_state(),
				)
			);
		}

		$plugins = $this->fetch_from_wporg( $wp_version );

		// Only cache a successful fetch for the full window; on a total
		// failure keep a short TTL so we retry soon without hammering.
		if ( ! empty( $plugins ) ) {
			set_transient( self::CACHE_KEY, $plugins, 12 * HOUR_IN_SECONDS );
		} else {
			set_transient( self::CACHE_KEY, array(), 15 * MINUTE_IN_SECONDS );
		}

		return $this->success(
			array(
				'plugins'    => $plugins,
				'wp_version' => $wp_version,
				'local'      => $this->local_state(),
			)
		);
	}

	/**
	 * Fresh install / activation state for each configured plugin (BUG-0063).
	 *
	 * Computed on every request — never cached with the WordPress.org payload —
	 * so a card reflects the site's real state: "Install now" only when the
	 * plugin is absent, "Activate" (with a one-click nonce URL) when installed
	 * but inactive, and an "Installed"/"Active" state once it is running. The
	 * previous version always linked to WordPress.org regardless.
	 *
	 * @since 8.0.0
	 *
	 * @return array<string,array{installed:bool,active:bool,activate_url:string}>
	 *         Keyed by plugin slug.
	 */
	private function local_state(): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$file_by_slug = $this->installed_files_by_slug();

		$state = array();
		foreach ( self::PLUGIN_SLUGS as $slug ) {
			$file         = isset( $file_by_slug[ $slug ] ) ? $file_by_slug[ $slug ] : '';
			$is_installed = '' !== $file;
			$is_active    = $is_installed && is_plugin_active( $file );

			$activate_url = '';
			if ( $is_installed && ! $is_active ) {
				$activate_url = wp_nonce_url(
					self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $file ) ),
					'activate-plugin_' . $file
				);
			}

			$state[ $slug ] = array(
				'installed'    => $is_installed,
				'active'       => $is_active,
				'activate_url' => $activate_url,
			);
		}

		return $state;
	}

	/**
	 * Map each installed plugin's WordPress.org-style slug (its folder, e.g.
	 * "disco/disco.php" → "disco") to its main plugin file.
	 *
	 * @since 8.0.0
	 *
	 * @return array<string,string> slug => plugin file.
	 */
	private function installed_files_by_slug(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$map = array();
		foreach ( array_keys( (array) get_plugins() ) as $plugin_file ) {
			$dir  = dirname( (string) $plugin_file );
			$slug = ( '.' === $dir ) ? basename( (string) $plugin_file, '.php' ) : $dir;
			if ( ! isset( $map[ $slug ] ) ) {
				$map[ $slug ] = (string) $plugin_file;
			}
		}
		return $map;
	}

	/**
	 * Query the WordPress.org Plugins API for each slug and normalize it.
	 *
	 * @since 8.0.0
	 *
	 * @param string $wp_version The running WordPress version.
	 * @return array List of normalized plugin rows (failed slugs are skipped).
	 */
	private function fetch_from_wporg( string $wp_version ): array {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$plugins = array();

		foreach ( self::PLUGIN_SLUGS as $slug ) {
			$info = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array(
						'short_description' => true,
						'icons'             => true,
						'active_installs'   => true,
						'ratings'           => true,
						'rating'            => true,
						'num_ratings'       => true,
						'last_updated'      => true,
						'tested'            => true,
						'requires'          => true,
						'homepage'          => true,
						'sections'          => false,
						'banners'           => false,
						'reviews'           => false,
						'contributors'      => false,
					),
				)
			);

			if ( is_wp_error( $info ) || ! is_object( $info ) ) {
				continue;
			}

			$plugins[] = $this->normalize( $info, $slug, $wp_version );
		}

		return $plugins;
	}

	/**
	 * Reduce a plugins_api response object to the fields the UI needs.
	 *
	 * @since 8.0.0
	 *
	 * @param object $info       plugins_api() response object.
	 * @param string $slug       Repository slug.
	 * @param string $wp_version Running WordPress version.
	 * @return array Normalized plugin row.
	 */
	private function normalize( $info, string $slug, string $wp_version ): array {
		$rating       = isset( $info->rating ) ? round( ( (float) $info->rating ) / 20, 1 ) : 0.0;
		$last_updated = isset( $info->last_updated ) ? (string) $info->last_updated : '';
		$last_ts      = '' !== $last_updated ? strtotime( $last_updated ) : false;
		$tested       = isset( $info->tested ) ? (string) $info->tested : '';

		return array(
			'slug'              => $slug,
			'name'              => isset( $info->name ) ? wp_strip_all_tags( (string) $info->name ) : $slug,
			'short_description' => isset( $info->short_description ) ? wp_strip_all_tags( (string) $info->short_description ) : '',
			'icon'              => $this->pick_icon( $info ),
			'rating'            => $rating,
			'num_ratings'       => isset( $info->num_ratings ) ? (int) $info->num_ratings : 0,
			'active_installs'   => isset( $info->active_installs ) ? (int) $info->active_installs : 0,
			'version'           => isset( $info->version ) ? (string) $info->version : '',
			'last_updated'      => $last_ts ? gmdate( 'c', $last_ts ) : '',
			'tested'            => $tested,
			'compatible'        => $this->is_compatible( $tested, $wp_version ),
			'homepage'          => isset( $info->homepage ) ? esc_url_raw( (string) $info->homepage ) : '',
		);
	}

	/**
	 * Choose the best available icon URL (svg → retina → standard → default).
	 *
	 * @since 8.0.0
	 *
	 * @param object $info plugins_api() response object.
	 * @return string Icon URL, or '' when none is available.
	 */
	private function pick_icon( $info ): string {
		if ( empty( $info->icons ) || ! is_array( $info->icons ) ) {
			return '';
		}
		foreach ( array( 'svg', '2x', '1x', 'default' ) as $key ) {
			if ( ! empty( $info->icons[ $key ] ) ) {
				return esc_url_raw( (string) $info->icons[ $key ] );
			}
		}
		return '';
	}

	/**
	 * Whether the plugin's "tested up to" version covers the running WP.
	 *
	 * Compares against the site's major.minor only (WordPress.org reports
	 * "tested up to" as major.minor), so a 6.7.2 site is compatible with a
	 * plugin tested up to 6.7.
	 *
	 * @since 8.0.0
	 *
	 * @param string $tested     "Tested up to" version, e.g. "6.7".
	 * @param string $wp_version Running WordPress version, e.g. "6.7.2".
	 * @return bool True when the plugin is tested against this WP (or unknown).
	 */
	private function is_compatible( string $tested, string $wp_version ): bool {
		if ( '' === $tested || '' === $wp_version ) {
			return true;
		}
		$parts      = explode( '.', $wp_version );
		$majorminor = isset( $parts[1] ) ? $parts[0] . '.' . $parts[1] : $parts[0];

		return version_compare( $tested, $majorminor, '>=' );
	}
}
