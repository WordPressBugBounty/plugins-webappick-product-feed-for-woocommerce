<?php
/**
 * PluginSuggestions — surfaces WebAppick's own plugins in the Featured tab of
 * Plugins → Add New, ported natively into V8.
 *
 * Replaces the procedural `webappick_suggest_plugin` class that lived inside
 * woo-feed.php's `! CTXFEED_V8_ACTIVE` block, which stopped running once V8
 * became the sole engine.
 *
 * Registered by AdminServiceProvider in admin requests only.
 *
 * @package    CTXFeed
 * @subpackage V8\Admin
 * @since      8.0.0
 */

namespace CTXFeed\V8\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects WebAppick plugin cards into the Featured plugin-browser tab.
 *
 * Hook names and the plugin-info transient key are preserved from V5, so the
 * behaviour and any warm caches carry over. Hardened compared with V5:
 *
 *  - `plugins_api()` is only called once the plugin-install API is actually
 *    loaded, and a WP_Error result is discarded instead of being pushed into
 *    the results list;
 *  - the duplicate check recognises both object and array plugin entries (the
 *    wp.org 1.2 API returns arrays; V5 only ever matched objects, so it could
 *    inject a second copy of a card that was already there);
 *  - injection order is consistent (V5 prepended from cache but appended on a
 *    cache miss, so the card jumped position after the first page load);
 *  - the whole feature can be switched off with a filter.
 *
 * @since 8.0.0
 */
final class PluginSuggestions {

	/**
	 * Slugs of the plugins to suggest, as published on wp.org.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	const PLUGIN_SLUGS = array(
		'webappick-pdf-invoice-for-woocommerce',
	);

	/**
	 * Prefix of the transient caching each plugin's wp.org information.
	 *
	 * Unchanged from V5 so existing cache entries stay usable.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'wf-plugin-info-';

	/**
	 * Lifetime of the cached plugin information, in seconds.
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const TRANSIENT_TTL = 7 * DAY_IN_SECONDS;

	/**
	 * Attach WordPress hooks.
	 *
	 * Wired by AdminServiceProvider::boot() for admin requests only.
	 *
	 * V5 additionally skipped registration whenever the unrelated third-party
	 * plugin `xt-woo-variation-swatches` was active — a copy/paste artefact
	 * from the snippet this code was adapted from. It is not reproduced: a
	 * competitor's swatch plugin has no bearing on whether CTX Feed may
	 * suggest WebAppick's invoice plugin. Site owners who want the suggestions
	 * gone use the filter below instead.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 *
	 * @hook filter install_plugins_table_api_args_featured → featured_plugins_tab()
	 * @hook filter ctxfeed_suggest_our_plugins — Return false to disable.
	 */
	public function register(): void {
		/**
		 * Filters whether WebAppick's own plugins are suggested in the
		 * Featured tab of the plugin browser.
		 *
		 * Defaults to true, matching V5 behaviour.
		 *
		 * @since 8.0.0
		 *
		 * @param bool $suggest Whether to inject the suggestions.
		 */
		if ( ! apply_filters( 'ctxfeed_suggest_our_plugins', true ) ) {
			return;
		}

		add_filter( 'install_plugins_table_api_args_featured', array( $this, 'featured_plugins_tab' ) );
	}

	/**
	 * Hook the results filter while the Featured tab is being queried.
	 *
	 * Attached to `install_plugins_table_api_args_featured`, which fires
	 * immediately before the Featured tab calls `plugins_api()`. The results
	 * filter is added here — rather than globally — so no other plugin query
	 * (search, tag browsing, the update checker) is touched.
	 *
	 * @since 8.0.0
	 *
	 * @param array $args Query arguments for the Featured tab.
	 * @return array Unmodified arguments.
	 *
	 * @hook filter plugins_api_result → plugins_api_result()
	 */
	public function featured_plugins_tab( $args ) {
		add_filter( 'plugins_api_result', array( $this, 'plugins_api_result' ), 10, 3 );

		return $args;
	}

	/**
	 * Inject the WebAppick plugin cards into the Featured tab results.
	 *
	 * Detaches itself first, so it runs exactly once per request even though
	 * `add_plugin_favs()` may itself call `plugins_api()` — V5 tried to do this
	 * too, but passed the callback's argument count where `remove_filter()`
	 * expects a priority, so the filter was never actually removed.
	 *
	 * @since 8.0.0
	 *
	 * @param object|\WP_Error $res    Response object from the plugins API.
	 * @param string           $action The requested action. Unused.
	 * @param object|array     $args   Arguments for the request. Unused.
	 * @return object|\WP_Error Response with the suggestions prepended, or untouched on failure.
	 */
	public function plugins_api_result( $res, $action = '', $args = null ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter -- $action and $args are positional arguments of the `plugins_api_result` filter contract; WordPress passes all three and they must be accepted even though only $res is used.
		remove_filter( 'plugins_api_result', array( $this, 'plugins_api_result' ), 10 );

		foreach ( self::PLUGIN_SLUGS as $plugin_slug ) {
			$res = $this->add_plugin_favs( $plugin_slug, $res );
		}

		return $res;
	}

	/**
	 * Prepend a single plugin's card to a plugins API result.
	 *
	 * No-ops when the response is not a usable object, when the plugin is
	 * already present in the results, or when its information cannot be
	 * fetched — in every one of those cases the merchant simply sees the
	 * unmodified Featured tab.
	 *
	 * @since 8.0.0
	 *
	 * @param string           $plugin_slug wp.org slug of the plugin to add.
	 * @param object|\WP_Error $res         Response object from the plugins API.
	 * @return object|\WP_Error Response, possibly with the plugin prepended.
	 */
	private function add_plugin_favs( string $plugin_slug, $res ) {
		if ( ! is_object( $res ) || is_wp_error( $res ) ) {
			return $res;
		}

		if ( ! isset( $res->plugins ) || ! is_array( $res->plugins ) ) {
			$res->plugins = array();
		}

		if ( $this->already_listed( $plugin_slug, $res->plugins ) ) {
			return $res;
		}

		$plugin_info = $this->get_plugin_info( $plugin_slug );

		if ( null === $plugin_info ) {
			return $res;
		}

		array_unshift( $res->plugins, $plugin_info );

		return $res;
	}

	/**
	 * Check whether a slug is already present in a plugins API result list.
	 *
	 * Handles both entry shapes: the wp.org 1.2 API returns arrays, while
	 * cached/legacy responses and `plugins_api( 'plugin_information' )` return
	 * objects.
	 *
	 * @since 8.0.0
	 *
	 * @param string $plugin_slug wp.org slug to look for.
	 * @param array  $plugins     Plugin entries from the API result.
	 * @return bool True when the slug is already listed.
	 */
	private function already_listed( string $plugin_slug, array $plugins ): bool {
		foreach ( $plugins as $plugin ) {
			if ( is_object( $plugin ) && isset( $plugin->slug ) && $plugin_slug === $plugin->slug ) {
				return true;
			}

			if ( is_array( $plugin ) && isset( $plugin['slug'] ) && $plugin_slug === $plugin['slug'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a plugin's wp.org information, from the transient cache when warm.
	 *
	 * @since 8.0.0
	 *
	 * @param string $plugin_slug wp.org slug of the plugin.
	 * @return object|null Plugin information object, or null when unavailable.
	 */
	private function get_plugin_info( string $plugin_slug ) {
		$transient_key = self::TRANSIENT_PREFIX . $plugin_slug;
		$cached        = get_transient( $transient_key );

		if ( is_object( $cached ) ) {
			return $cached;
		}

		$plugin_info = $this->fetch_plugin_info( $plugin_slug );

		if ( null === $plugin_info ) {
			return null;
		}

		set_transient( $transient_key, $plugin_info, self::TRANSIENT_TTL );

		return $plugin_info;
	}

	/**
	 * Query wp.org for a plugin's information.
	 *
	 * `plugins_api()` lives in wp-admin/includes/plugin-install.php, which is
	 * loaded on the plugin-install screen but not on every admin request; it is
	 * required defensively before use. A WP_Error — wp.org unreachable, slug
	 * retired — yields null so nothing is injected.
	 *
	 * @since 8.0.0
	 *
	 * @param string $plugin_slug wp.org slug of the plugin.
	 * @return object|null Plugin information object, or null on failure.
	 */
	private function fetch_plugin_info( string $plugin_slug ) {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			return null;
		}

		$plugin_info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $plugin_slug,
				'is_ssl' => is_ssl(),
				'fields' => array(
					'banners'           => true,
					'reviews'           => true,
					'downloaded'        => true,
					'active_installs'   => true,
					'icons'             => true,
					'short_description' => true,
				),
			)
		);

		if ( is_wp_error( $plugin_info ) || ! is_object( $plugin_info ) ) {
			return null;
		}

		return $plugin_info;
	}
}
