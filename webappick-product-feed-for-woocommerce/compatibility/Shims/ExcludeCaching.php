<?php
/**
 * Compatibility shim for page/CDN caching plugins.
 *
 * Registers the feed output directory (uploads/woo-feed/) as a cache
 * exception with the major caching plugins so merchants never fetch a stale
 * feed file. Prefers runtime filters where a plugin offers one; falls back to
 * additive, idempotent option writes (admin-only) where it does not. Every
 * branch self-guards on the target plugin, so absent plugins are no-ops — no
 * fatals. The whole shim is behind the `ctxfeed_cache_exclude_enabled`
 * kill-switch, and the excluded path is filterable via
 * `ctxfeed_cache_exclude_path`.
 *
 * @package CTXFeed\Compat\Shims
 */

namespace CTXFeed\Compat\Shims;

use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ExcludeCaching
 *
 * @package CTXFeed\Compat\Shims
 */
class ExcludeCaching {

	/**
	 * Root-relative URL path of the feed directory (no trailing slash),
	 * e.g. `/wp-content/uploads/woo-feed`.
	 *
	 * @var string
	 */
	private $feed_path;

	/**
	 * ExcludeCaching constructor — wire every supported caching plugin.
	 */
	public function __construct() {

		/**
		 * Master kill-switch for the caching-exclusion shim.
		 *
		 * @since 8.0.0
		 *
		 * @param bool $enabled Whether to register the cache exclusions.
		 */
		if ( ! apply_filters( 'ctxfeed_cache_exclude_enabled', true ) ) {
			return;
		}

		$this->feed_path = $this->compute_feed_path();

		// --- Runtime filters (cheap, front-end capable, no option writes). ---
		add_filter( 'rocket_cdn_reject_files', array( $this, 'reject_wp_rocket_cdn' ) );
		add_filter( 'rocket_cache_reject_uri', array( $this, 'reject_wp_rocket_uri' ) );
		add_filter( 'sgo_exclude_urls_from_cache', array( $this, 'reject_siteground' ) );
		add_filter( 'cache_enabler_bypass_cache', array( $this, 'bypass_cache_enabler' ) );

		// Covers W3TC / WP Super Cache / WP Rocket / WP-Optimize / Hummingbird
		// / WP Fastest / LiteSpeed / Comet when a feed URL is served via PHP.
		add_action( 'template_redirect', array( $this, 'set_donotcachepage' ), 0 );

		// --- Option writers (admin-only; each additive + self-guarded). ---
		add_action( 'litespeed_init', array( $this, 'exclude_from_litespeed' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'exclude_from_wp_fastest' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'exclude_from_wp_super' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'exclude_from_breeze' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'exclude_from_wp_optimize' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'exclude_from_swift' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'exclude_from_speed_booster' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'exclude_from_comet' ), 10, 0 );
		add_action( 'admin_init', array( $this, 'exclude_from_hyper' ), 10, 0 );
	}

	/**
	 * The root-relative feed directory path, derived from the uploads dir so
	 * it stays correct when uploads is relocated or on multisite.
	 *
	 * @return string
	 */
	private function compute_feed_path(): string {
		$upload   = wp_upload_dir();
		$base_url = isset( $upload['baseurl'] ) ? (string) $upload['baseurl'] : '';
		$path     = trim( (string) wp_parse_url( $base_url, PHP_URL_PATH ), '/' );

		if ( '' === $path ) {
			$path = 'wp-content/uploads';
		}

		$feed_path = '/' . $path . '/woo-feed';

		/**
		 * Filter the root-relative feed path excluded from caching.
		 *
		 * @since 8.0.0
		 *
		 * @param string $feed_path e.g. `/wp-content/uploads/woo-feed`.
		 */
		return (string) apply_filters( 'ctxfeed_cache_exclude_path', $feed_path );
	}

	// =====================================================================
	// Runtime filters.
	// =====================================================================

	/**
	 * WP Rocket — exclude the feed dir from CDN rewriting.
	 *
	 * @param array $files Paths WP Rocket excludes from its CDN.
	 * @return array
	 */
	public function reject_wp_rocket_cdn( $files ) {
		$files   = is_array( $files ) ? $files : array();
		$files[] = $this->feed_path . '/(.*)';
		return $files;
	}

	/**
	 * WP Rocket — exclude the feed dir from page caching.
	 *
	 * @param array $uris URI regex fragments WP Rocket never caches.
	 * @return array
	 */
	public function reject_wp_rocket_uri( $uris ) {
		$uris   = is_array( $uris ) ? $uris : array();
		$uris[] = $this->feed_path . '/(.*)';
		return $uris;
	}

	/**
	 * SiteGround Optimizer (Speed Optimizer) — exclude the feed dir.
	 *
	 * @param array $urls URL patterns (slash-wrapped, `*` wildcards) to exclude.
	 * @return array
	 */
	public function reject_siteground( $urls ) {
		$urls   = is_array( $urls ) ? $urls : array();
		$urls[] = $this->feed_path . '/*';
		return $urls;
	}

	/**
	 * Cache Enabler — bypass the cache for feed requests (runtime, no option
	 * write, so an existing user exclusion list is never mutated).
	 *
	 * @param bool $bypass Whether Cache Enabler already decided to bypass.
	 * @return bool
	 */
	public function bypass_cache_enabler( $bypass ) {
		if ( $bypass ) {
			return $bypass;
		}
		return $this->is_feed_request();
	}

	/**
	 * On a feed request, tell PHP page caches not to cache the response.
	 *
	 * Hooked to `template_redirect` (priority 0). Belt-and-suspenders: feed
	 * files under uploads/ are usually served directly by the web server and
	 * bypass PHP entirely — this covers the case where a feed URL routes
	 * through WordPress.
	 *
	 * @return void
	 */
	public function set_donotcachepage(): void {
		if ( ! $this->is_feed_request() ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is the de-facto cross-plugin caching constant we deliberately set; prefixing it would defeat its purpose.
			define( 'DONOTCACHEPAGE', true );
		}

		// LiteSpeed explicit no-cache for this request.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party action owned by LiteSpeed Cache; we fire its documented no-cache control, not a hook of ours.
		do_action( 'litespeed_control_set_nocache', 'ctxfeed feed file' );
	}

	/**
	 * Whether the current request targets the feed directory.
	 *
	 * @return bool
	 */
	private function is_feed_request(): bool {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Read-only substring probe of the request path; sanitized below, no state change.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return '' !== $request_uri && false !== strpos( $request_uri, 'woo-feed' );
	}

	// =====================================================================
	// Option writers (admin-only; additive + idempotent).
	// =====================================================================

	/**
	 * LiteSpeed Cache — add the feed dir to the CDN exclusion list.
	 *
	 * @return void
	 */
	public function exclude_from_litespeed(): void {
		if ( ! class_exists( 'LiteSpeed\Core' ) || ! defined( 'LSCWP_DIR' ) ) {
			return;
		}

		$paths = maybe_unserialize( get_option( 'litespeed.conf.cdn-exc' ) );
		if ( is_array( $paths ) && ! in_array( $this->feed_path, $paths, true ) ) {
			$paths[] = $this->feed_path;
			update_option( 'litespeed.conf.cdn-exc', $paths );
		}
	}

	/**
	 * WP Fastest Cache — add a "contain woo-feed" page exclusion rule.
	 *
	 * @return void
	 */
	public function exclude_from_wp_fastest(): void {
		if ( ! class_exists( 'WpFastestCache' ) ) {
			return;
		}

		$rules = json_decode( (string) get_option( 'WpFastestCacheExclude' ), false );
		$rules = is_array( $rules ) ? $rules : array();

		foreach ( $rules as $rule ) {
			if ( isset( $rule->content ) && 'woo-feed' === $rule->content ) {
				return; // Already excluded.
			}
		}

		$new_rule          = new stdClass();
		$new_rule->prefix  = 'contain';
		$new_rule->content = 'woo-feed';
		$new_rule->type    = 'page';
		$rules[]           = $new_rule;

		update_option( 'WpFastestCacheExclude', wp_json_encode( $rules ) );
	}

	/**
	 * WP Super Cache — add the `woo-feed` token to the exclusion CSV.
	 *
	 * @return void
	 */
	public function exclude_from_wp_super(): void {
		if ( ! function_exists( 'wpsc_init' ) ) {
			return;
		}

		$csv = get_option( 'ossdl_off_exclude' );
		if ( is_string( $csv ) && false === strpos( $csv, 'woo-feed' ) ) {
			$list   = array_filter( array_map( 'trim', explode( ',', $csv ) ) );
			$list[] = 'woo-feed';
			update_option( 'ossdl_off_exclude', implode( ',', $list ) );
		}
	}

	/**
	 * Breeze — exclude feed file extensions from CDN content.
	 *
	 * @return void
	 */
	public function exclude_from_breeze(): void {
		if ( ! class_exists( 'Breeze_Admin' ) ) {
			return;
		}

		$settings = maybe_unserialize( get_option( 'breeze_cdn_integration' ) );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$existing  = isset( $settings['cdn-exclude-content'] ) && is_array( $settings['cdn-exclude-content'] )
			? $settings['cdn-exclude-content']
			: array();
		$feed_exts = array( '.xml', '.csv', '.tsv', '.txt', '.xls' );
		$merged    = array_values( array_unique( array_merge( $existing, $feed_exts ) ) );
		if ( $merged !== $existing ) {
			$settings['cdn-exclude-content'] = $merged;
			update_option( 'breeze_cdn_integration', $settings );
		}
	}

	/**
	 * WP-Optimize — add the feed dir to the page-cache exception URLs.
	 *
	 * @return void
	 */
	public function exclude_from_wp_optimize(): void {
		if ( ! class_exists( 'WP_Optimize' ) ) {
			return;
		}

		$config = maybe_unserialize( get_option( 'wpo_cache_config' ) );
		if ( ! is_array( $config ) || empty( $config['enable_page_caching'] ) ) {
			return;
		}

		$urls = isset( $config['cache_exception_urls'] ) && is_array( $config['cache_exception_urls'] )
			? $config['cache_exception_urls']
			: array();

		if ( ! in_array( $this->feed_path, $urls, true ) ) {
			$urls[]                         = $this->feed_path;
			$config['cache_exception_urls'] = $urls;
			update_option( 'wpo_cache_config', $config );
		}
	}

	/**
	 * Swift Performance Lite — add the feed dir to the exclude strings.
	 *
	 * @return void
	 */
	public function exclude_from_swift(): void {
		if ( ! class_exists( 'Swift_Performance_Lite' ) ) {
			return;
		}

		$options = maybe_unserialize( get_option( 'swift_performance_options' ) );
		$options = is_array( $options ) ? $options : array();

		$strings = isset( $options['exclude-strings'] ) && is_array( $options['exclude-strings'] )
			? $options['exclude-strings']
			: array();

		if ( ! in_array( $this->feed_path, $strings, true ) ) {
			$strings[]                  = $this->feed_path;
			$options['exclude-strings'] = $strings;
			update_option( 'swift_performance_options', $options );
		}
	}

	/**
	 * Speed Booster Pack — add the feed dir to the caching exclusion list.
	 *
	 * Rewritten to exclude the feed directory token instead of enumerating
	 * every feed URL via the removed raw-SQL helper.
	 *
	 * @return void
	 */
	public function exclude_from_speed_booster(): void {
		if ( ! class_exists( 'Speed_Booster_Pack' ) ) {
			return;
		}

		$settings = maybe_unserialize( get_option( 'sbp_options' ) );
		if ( ! is_array( $settings ) || ! isset( $settings['caching_exclude_urls'] ) ) {
			return;
		}

		$list = (string) $settings['caching_exclude_urls'];
		if ( false === strpos( $list, $this->feed_path ) ) {
			$settings['caching_exclude_urls'] = '' === trim( $list )
				? $this->feed_path
				: $list . "\n" . $this->feed_path;
			update_option( 'sbp_options', $settings );
		}
	}

	/**
	 * Comet Cache — add the feed dir to the exclude URIs.
	 *
	 * @return void
	 */
	public function exclude_from_comet(): void {
		if ( ! function_exists( 'is_plugin_active' )
			|| ! is_plugin_active( 'comet-cache/comet-cache.php' ) ) {
			return;
		}

		$settings = maybe_unserialize( get_option( 'comet_cache_options' ) );
		if ( ! is_array( $settings ) || ! isset( $settings['exclude_uris'] ) ) {
			return;
		}

		$uris = (string) $settings['exclude_uris'];
		if ( false === strpos( $uris, $this->feed_path ) ) {
			$settings['exclude_uris'] = '' === trim( $uris )
				? $this->feed_path
				: $uris . "\n" . $this->feed_path;
			update_option( 'comet_cache_options', $settings );
		}
	}

	/**
	 * Hyper Cache — add the feed dir to the reject URIs.
	 *
	 * @return void
	 */
	public function exclude_from_hyper(): void {
		if ( ! class_exists( 'HyperCache' ) ) {
			return;
		}

		$settings = maybe_unserialize( get_option( 'hyper-cache' ) );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$reject = isset( $settings['reject_uris'] ) && is_array( $settings['reject_uris'] )
			? $settings['reject_uris']
			: array();

		if ( ! in_array( $this->feed_path, $reject, true ) ) {
			$reject[]                        = $this->feed_path;
			$settings['reject_uris']         = $reject;
			$settings['reject_uris_enabled'] = 1;
			update_option( 'hyper-cache', $settings );
		}
	}
}
