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
	 * Whether the V8 engine booted completely in this request (CBT-806).
	 *
	 * @since 8.0.38
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Parts of the stack that shape feeds and failed to load in this request,
	 * as keys (CBT-807): 'compat' (free's compatibility layer), 'pro' (a Pro
	 * part free resolves at boot).
	 *
	 * @since 8.0.38
	 * @var array<string,bool>
	 */
	private static $incomplete = array();

	/**
	 * A "not found" error naming a CTX Feed Pro class.
	 *
	 * @since 8.0.38
	 */
	const PRO_CLASS_PATTERN = '/(?:Class|Interface|Trait|Enum) ["\']?\\\\?CTXFeed\\\\Pro\\\\/';

	/**
	 * How old the automatic updater's lock may be and still count as an
	 * update in progress (= Status\ProState::EVIDENCE_WINDOW; an own copy, as
	 * this is read after a failure, when another class may not load).
	 *
	 * @since 8.0.38
	 */
	private const UPDATER_LOCK_WINDOW = 600;

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

		$booted       = self::guarded(
			function () {
				$this->boot_v8();
			}
		);
		self::$booted = $booted;
		if ( $booted ) {
			self::notify_recent_boot_failure();
		}
	}

	/**
	 * Whether the V8 engine booted completely in this request. False while
	 * the plugin's files are being replaced by an update, or after any boot
	 * failure — the upgrade routine and CTX Feed Pro wait for a request that
	 * booted (CBT-806). did_action( 'ctxfeed_v8_booted' ) cannot tell: the
	 * action counter rises before its listeners run.
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	public static function booted(): bool {
		return self::$booted;
	}

	/**
	 * Record that a part of the stack which shapes feeds did not load in this
	 * request (CBT-807): a feed run then waits instead of writing rows
	 * without it. 'compat' = free's compatibility layer (a guarded step
	 * failed or its autoloader is missing); 'pro' = a Pro part free resolves
	 * at boot (the Custom Template 2 renderer).
	 *
	 * @since 8.0.38
	 *
	 * @param string $part Part name.
	 * @return void
	 */
	public static function mark_incomplete( string $part ): void {
		self::$incomplete[ $part ] = true;
	}

	/**
	 * The parts marked incomplete in this request (see mark_incomplete()),
	 * in the order they were marked; empty when the stack is complete. A
	 * 'pro' entry is Pro's reason to wait; any other entry is free's.
	 *
	 * @since 8.0.38
	 *
	 * @return string[]
	 */
	public static function incomplete(): array {
		return array_map( 'strval', array_keys( self::$incomplete ) );
	}

	/**
	 * Whether an error thrown during a feed run means a plugin's files are
	 * being replaced right now (CBT-807), so the run must wait instead of
	 * skipping the product or failing:
	 * - 'pro': a "Class … not found" naming CTXFeed\Pro, or a ParseError in a
	 *   file under WOO_FEED_PRO_PATH — while Pro's main file is unreadable,
	 *   the 8.x Pro's src/ folder is gone, maintenance mode is on or the
	 *   automatic updater took its lock within the last 10 minutes;
	 * - 'free': a "not found" naming one of free's classes (file_for()), or a
	 *   ParseError in free's folder — while files_in_flux();
	 * - '' for anything else (a permanently missing class stays a skipped
	 *   product, never a stalled feed).
	 * Previous errors in the chain count too. Never throws.
	 *
	 * @since 8.0.38
	 *
	 * @param \Throwable $e The error.
	 * @return string 'pro', 'free' or ''.
	 */
	public static function in_flux_reason( \Throwable $e ): string {
		try {
			$pro   = false;
			$free  = false;
			$error = $e;
			for ( $depth = 0; null !== $error && $depth < 5; $depth++ ) {
				if ( self::is_pro_error( $error ) ) {
					$pro = true;
				} elseif ( self::is_free_error( $error ) ) {
					$free = true;
				}
				$error = $error->getPrevious();
			}
			if ( $pro && self::pro_files_in_flux( true ) ) {
				return 'pro';
			}
			if ( $free && self::files_in_flux() ) {
				return 'free';
			}
		} catch ( \Throwable $ignored ) {
			return '';
		}

		return '';
	}

	/**
	 * Whether a plugin's files are missing right now, by stat calls only
	 * (CBT-807) — for a check after each batch, so a value an inner catch
	 * turned into a wrong default is never kept: 'pro' when Pro is loaded
	 * and its main file is unreadable or (the 8.x Pro only) its src/ folder
	 * is gone; 'free'
	 * when free's main file is unreadable or its V8/ folder is gone; ''
	 * otherwise. Maintenance mode and the updater lock are not read here
	 * (any plugin's update sets them); in_flux_reason() reads them after an
	 * error. Never throws.
	 *
	 * @since 8.0.38
	 *
	 * @return string 'pro', 'free' or ''.
	 */
	public static function files_in_flux_reason(): string {
		try {
			if ( self::pro_files_in_flux( false ) ) {
				return 'pro';
			}
			$root = dirname( __DIR__ );

			return ! is_readable( $root . '/woo-feed.php' ) || ! is_dir( $root . '/V8' ) ? 'free' : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Whether the error names a CTX Feed Pro class that was not found, or is
	 * a ParseError in one of Pro's files.
	 *
	 * @since 8.0.38
	 *
	 * @param \Throwable $error The error.
	 * @return bool
	 */
	private static function is_pro_error( \Throwable $error ): bool {
		if ( preg_match( self::PRO_CLASS_PATTERN, $error->getMessage() ) ) {
			return true;
		}

		return $error instanceof \ParseError && defined( 'WOO_FEED_PRO_PATH' ) && self::path_under( $error->getFile(), (string) WOO_FEED_PRO_PATH );
	}

	/**
	 * Whether the error names one of free's classes that was not found, or
	 * is a ParseError in one of free's files.
	 *
	 * @since 8.0.38
	 *
	 * @param \Throwable $error The error.
	 * @return bool
	 */
	private static function is_free_error( \Throwable $error ): bool {
		if ( '' !== self::file_for( $error->getMessage() ) ) {
			return true;
		}

		return $error instanceof \ParseError && self::path_under( $error->getFile(), dirname( __DIR__ ) );
	}

	/**
	 * Whether $file lies inside $folder (slashes normalised).
	 *
	 * @since 8.0.38
	 *
	 * @param string $file   File path.
	 * @param string $folder Folder path.
	 * @return bool
	 */
	private static function path_under( string $file, string $folder ): bool {
		$folder = rtrim( str_replace( '\\', '/', $folder ), '/' );

		return '' !== $folder && 0 === strpos( str_replace( '\\', '/', $file ), $folder . '/' );
	}

	/**
	 * Whether Pro's files are being replaced: Pro is loaded and its main
	 * file is unreadable or its src/ folder is gone; with $site_wide also
	 * maintenance mode or a fresh automatic-updater lock.
	 *
	 * The src/ folder is the 8.x Pro's, checked only while its Bootstrap
	 * class is loaded: a Legacy Pro (7.x and older) defines the same
	 * WOO_FEED_PRO_FILE / _PATH constants but never had src/ — checking it
	 * there held every batch, then failed the run (CBT-807 F1).
	 *
	 * @since 8.0.38
	 *
	 * @param bool $site_wide Also read maintenance mode and the updater lock.
	 * @return bool
	 */
	private static function pro_files_in_flux( bool $site_wide ): bool {
		if ( defined( 'WOO_FEED_PRO_FILE' ) ) {
			clearstatcache();
			if ( ! is_readable( (string) WOO_FEED_PRO_FILE ) ) {
				return true;
			}
			if ( defined( 'WOO_FEED_PRO_PATH' ) && class_exists( 'CTXFeed\\Pro\\Bootstrap', false )
				&& ! is_dir( rtrim( (string) WOO_FEED_PRO_PATH, '/\\' ) . '/src' ) ) {
				return true;
			}
		}
		if ( ! $site_wide ) {
			return false;
		}
		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			return true;
		}

		return self::updater_locked();
	}

	/**
	 * Whether WordPress's automatic updater holds its lock and took it
	 * within the window (UPDATER_LOCK_WINDOW, or a longer wait maximum from
	 * `ctxfeed_update_wait_max_seconds`). The option's value is the time the
	 * lock was taken; a killed update leaves it behind for hours, or for
	 * good once automatic updates are turned off — an old lock, or one with
	 * no time, is no evidence of an update (CBT-807 F2). Never throws.
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	private static function updater_locked(): bool {
		try {
			if ( ! function_exists( 'get_option' ) ) {
				return false;
			}
			$taken = get_option( 'auto_updater.lock' );
			if ( ! is_numeric( $taken ) || (int) $taken <= 0 ) {
				return false;
			}
			$window = self::UPDATER_LOCK_WINDOW;
			if ( function_exists( 'apply_filters' ) ) {
				$window = min( 1800, max( $window, (int) apply_filters( 'ctxfeed_update_wait_max_seconds', $window ) ) );
			}

			return time() - (int) $taken <= $window;
		} catch ( \Throwable $e ) {
			return false;
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
	 * The step is not type-hinted `callable`: array( 'Some\\Class', 'method' )
	 * whose class file has just vanished would throw a TypeError at the call,
	 * outside the try. Checked inside instead (CBT-806).
	 *
	 * @param callable|mixed $step Boot step.
	 * @return bool True when the step completed.
	 */
	public static function guarded( $step ): bool {
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
			// is_callable() autoloads an array( 'Class', 'method' ) step: a
			// half-written file throws here, a missing one returns false.
			if ( is_callable( $step ) ) {
				call_user_func( $step );
				return true;
			}
			$error = self::unloadable( $step, 'CTX Feed boot step could not be loaded.' );
		} catch ( \Throwable $e ) {
			$error = $e;
		}
		self::remove_hooks_added_since( $before );
		self::record_boot_failure( $error );

		return false;
	}

	/**
	 * Run a callback that WordPress calls AFTER boot (admin screens, cron,
	 * widgets, REST routes) so a failure there is contained too (CBT-806):
	 * the callback's class files can vanish while an update replaces the
	 * plugin folder. Unlike guarded() it takes no hook snapshot — it runs on
	 * ordinary requests and adds no hooks.
	 *
	 * Only a file that could not be loaded (a missing class, a half-written
	 * file) is recorded for the "could not load one of its files" notice.
	 * Any other error is a bug in that callback, not a file problem: it is
	 * written to the PHP error log with its file and line, and no notice
	 * tells the admin to reinstall.
	 *
	 * @since 8.0.38
	 *
	 * @param callable|mixed $callback Callback.
	 * @param mixed          $fallback Returned when the callback fails.
	 * @return mixed The callback's result, or $fallback.
	 */
	public static function contain( $callback, $fallback = null ) {
		$unloadable = false;
		try {
			if ( is_callable( $callback ) ) {
				return call_user_func( $callback );
			}
			$error      = self::unloadable( $callback, 'CTX Feed callback could not be loaded.' );
			$unloadable = true;
		} catch ( \Throwable $e ) {
			$error = $e;
		}

		if ( $unloadable || $error instanceof \ParseError || '' !== self::file_for( $error->getMessage() ) ) {
			self::record_boot_failure( $error );
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A contained error must still reach the host's PHP log, as the fatal it replaces would have.
			error_log( 'CTX Feed contained an error: ' . get_class( $error ) . ': ' . substr( $error->getMessage(), 0, 300 ) . ' in ' . $error->getFile() . ':' . $error->getLine() );
		}

		return $fallback;
	}

	/**
	 * The error for a step that is not callable. An array( 'Class', 'method' )
	 * step whose class file is missing gets PHP's own "not found" message,
	 * so the record names the file — and is dropped once an update has
	 * finished copying it (notify_recent_boot_failure()).
	 *
	 * @since 8.0.38
	 *
	 * @param mixed  $step     The step.
	 * @param string $fallback Message for any other step.
	 * @return \RuntimeException
	 */
	private static function unloadable( $step, string $fallback ): \RuntimeException {
		if ( is_array( $step ) && isset( $step[0] ) && is_string( $step[0] ) && ! class_exists( $step[0], false ) ) {
			return new \RuntimeException( 'Class "' . ltrim( $step[0], '\\' ) . '" not found' );
		}

		return new \RuntimeException( $fallback );
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
		$file    = self::file_for( $message );

		// While WordPress replaces the plugin folder (an update), every request
		// in that window fails the same way and the next one boots the new
		// version: log it, but store and show nothing — a "reinstall CTX Feed"
		// notice after every ordinary update would be false (CBT-806).
		if ( self::files_in_flux() ) {
			static $logged = false;
			if ( ! $logged ) {
				$logged = true;
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- One line per request while the plugin is being updated.
				error_log( 'CTX Feed paused for this request while its files are being updated: ' . substr( $message, 0, 300 ) );
			}
			return;
		}

		// A CTX Feed Pro class (Pro hooks into this boot), or a half-written
		// file in Pro's folder (a ParseError names no class, CBT-807): Pro's
		// folder is the one being replaced or broken, and Pro shows its own
		// notice — never tell the admin to reinstall CTX Feed for it.
		if ( self::is_pro_error( $error ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The fatal this replaces would have been logged the same way.
			error_log( 'CTX Feed paused for this request, a CTX Feed Pro file could not be loaded: ' . substr( $message, 0, 300 ) );
			return;
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
	 * The plugin file of a "Class … not found" error, relative to the plugin
	 * folder ('' for other errors): CTXFeed\\V8\\X → V8/X.php,
	 * CTXFeed\\Compat\\X → compatibility/X.php, CTXFeed\\AppServices\\X →
	 * V8/AppServices/X.php.
	 *
	 * @since 8.0.38
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	private static function file_for( string $message ): string {
		if ( ! preg_match( '/(?:Class|Interface|Trait|Enum) ["\']?\\\\?CTXFeed\\\\(V8|Compat|AppServices)\\\\([A-Za-z0-9_\\\\]+)["\']? not found/', $message, $match ) ) {
			return '';
		}
		// The relocated telemetry SDK keeps its CTXFeed\AppServices namespace.
		$folders = array(
			'V8'          => 'V8/',
			'Compat'      => 'compatibility/',
			'AppServices' => 'V8/AppServices/',
		);

		return $folders[ $match[1] ] . str_replace( '\\', '/', $match[2] ) . '.php';
	}

	/**
	 * Whether the plugin's files are being replaced right now (an update):
	 * the main file or the V8 folder is gone, maintenance mode is on, or the
	 * automatic updater took its lock within the window (updater_locked()).
	 * Read only after a failure — never a gate on ordinary requests.
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	private static function files_in_flux(): bool {
		$root = dirname( __DIR__ );
		clearstatcache();
		if ( ! is_readable( $root . '/woo-feed.php' ) || ! is_dir( $root . '/V8' ) ) {
			return true;
		}
		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			return true;
		}

		return self::updater_locked();
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
		// Recorded by an older version and its file is back: an update raced a
		// request and has finished since — nothing for the admin to do.
		$file     = isset( $stored['file'] ) ? (string) $stored['file'] : '';
		$readable = '' !== $file && is_readable( dirname( __DIR__ ) . '/' . $file );
		$older    = defined( 'CTXFEED_V8_VERSION' ) && isset( $stored['version'] ) && CTXFEED_V8_VERSION !== $stored['version'];
		// Or the file was missing and is there now: an update was still
		// copying files when the record was written (an upload over the
		// old version shows neither maintenance mode nor the updater lock).
		// A file that was on disk and still failed to load stays shown.
		$copied = $readable && isset( $stored['on_disk'] ) && false === $stored['on_disk'];
		if ( $copied || ( $older && ( '' === $file || $readable ) ) ) {
			delete_option( self::BOOT_FAILURE_OPTION );
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

		// woo-feed.php registered the PSR-4 autoloader; load it only when the
		// class is used on its own (tests). Never require: a file that vanished
		// mid-update must stay a catchable error (CBT-806).
		if ( ! class_exists( Container::class ) && is_readable( __DIR__ . '/autoload.php' ) ) {
			include_once __DIR__ . '/autoload.php';
		}

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
			define( 'CTXFEED_V8_VERSION', '8.0.39' );
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
