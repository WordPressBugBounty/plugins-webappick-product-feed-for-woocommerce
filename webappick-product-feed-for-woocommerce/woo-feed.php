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
 * Description:       Easily generate woocommerce product feed for any marketing channel like Google, Meta, Tiktok, X, SnapChat, ChatGPT, Perplexity & more. Support 220+ Channels.
 * Version:           8.0.40
 * Author:            WebAppick
 * Author URI:        https://webappick.com/
 * License:           GPL v2
 * License URI:       http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       woo-feed
 * Domain Path:       /languages
 *
 * WP Requirement & Test
 * Requires at least: 6.2
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

/**
 * Hold CTX Feed's own scheduled runs while CTX Feed itself is paused
 * (CBT-807). Its engine did not load in this request, so Action Scheduler
 * would find no callback for a feed batch, finalize or scheduled update and
 * mark it failed — the run would be lost although the next request (after
 * the update) boots fine. Instead the action is queued again with its own
 * keyed arguments, 60 seconds later, unless a pending copy already exists
 * (for a scheduled update: one due soon — the series' next run does not
 * count). The cap: after 10 minutes of holding (filter
 * ctxfeed_update_wait_max_seconds) actions are no longer re-queued. Its start
 * is kept in the non-autoloaded option ctxfeed_free_hold_since; a booted
 * request clears it as soon as one of these actions runs.
 *
 * Called with true while paused, with false from a booted request. A closure
 * in a variable: no global function or class is declared (CBT-698), and every
 * Action Scheduler call is existence-checked and inside a try.
 *
 * @since 8.0.38
 */
$ctxfeed_hold_runs = static function ( $paused = true ) {
	static $registered = array();
	$mode              = $paused ? 'hold' : 'clear';
	if ( isset( $registered[ $mode ] ) ) {
		return;
	}
	$registered[ $mode ] = true;
	$hooks               = array( 'ctxfeed_generate_batch', 'ctxfeed_finalize_feed', 'ctxfeed_recurring_generation' );
	$option              = 'ctxfeed_free_hold_since';

	if ( ! $paused ) {
		$clear = static function () use ( $option ) {
			try {
				if ( false !== get_option( $option, false ) ) {
					delete_option( $option );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		};
		foreach ( $hooks as $hook ) {
			add_action( $hook, $clear, 1, 0 );
		}
		return;
	}

	$current = 0;
	$note    = static function ( $action_id = 0 ) use ( &$current ) {
		$current = (int) $action_id;
	};
	$hold    = static function () use ( &$current, $hooks, $option ) {
		static $logged = false;
		$action_id     = $current;
		$current       = 0;
		try {
			// The engine booted after all (only the compatibility layer failed
			// at include time): its own handler takes the action.
			if ( class_exists( 'CTXFeed\\V8\\Bootstrap', false ) && is_callable( array( 'CTXFeed\\V8\\Bootstrap', 'booted' ) ) && call_user_func( array( 'CTXFeed\\V8\\Bootstrap', 'booted' ) ) ) {
				return;
			}
			if ( $action_id <= 0 || ! class_exists( 'ActionScheduler', false ) || ! is_callable( array( 'ActionScheduler', 'store' ) )
				|| ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_schedule_single_action' ) ) {
				return;
			}
			$store  = call_user_func( array( 'ActionScheduler', 'store' ) );
			$action = is_object( $store ) && is_callable( array( $store, 'fetch_action' ) ) ? $store->fetch_action( $action_id ) : null;
			if ( ! is_object( $action ) || ! is_callable( array( $action, 'get_hook' ) ) || ! is_callable( array( $action, 'get_args' ) ) ) {
				return;
			}
			$hook = (string) $action->get_hook();
			$args = $action->get_args();
			if ( ! in_array( $hook, $hooks, true ) || ! is_array( $args ) ) {
				return;
			}
			$group = is_callable( array( $action, 'get_group' ) ) ? (string) $action->get_group() : '';

			$delay = max( 30, min( 300, (int) apply_filters( 'ctxfeed_update_wait_delay', 60 ) ) );
			$max   = min( 1800, max( 2 * $delay, (int) apply_filters( 'ctxfeed_update_wait_max_seconds', 600 ) ) );
			$now   = time();

			// A new episode starts when nothing was held for a day.
			$record = get_option( $option, array() );
			$since  = is_array( $record ) && isset( $record['since'] ) ? (int) $record['since'] : 0;
			$last   = is_array( $record ) && isset( $record['last'] ) ? (int) $record['last'] : 0;
			if ( $since <= 0 || $since > $now || $now - $last > 86400 ) {
				$since = $now;
			}
			update_option(
				$option,
				array(
					'since' => $since,
					'last'  => $now,
				),
				false
			);

			if ( $now - $since >= $max ) {
				if ( ! $logged ) {
					$logged = true;
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- One line per request: the run is dropped instead of waiting longer.
					error_log( 'CTX Feed has been paused for over ' . $max . ' seconds: its scheduled action ' . $hook . ' is no longer held.' );
				}
				return;
			}

			$query = array(
				'hook'     => $hook,
				'args'     => $args,
				'status'   => 'pending',
				'per_page' => 1,
			);
			if ( 'ctxfeed_recurring_generation' === $hook ) {
				$query['date']         = $now + $delay + 30;
				$query['date_compare'] = '<=';
			}
			$pending = as_get_scheduled_actions( $query, 'ids' );
			if ( ! empty( $pending ) ) {
				return;
			}
			as_schedule_single_action( $now + $delay, $hook, $args, $group );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Holding a run must never fail the request; the error still reaches the host's PHP log.
			error_log( 'CTX Feed could not hold a scheduled action while paused: ' . substr( $e->getMessage(), 0, 300 ) );
		}
	};
	add_action( 'action_scheduler_begin_execute', $note, 10, 1 );
	foreach ( $hooks as $hook ) {
		add_action( $hook, $hold, 5, 0 );
	}
};

/**
 * Pause CTX Feed for this request (CBT-734, CBT-806): tell administrators and
 * CTX Feed Pro that the plugin's files are being updated, instead of failing,
 * and hold CTX Feed's scheduled runs meanwhile (CBT-807). A closure in a
 * variable, so no global function is declared (CBT-698).
 *
 * @since 8.0.38
 */
$ctxfeed_pause = static function () use ( $ctxfeed_hold_runs ) {
	$ctxfeed_hold_runs( true );
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'CTX Feed is paused because some of its files are missing. This is normal for a moment while the plugin is being updated. If this message stays after the update has finished, reinstall CTX Feed from Plugins > Add New Plugin.', 'woo-feed' );
			echo '</p></div>';
		}
	);
	// Tell CTX Feed Pro the free plugin is mid-update, not uninstalled, so it
	// does not start its "install the free plugin" onboarding meanwhile.
	add_filter( 'ctxfeed_free_installation_incomplete', '__return_true' );
};

/**
 * Incomplete-installation guard (CBT-734).
 *
 * WordPress copies an update's files one by one; on a slow host the folder is
 * half-written for minutes, and an unguarded require of a not-yet-copied file
 * fatals EVERY request (HelpScout #69268: 361 fatals in 2.5 minutes). Same
 * pattern as WooCommerce's "installation is incomplete" check: verify the boot
 * files first; if any is missing, stay inactive for this request and tell
 * administrators, instead of failing. The next request after the copy
 * finishes boots normally. No global function is declared, so no collision.
 *
 * @since 8.0.34
 */
$ctxfeed_missing_files = array_filter(
	array( 'constants.php', 'installer.php', 'V8/autoload.php', 'V8/Bootstrap.php' ),
	static function ( $file ) {
		return ! is_readable( __DIR__ . '/' . $file );
	}
);
if ( ! empty( $ctxfeed_missing_files ) ) {
	$ctxfeed_pause();
	unset( $ctxfeed_missing_files, $ctxfeed_pause, $ctxfeed_hold_runs );
	return;
}
unset( $ctxfeed_missing_files );

/*
 * Everything below loads plugin files, so it runs inside one try: a file that
 * vanishes or is still half-written while WordPress updates the plugin
 * (a ParseError, a missing class) pauses CTX Feed for this request instead of
 * fataling the site (CBT-806, #69591). include_once (never require) so a file
 * that disappears after the check above is a warning + a catchable error, not
 * an uncatchable E_COMPILE_ERROR.
 */
try {
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

	if ( false === include_once __DIR__ . '/constants.php' ) {
		throw new \RuntimeException( 'constants.php could not be loaded.' );
	}

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
	if ( CTXFEED_V8_ACTIVE ) {
		if ( false === include_once __DIR__ . '/V8/autoload.php' ) {
			throw new \RuntimeException( 'V8/autoload.php could not be loaded.' );
		}

		// Load the Bootstrap class NOW, while the files are known to exist: every
		// hook below runs through Bootstrap::guarded(), so the guard must never
		// itself be the class that fails to load later in the request — the
		// #69591 fatal was `new Bootstrap()` on plugins_loaded after WordPress
		// had moved the folder away (CBT-806). It declares no parent/interface,
		// so this loads exactly one file.
		if ( ! class_exists( '\\CTXFeed\\V8\\Bootstrap', false ) && false === include_once __DIR__ . '/V8/Bootstrap.php' ) {
			throw new \RuntimeException( 'V8/Bootstrap.php could not be loaded.' );
		}
		// An older V8/Bootstrap.php next to this newer main file (the update
		// has copied one and not yet the other): the hooks below would call
		// methods it lacks, outside any try.
		if ( ! method_exists( '\\CTXFeed\\V8\\Bootstrap', 'guarded' ) || ! method_exists( '\\CTXFeed\\V8\\Bootstrap', 'contain' ) || ! method_exists( '\\CTXFeed\\V8\\Bootstrap', 'booted' ) || ! method_exists( '\\CTXFeed\\V8\\Bootstrap', 'mark_incomplete' ) ) {
			throw new \RuntimeException( 'V8/Bootstrap.php is from another version.' );
		}

		// V8 plugin lifecycle (activation / deactivation / upgrade). Self-contained
		// replacement for the V5 installer; survives removal of includes/ + V5.
		// __DIR__, NOT WOO_FEED_FREE_PATH: an already-loaded OLD Pro (which bundled
		// Free) defines WOO_FEED_FREE_PATH -> its own libs/ via `if ( ! defined )`,
		// so during the two-step upgrade window that constant can point at a folder
		// with no installer.php and a WOO_FEED_FREE_PATH require would fatal the
		// whole site. The plugin's OWN files must always load relative to this file.
		if ( false === include_once __DIR__ . '/installer.php' ) {
			throw new \RuntimeException( 'installer.php could not be loaded.' );
		}

		// V8 telemetry (WebAppick AppServices SDK) — usage insights + promotions.
		// Admin/cron only; the SDK gates opt-in internally. The upsell admin UI
		// (review-nag, compat notices, Premium/Our-Plugins pages) is deferred to
		// the React admin.
		add_action(
			'init',
			static function () {
				\CTXFeed\V8\Bootstrap::guarded(
					static function () {
						$needs_appservices = is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() );
						// An old Pro (< 8) owns the CTXFeed\AppServices namespace with
						// its own SDK copy (CBT-844): free telemetry waits until Pro
						// is updated rather than run on another plugin's classes.
						$legacy_pro = class_exists( '\\CTXFeed\\V8\\Status\\LegacyPro' ) && \CTXFeed\V8\Status\LegacyPro::is_active();
						if ( $needs_appservices && ! $legacy_pro && class_exists( '\\CTXFeed\\V8\\AppServices\\AppServices' ) ) {
							\CTXFeed\V8\AppServices\AppServices::instance();
						}
					}
				);
			},
			5
		);

		// A boot that fails here leaves CTX Feed paused for this request: hold
		// its scheduled runs instead of letting them fail (CBT-807).
		add_action(
			'plugins_loaded',
			static function () use ( $ctxfeed_hold_runs ) {
				\CTXFeed\V8\Bootstrap::guarded(
					static function () use ( $ctxfeed_hold_runs ) {
						( new \CTXFeed\V8\Bootstrap() )->init();
						$ctxfeed_hold_runs( ! \CTXFeed\V8\Bootstrap::booted() );
					}
				);
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
		// The layer shapes feed values, so when any part of it fails (a step
		// throws, or its autoloader is missing) the stack is marked incomplete
		// and feed runs wait instead of going out without it (CBT-807).
		if ( is_readable( __DIR__ . '/compatibility/autoload.php' ) && false !== include_once __DIR__ . '/compatibility/autoload.php' ) {
			add_action(
				'plugins_loaded',
				static function () {
					\CTXFeed\V8\Bootstrap::guarded(
						static function () {
							// is_callable() inside guarded() autoloads the class; a
							// missing file is a recorded failure, never a TypeError
							// (CBT-806).
							if ( ! \CTXFeed\V8\Bootstrap::guarded( array( '\\CTXFeed\\Compat\\Bootstrap', 'init' ) ) ) {
								\CTXFeed\V8\Bootstrap::mark_incomplete( 'compat' );
							}
						}
					);
				},
				20
			);

			// Initialise the third-party plugin compatibility shims: activates
			// CTXFeed\Compat\CompatibilityFactory, which instantiates each shim whose
			// plugin is active. The shims register their WP filters at constructor
			// time; those filters fire later when V8's FeedGenerator triggers the
			// V5-shaped hooks during generation.
			//
			// Priority 25 = after V8 Bootstrap (20) and the provider Bootstrap (20).
			add_action(
				'plugins_loaded',
				static function () {
					\CTXFeed\V8\Bootstrap::guarded(
						static function () {
							if ( ! \CTXFeed\V8\Bootstrap::guarded( array( '\\CTXFeed\\Compat\\CompatibilityFactory', 'init' ) ) ) {
								\CTXFeed\V8\Bootstrap::mark_incomplete( 'compat' );
							}
						}
					);
				},
				25
			);
		} else {
			\CTXFeed\V8\Bootstrap::mark_incomplete( 'compat' );
		}
	}
} catch ( \Throwable $ctxfeed_error ) {
	// A file vanished or is half-written mid-update: pause, never fatal.
	// When the V8 boot was already hooked, what failed is the compatibility
	// layer below it: the engine still boots on plugins_loaded, so mark the
	// stack incomplete and feed runs wait (CBT-807).
	if ( class_exists( '\\CTXFeed\\V8\\Bootstrap', false ) && method_exists( '\\CTXFeed\\V8\\Bootstrap', 'mark_incomplete' ) ) {
		\CTXFeed\V8\Bootstrap::mark_incomplete( 'compat' );
	}
	$ctxfeed_pause();
	unset( $ctxfeed_error, $ctxfeed_pause, $ctxfeed_hold_runs );
	return;
}
unset( $ctxfeed_pause, $ctxfeed_hold_runs );

// End of file woo-feed.php.
