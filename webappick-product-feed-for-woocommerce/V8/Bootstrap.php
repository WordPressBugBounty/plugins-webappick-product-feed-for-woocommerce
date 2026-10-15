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
	 * Option holding the last boot failure (CBT-802).
	 *
	 * @since 8.0.37
	 */
	const BOOT_FAILURE_OPTION = 'ctxfeed_boot_failure';

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

		$booted = self::guarded(
			function () {
				$this->boot_v8();
			}
		);
		if ( $booted ) {
			self::notify_recent_boot_failure();
		}
	}

	/**
	 * Run a boot step so a failure can never take the site down (CBT-802).
	 *
	 * Live (#69543, Hostinger, PHP 8.3): every 40-90 minutes one CTX Feed
	 * class could not be loaded although its file was on disk, and the
	 * uncaught "Class not found" error in a provider's boot() fataled every
	 * request — Elementor, WooCommerce and the rest never finished loading,
	 * CPU sat near 100% for hours. Now any Throwable from the step is caught:
	 * the hooks the step had already added are removed again (they would
	 * resolve the same missing class later in the request and fatal there),
	 * the failure is recorded for administrators, and CTX Feed simply stays
	 * off for that page load while the rest of the site works.
	 *
	 * @since 8.0.37
	 *
	 * @param callable $step Boot step.
	 * @return bool True when the step completed.
	 */
	public static function guarded( callable $step ): bool {
		global $wp_filter;

		// Arrays copy on write: this keeps the current callback lists
		// without copying anything unless a hook changes afterwards.
		$before = array();
		if ( is_array( $wp_filter ) ) {
			foreach ( $wp_filter as $hook => $hook_object ) {
				if ( is_object( $hook_object ) && isset( $hook_object->callbacks ) && is_array( $hook_object->callbacks ) ) {
					$before[ $hook ] = $hook_object->callbacks;
				}
			}
		}

		try {
			call_user_func( $step );
			return true;
		} catch ( \Throwable $e ) {
			self::remove_hooks_added_since( $before );
			self::record_boot_failure( $e );
			return false;
		}
	}

	/**
	 * Remove every hook callback added since the snapshot was taken.
	 *
	 * @since 8.0.37
	 *
	 * @param array<string,array> $before Hook name => callbacks (by priority) before the step.
	 * @return void
	 */
	private static function remove_hooks_added_since( array $before ): void {
		global $wp_filter;

		if ( ! is_array( $wp_filter ) || ! function_exists( 'remove_filter' ) ) {
			return;
		}
		foreach ( $wp_filter as $hook => $hook_object ) {
			if ( ! is_object( $hook_object ) || ! isset( $hook_object->callbacks ) || ! is_array( $hook_object->callbacks ) ) {
				continue;
			}
			foreach ( $hook_object->callbacks as $priority => $callbacks ) {
				foreach ( (array) $callbacks as $id => $callback ) {
					if ( isset( $before[ $hook ][ $priority ][ $id ] ) || ! isset( $callback['function'] ) ) {
						continue;
					}
					remove_filter( $hook, $callback['function'], $priority );
				}
			}
		}
	}

	/**
	 * Record a failed boot: an admin notice for this page load, and a
	 * stored record (at most one write per 10 minutes) so administrators
	 * also learn about failures that happened in background requests.
	 *
	 * The record says whether the class file was on disk when the error
	 * was caught — "missing" means something removed it (a reinstall is
	 * needed), "on disk" means the server failed to read it for a moment.
	 *
	 * @since 8.0.37
	 *
	 * @param \Throwable $error The error.
	 * @return void
	 */
	private static function record_boot_failure( \Throwable $error ): void {
		$message = $error->getMessage();
		$file    = '';
		if ( preg_match( '/(?:Class|Interface|Trait|Enum) ["\']?\\\\?CTXFeed\\\\V8\\\\([A-Za-z0-9_\\\\]+)["\']? not found/', $message, $match ) ) {
			$file = 'V8/' . str_replace( '\\', '/', $match[1] ) . '.php';
		}
		$on_disk = '' !== $file && is_readable( dirname( __DIR__ ) . '/' . $file );

		$record = array(
			'time'    => time(),
			'message' => substr( $message, 0, 300 ),
			'file'    => $file,
			'on_disk' => $on_disk,
			'version' => defined( 'CTXFEED_V8_VERSION' ) ? CTXFEED_V8_VERSION : '',
		);

		if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) ) {
			$stored = get_option( self::BOOT_FAILURE_OPTION, array() );
			if ( ! is_array( $stored ) || empty( $stored['time'] ) || time() - (int) $stored['time'] >= 600 ) {
				update_option( self::BOOT_FAILURE_OPTION, $record, false );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- One line per 10 minutes so the host's PHP log shows the failure next to its own errors.
				error_log( 'CTX Feed paused for this request: ' . $record['message'] . ( '' !== $file ? ' (' . $file . ( $on_disk ? ' is on disk' : ' is missing' ) . ')' : '' ) );
			}
		}

		if ( function_exists( 'add_action' ) ) {
			add_action(
				'admin_notices',
				static function () use ( $record ) {
					self::render_boot_failure_notice( $record );
				}
			);
		}
	}

	/**
	 * Show administrators a recent boot failure (within 24 hours), also on
	 * page loads that booted fine — failures in background requests are
	 * otherwise invisible.
	 *
	 * @since 8.0.37
	 *
	 * @return void
	 */
	public static function notify_recent_boot_failure(): void {
		if ( ! function_exists( 'is_admin' ) || ! is_admin() || ! function_exists( 'get_option' ) ) {
			return;
		}
		$stored = get_option( self::BOOT_FAILURE_OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored['time'] ) || time() - (int) $stored['time'] > DAY_IN_SECONDS ) {
			return;
		}
		add_action(
			'admin_notices',
			static function () use ( $stored ) {
				self::render_boot_failure_notice( $stored );
			}
		);
	}

	/**
	 * Print the boot-failure notice (administrators only, once per page).
	 *
	 * @since 8.0.37
	 *
	 * @param array $record Failure record.
	 * @return void
	 */
	private static function render_boot_failure_notice( array $record ): void {
		static $shown = false;
		if ( $shown || ! function_exists( 'current_user_can' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$shown = true;

		$file = isset( $record['file'] ) ? (string) $record['file'] : '';
		$when = function_exists( 'wp_date' ) && ! empty( $record['time'] ) ? wp_date( 'Y-m-d H:i', (int) $record['time'] ) : '';

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'CTX Feed paused itself on a page load', 'woo-feed' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: 1: date and time, 2: plugin file path */
				__( '(last: %1$s) because one of its files could not be loaded: %2$s. Your site kept working; CTX Feed was off for that page load only.', 'woo-feed' ),
				$when,
				'' !== $file ? $file : ( isset( $record['message'] ) ? (string) $record['message'] : '' )
			)
		);
		echo ' ';
		if ( '' !== $file && empty( $record['on_disk'] ) ) {
			echo esc_html__( 'The file is missing. Please reinstall CTX Feed, and check whether a security or malware scanner on your server removed it.', 'woo-feed' );
		} else {
			echo esc_html__( 'The file is on disk, so the server could not read it for a moment. If this keeps happening, please contact your host and our support with this message.', 'woo-feed' );
		}
		echo '</p></div>';
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

		// A Pro build older than the engine migration (CBT-649) flips the
		// gates but ships no engines — close them so the UI never unlocks
		// what nothing executes; NoticeProvider explains the update.
		Status\ProHandshake::close_gates();

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
			define( 'CTXFEED_V8_VERSION', '8.0.37' );
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
			define( 'WOO_FEED_LOG_DIR', \CTXFeed\V8\Utility\LogDirGuard::dir() );
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
