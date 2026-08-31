<?php
/**
 * Bootstrap — V8 Engine entry point and router.
 *
 * Routes between V8 and V5 engines based on user preference.
 * When V8 is active, orchestrates the full four-phase boot sequence:
 *   Phase 1: register() on ALL providers → services bound
 *   Phase 2: apply_filters('ctxfeed_container_bindings') → external services
 *   Phase 3: boot() on ALL providers → hooks registered
 *   Phase 4: do_action('ctxfeed_v8_booted') → extensions initialize
 *
 * @package    CTXFeed
 * @subpackage V8
 * @since      8.0.0
 * @implements CORE-FRD-7.1, CORE-FRD-7.2, CORE-FRD-7.3, CORE-FRD-7.4, CORE-FRD-7.5
 */

namespace CTXFeed\V8;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\CoreServiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V8 engine bootstrap and router.
 *
 * @since 8.0.0
 */
class Bootstrap {

	/**
	 * The DI container instance.
	 *
	 * @since 8.0.0
	 * @var Container
	 */
	private Container $container;

	/**
	 * Initialize the V8 engine.
	 *
	 * Called from woo-feed.php on `plugins_loaded`. Routes to V8
	 * or returns immediately for V5 passthrough.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-7.2, CORE-FRD-7.4
	 *
	 * @return void
	 */
	public function init(): void {

		if ( ! $this->should_use_v8() ) {
			return; // V5 continues normally.
		}

		$this->boot_v8();
	}

	/**
	 * Get the DI container instance.
	 *
	 * Provides a fallback access point for edge cases where DI is impractical.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-7.5
	 *
	 * @return Container The DI container instance.
	 */
	public function get_container(): Container {

		return $this->container;
	}

	/**
	 * Determine if the V8 engine should be used.
	 *
	 * V8 is the sole engine — the V5/V8 engine switch has been removed, so this
	 * always returns true. Retained as a single seam in case boot ever needs to
	 * be gated again.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-7.1
	 *
	 * @return bool Always true.
	 */
	private function should_use_v8(): bool {

		return true;
	}

	/**
	 * Boot the V8 engine.
	 *
	 * Executes the full four-phase boot sequence:
	 * 1. Define constants.
	 * 2. Require autoloader.
	 * 3. Create Container and add all ServiceProviders.
	 * 4. Phase 1: register() on ALL providers.
	 * 5. Phase 2: apply external bindings filter.
	 * 6. Phase 3: boot() on ALL providers.
	 * 7. Phase 4: fire ctxfeed_v8_booted action.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-7.2
	 *
	 * @return void
	 */
	private function boot_v8(): void {

		$this->define_constants();

		// Require PSR-4 autoloader.
		require_once __DIR__ . '/autoload.php';

		// Create container singleton.
		$this->container = Container::get_instance();

		// Add all module ServiceProviders in dependency order.
		foreach ( $this->get_providers() as $provider ) {
			$this->container->add_provider( $provider );
		}

		// Phase 1: register() on ALL providers — binds services.
		$this->container->register_providers();

		// Phase 2: apply external bindings filter.
		$this->container->apply_external_bindings();

		// Phase 3: boot() on ALL providers — registers hooks.
		$this->container->boot();
	}

	/**
	 * Define V8 engine constants.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function define_constants(): void {

		if ( ! defined( 'CTXFEED_V8_VERSION' ) ) {
			define( 'CTXFEED_V8_VERSION', '8.0.1' );
		}

		if ( ! defined( 'CTXFEED_V8_PATH' ) ) {
			define( 'CTXFEED_V8_PATH', __DIR__ . '/' );
		}

		if ( ! defined( 'CTXFEED_V8_URL' ) ) {
			define( 'CTXFEED_V8_URL', plugin_dir_url( __FILE__ ) );
		}

		$this->define_legacy_constants();
	}

	/**
	 * Define the WOO_FEED_* plugin constants historically owned by V5's
	 * includes/classes/class-woo-feed-constants.php.
	 *
	 * Relocated here so V8 no longer depends on that V5 file (which is
	 * deleted with the V5 engine). Every define is defined()-guarded, so
	 * while V5 is still present — and defines these first, early in
	 * woo-feed.php — this is a harmless no-op; it becomes the single source
	 * once V5 is removed. Paths/URL derive from the plugin entry file
	 * (WOO_FEED_FREE_FILE) or, as a fallback, from this file's location
	 * ({plugin}/V8/Bootstrap.php).
	 *
	 * V8 itself needs only WOO_FEED_FREE_PATH / _ADMIN_PATH / _VERSION; the
	 * rest are kept for the Pro plugin and third-party back-compat.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function define_legacy_constants(): void {

		$main_file = defined( 'WOO_FEED_FREE_FILE' )
			? WOO_FEED_FREE_FILE
			: dirname( __DIR__ ) . '/woo-feed.php';

		if ( ! defined( 'WOO_FEED_FREE_VERSION' ) ) {
			// Single source of truth is the plugin header — read it rather
			// than duplicate the version string. Falls back to the V8 version.
			$version = '';
			if ( function_exists( 'get_file_data' ) && file_exists( $main_file ) ) {
				$header  = get_file_data( $main_file, array( 'Version' => 'Version' ) );
				$version = isset( $header['Version'] ) ? $header['Version'] : '';
			}
			define( 'WOO_FEED_FREE_VERSION', '' !== $version ? $version : CTXFEED_V8_VERSION );
		}

		if ( ! defined( 'WOO_FEED_FREE_PATH' ) ) {
			define( 'WOO_FEED_FREE_PATH', plugin_dir_path( $main_file ) );
		}

		if ( ! defined( 'WOO_FEED_PLUGIN_URL' ) ) {
			define( 'WOO_FEED_PLUGIN_URL', trailingslashit( plugin_dir_url( $main_file ) ) );
		}

		if ( ! defined( 'WOO_FEED_MIN_PHP_VERSION' ) ) {
			define( 'WOO_FEED_MIN_PHP_VERSION', '7.4' );
		}

		if ( ! defined( 'WOO_FEED_LOG_DIR' ) ) {
			$upload_dir = wp_get_upload_dir();
			define( 'WOO_FEED_LOG_DIR', $upload_dir['basedir'] . '/woo-feed/logs/' );
		}
	}

	/**
	 * Get the ordered list of module ServiceProviders.
	 *
	 * Registration order follows dependency order as defined in CORE-FRD-7.3.
	 * AI module is a separate extension plugin — NOT registered here.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-7.3
	 *
	 * @return \CTXFeed\V8\Core\ServiceProvider[] Ordered array of ServiceProvider instances.
	 */
	private function get_providers(): array {

		return array(
			new Core\CoreServiceProvider(),                  // 1. Core (config_factory, hook_manager).
			new Utility\UtilityServiceProvider(),             // 2. Utility (filesystem, encryption, cache, perf).
			new Product\ProductServiceProvider(),             // 3. Product (cache_warmer, product_repository, attribute_resolver).
			new Transform\TransformServiceProvider(),         // 4. Transform (transform_pipeline).
			new Filter\FilterServiceProvider(),               // 5. Filter (filter_manager).
			new Template\TemplateServiceProvider(),           // 6. Template (stream_writer, template_registry).
			new Feed\FeedServiceProvider(),                   // 7. Feed (feed_generator, feed_scheduler).
			new Channel\ChannelServiceProvider(),             // 8. Channel (channel_registry).
			new API\ApiServiceProvider(),                     // 9. API (rest endpoints).
			new Admin\AdminServiceProvider(),                 // 10. Admin (admin pages, assets).
			new Dashboard\DashboardServiceProvider(),         // 11. Dashboard (metrics, analytics).
			new CustomFields\CustomFieldServiceProvider(),    // 12. CustomFields (product meta, brand taxonomy).
			// 13 (was Compat\CompatServiceProvider) — removed. The third-party
			// plugin compatibility layer lives in the `ctx-compatibility/`
			// submodule (always loaded from woo-feed.php). The deleted V8/Compat
			// module was scaffolding-only — empty adapter stubs and a
			// LegacyBridge that listened to never-fired hooks.
			new Migration\MigrationServiceProvider(),         // 14. Migration (v5→v8, rollback).
		);
	}
}
