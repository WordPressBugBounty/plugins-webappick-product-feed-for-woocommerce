<?php
/**
 * The plugin bootstrap file
 *
 * @link              https://webappick.com
 * @since             1.0.0
 * @package           Woo_Feed
 *
 * @wordpress-plugin
 * Plugin Name:       CTX Feed
 * Plugin URI:        https://webappick.com/
 * Description:       Easily generate woocommerce product feed for any marketing channel like Google Shopping(Merchant), Facebook Remarketing, Bing, eBay & more. Support 100+ Merchants.
 * Version:           8.0.1
 * Author:            WebAppick
 * Author URI:        https://webappick.com/
 * License:           GPL v2
 * License URI:       http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       woo-feed
 * Domain Path:       /languages
 *
 * WP Requirement & Test
 * Requires at least: 4.4
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * WC Requirement & Test
 * WC requires at least: 3.3
 * WC tested up to: 11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	die(); // If this file is called directly, abort.
}

/**
 * Fatal-safe PHP floor.
 *
 * V8 uses PHP 7.4 syntax (typed properties, arrow functions); parsing those on
 * PHP < 7.4 white-screens the whole site, which WordPress then treats as a
 * fatal and pauses the plugin. The `Requires PHP` header blocks wp.org from
 * delivering the update to old-PHP sites, but a MANUAL ZIP upload or WP-CLI
 * update bypasses that header — so guard at runtime: degrade to an admin
 * notice and stop loading, rather than fatal, on an unsupported PHP version.
 *
 * @since 8.0.0
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version. */
					__( 'CTX Feed requires PHP %1$s or higher and has been paused — your server is running PHP %2$s. Please ask your host to update PHP, then CTX Feed will resume automatically.', 'woo-feed' ),
					'7.4',
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

if ( ! defined( 'WOO_FEED_FREE_FILE' ) ) {
	/**
	 * Plugin Base File
	 *
	 * @since 3.1.41
	 * @var string
	 */
	define( 'WOO_FEED_FREE_FILE', __FILE__ );
}

if ( ! defined( 'WOO_FEED_PLUGIN_FILE' ) ) {
	$woo_feed_plugin_file = explode( DIRECTORY_SEPARATOR, __FILE__ );
	$woo_feed_plugin_file = end( $woo_feed_plugin_file );
	/**
	 * Plugin file
	 *
	 * @since 5.0.0
	 * @var   string
	 */
	define( 'WOO_FEED_PLUGIN_FILE', $woo_feed_plugin_file );
}

require_once __DIR__ . '/constants.php';

if ( ! function_exists( 'request_filesystem_credentials' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}

Woo_Feed_Constants::defined_constants();

/*
 * The V5-era libs/autoload.php (a spl_autoload_register for `WebAppick\*`
 * classes) is no longer loaded: no surviving code references those classes,
 * and V8 autoloads through V8/autoload.php below. The libs/ folder has been
 * moved out of the plugin (03-source/ctx-old/) alongside V5.
 */

/**
 * V8 Engine Detection.
 *
 * V8 is the sole engine. This constant now only gates the V8 boot block at the
 * end of this file; the `! CTXFEED_V8_ACTIVE` V5 bootstrap and Ajax/REST blocks
 * it used to guard were unreachable dead code and have been removed.
 *
 * @since 8.0.0
 */
if ( ! defined( 'CTXFEED_V8_ACTIVE' ) ) {
	// V8 is the sole engine — the V5/V8 engine switch has been removed, so this
	// always resolves to true and the V8 engine always boots.
	define( 'CTXFEED_V8_ACTIVE', true );
}

/**
 * Development Mode — enables all Pro/Extension features via FeatureGate.
 *
 * Ships as false: FeatureGate::has() defaults to false for every feature, so
 * Pro-gated UI and endpoints stay locked unless the Pro plugin unlocks them.
 *
 * During development, override it WITHOUT editing this file by defining it
 * ahead of the plugin in wp-config.php:
 *   define( 'CTXFEED_DEV_MODE', true );
 * That makes every feature default to true so Pro-gated UI and endpoints work
 * without the Pro plugin installed. Must never be true in production.
 *
 * @since 8.0.0
 */
if ( ! defined( 'CTXFEED_DEV_MODE' ) ) {
	define( 'CTXFEED_DEV_MODE', false );
}

/**
 * Third-party plugin compatibility — cut over to the `compatibility/` layer.
 *
 * The legacy `ctx-compatibility/` submodule (whose shims made runtime calls
 * into CTXFeed\V5\*) has been superseded by the V5-free `compatibility/` layer
 * (namespace CTXFeed\Compat). Its autoloader, the core-extraction provider
 * Bootstrap, and the shim CompatibilityFactory are all wired in the V8 block
 * below. The old submodule is intentionally NO LONGER loaded here.
 *
 * The `ctx-compatibility/` folder is retained on disk only until the cutover
 * is confirmed in production, then removed. To roll back, restore this file.
 */
// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- Disabled require kept verbatim: the docblock above states this is the rollback switch for the compatibility/ cutover.
// require_once WOO_FEED_FREE_PATH . 'ctx-compatibility/autoload.php'; // superseded by compatibility/ (CTXFeed\Compat)

/**
 * V5 engine — no longer loaded under V8.
 *
 * `V5/autoload.php` (a lazy spl_autoload_register resolver) is intentionally
 * NOT registered. V8 references a handful of V5 utility classes (Status, Logs,
 * Cache, Helper, CTX_WC_Log_Handler, InputCustomFiled) ONLY through
 * `class_exists()` / `instanceof` guards, so with the V5 autoloader absent
 * those classes never resolve and the guarded paths degrade gracefully.
 *
 * The `V5/` folder is retained on disk only until deletion is confirmed. To
 * roll back, restore this file.
 */
// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar -- Disabled require kept verbatim: the docblock above states this is the rollback switch for un-loading the V5 autoloader.
// require_once WOO_FEED_FREE_PATH . 'V5/autoload.php'; // V5 no longer loaded under V8

/**
 * NOTE: the legacy self-protection that deactivated the Pro plugin has been
 * REMOVED for the V8 thin-unlock model. In the old architecture the Pro plugin
 * bundled a full copy of Free, so the two could not run together and Free
 * force-deactivated Pro. The new Pro (CTXFeed\Pro) bundles no Free and is
 * DESIGNED to run alongside this plugin — deactivating it here silently broke
 * Pro activation (WP would report "Plugin activated", then Free removed it on
 * the next load).
 */
/**
 * Installer — the V8 installer (root installer.php) owns the
 * activation/deactivation lifecycle and is loaded in the V8 boot block below.
 * The V5-era includes/class-woo-feed-installer.php is no longer loaded; it
 * preserved the same lifecycle, so nothing is lost.
 *
 * Uses Tracker — the V5-era includes/classes/class-woo-feed-webappick-api.php
 * (WooFeedWebAppickAPI) is likewise no longer loaded. It only ever declared a
 * class, and the sole surviving instantiations lived in the removed V5 blocks
 * and admin/partials/woo-feed-settings.php (unreachable under V8). Telemetry is
 * now wired by V8/AppServices, started from the V8 boot block below. To roll
 * back, restore this file.
 */

/**
 * HPOS compatibility.
 */
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	add_action(
		'before_woocommerce_init',
		function () {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		} 
	);
}

/**
 * Boot V8 Engine.
 *
 * Always loads: CTXFEED_V8_ACTIVE is hard-defined true (the ctxfeed_engine
 * switch has been removed). The Bootstrap class handles the 4-phase boot
 * sequence:
 *   Phase 1: register() — bind service factories
 *   Phase 2: (reserved)
 *   Phase 3: boot() — cross-module wiring + hooks
 *   Phase 4: (reserved)
 *
 * V8 mode is fully self-contained — V5 helpers are NOT loaded.
 * All feed CRUD and generation is handled by V8 classes.
 *
 * @since 8.0.0
 */
if ( CTXFEED_V8_ACTIVE && file_exists( __DIR__ . '/V8/Bootstrap.php' ) ) {
	require_once __DIR__ . '/V8/autoload.php';

	// V8 plugin lifecycle (activation / deactivation / upgrade). Self-contained
	// replacement for the V5 installer; survives removal of includes/ + V5.
	// __DIR__, NOT WOO_FEED_FREE_PATH: an already-loaded OLD Pro (which bundled
	// Free) defines WOO_FEED_FREE_PATH -> its own libs/ via `if ( ! defined )`,
	// so during the two-step upgrade window that constant can point at a folder
	// with no installer.php and a WOO_FEED_FREE_PATH require would fatal the
	// whole site. The plugin's OWN files must always load relative to this file.
	require_once __DIR__ . '/installer.php';

	// V8 telemetry (WebAppick AppServices SDK) — usage insights + promotions.
	// Admin/cron only; the SDK gates opt-in internally. The upsell admin UI
	// (review-nag, compat notices, Premium/Our-Plugins pages) is deferred to
	// the React admin.
	add_action(
		'init',
		function () {
			$needs_appservices = is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );
			if ( $needs_appservices && class_exists( '\\CTXFeed\\V8\\AppServices\\AppServices' ) ) {
				\CTXFeed\V8\AppServices\AppServices::instance();
			}
		},
		5 
	);

	add_action(
		'plugins_loaded',
		function () {
			$bootstrap = new \CTXFeed\V8\Bootstrap();
			$bootstrap->init();
		},
		20 
	); // Priority 20: after WooCommerce (10) loads.

	// Core-extraction compatibility providers.
	//
	// The third-party-plugin logic (SEO / multi-vendor / product-type /
	// currency) that used to be hardcoded in the V8 resolvers has been
	// relocated into `compatibility/Providers/`. Those providers answer new
	// `ctxfeed_*` hook seams the resolvers now fire, so they MUST be active or
	// the affected feed data regresses. They hook distinct seams from the
	// legacy `woo_feed_filter_product_*` bridge, so this never collides with
	// the `ctx-compatibility/` submodule below.
	//
	// NOTE: this activates ONLY the extraction providers. The rewritten
	// submodule shims under `compatibility/Shims/` stay dormant (not wired) —
	// the live submodule keeps handling those. See compatibility/ARCHITECTURE.md.
	if ( file_exists( __DIR__ . '/compatibility/autoload.php' ) ) {
		require_once __DIR__ . '/compatibility/autoload.php';
		add_action(
			'plugins_loaded',
			static function () {
				if ( class_exists( '\\CTXFeed\\Compat\\Bootstrap' ) ) {
					\CTXFeed\Compat\Bootstrap::init();
				}
			},
			20 
		);
	}

	// Initialise the third-party plugin compatibility shims (V5-free rewrite).
	//
	// Cut over from the legacy `ctx-compatibility/` submodule to the new
	// `compatibility/` layer: this activates CTXFeed\Compat\CompatibilityFactory,
	// which scans compatibility/Shims/, intersects with active third-party
	// plugins via is_plugin_active(), and instantiates each matching shim. The
	// shims register their WP filters at constructor time; those filters fire
	// later when V8's FeedGenerator triggers the V5-shaped hooks during
	// generation — identical behaviour to the old submodule, minus the V5
	// dependency.
	//
	// Priority 25 = after V8 Bootstrap (20) and the provider Bootstrap (20).
	if ( class_exists( '\\CTXFeed\\Compat\\CompatibilityFactory' ) ) {
		add_action(
			'plugins_loaded',
			static function () {
				\CTXFeed\Compat\CompatibilityFactory::init();
			},
			25
		);
	}

	// Two-step upgrade window guard: an OLD Pro (< 8.0.0) running beside this
	// V8 Free bundles its own V5 engine, so both engines would hook feed
	// generation and register the admin UI at once. We can't stop the old Pro
	// from loading, but we warn the admin to finish the update. The check runs
	// inside the notice callback so WOO_FEED_PRO_VERSION (defined by Pro at
	// include time) is resolved by the time admin_notices fires.
	add_action(
		'admin_notices',
		static function () {
			if ( ! defined( 'WOO_FEED_PRO_VERSION' ) || version_compare( WOO_FEED_PRO_VERSION, '8.0.0', '>=' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: the outdated CTX Feed Pro version. */
					__( 'CTX Feed was updated to 8.0, but CTX Feed Pro (%s) is still on an older version. Please update CTX Feed Pro to 8.0 or higher — running the two together can duplicate menus and feed jobs until Pro is updated.', 'woo-feed' ),
					WOO_FEED_PRO_VERSION
				)
			);
			echo '</p></div>';
		}
	);
}

// End of file woo-feed.php.
