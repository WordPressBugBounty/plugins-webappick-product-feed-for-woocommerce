<?php
/**
 * ProState — is CTX Feed Pro expected in this request but not ready (CBT-807)?
 *
 * A feed run may only write or publish in a request where every part that
 * shapes the feed has loaded. When CTX Feed Pro is listed as active and
 * licensed but is being updated, or its boot failed, a run must wait instead
 * of writing rows without Pro's rules (currency, language, mappings …).
 * FeedScheduler asks paused() once per action or REST call — never per
 * product — and holds the run while it is true.
 *
 * Rules for every probe:
 * - class_exists( …, false ): a Pro file that is half-written while
 *   WordPress replaces the folder must never be autoloaded from here.
 * - Capabilities, never version numbers.
 * - Nothing here may throw: every public method catches.
 *
 * @package    CTXFeed
 * @subpackage V8/Status
 * @since      8.0.38
 */

namespace CTXFeed\V8\Status;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads whether CTX Feed Pro is expected but not ready.
 *
 * @since 8.0.38
 */
final class ProState {

	/**
	 * Pro's main file name (= Admin\ProPlugin::MAIN_FILE). An own copy, so a
	 * cron request never loads an admin class.
	 *
	 * @since 8.0.38
	 * @var string
	 */
	const MAIN_FILE = 'webappick-product-feed-for-woocommerce-pro.php';

	/**
	 * Option (not autoloaded): when Pro was first seen listed but missing
	 * without any update evidence.
	 *
	 * @since 8.0.38
	 * @var string
	 */
	const MISSING_SINCE_OPTION = 'ctxfeed_pro_missing_since';

	/**
	 * How long "listed, folder gone, no evidence" still counts as an update
	 * (a "replace current with uploaded" deploy deletes the folder, then
	 * moves the new one in). After that Pro is treated as deleted.
	 *
	 * @since 8.0.38
	 * @var int
	 */
	const MISSING_GRACE = 300;

	/**
	 * Update evidence (a recent temp backup, a Pro folder without its main
	 * file) counts only when it is at most this old — the wait maximum
	 * (filter ctxfeed_update_wait_max_seconds; never less than this). Core
	 * keeps upgrade-temp-backup for up to a week after a killed update.
	 *
	 * @since 8.0.38
	 * @var int
	 */
	const EVIDENCE_WINDOW = 600;

	/**
	 * Age after which a "Pro was not loaded" sighting is forgotten once Pro
	 * loads again (a day): a younger one keeps counting, so a request that
	 * loads Pro never restarts another kind of request's countdown.
	 *
	 * @since 8.0.38
	 * @var int
	 */
	const MISSING_FORGET_AFTER = 86400;

	/**
	 * How long Pro stays licensed after its licence server first reported
	 * the licence as not active (= Pro's License::GRACE_PERIOD, CBT-682).
	 * An own copy: Pro's License class is not loaded on every path.
	 *
	 * @since 8.0.38
	 * @var int
	 */
	const LICENCE_GRACE = 604800;

	/**
	 * Whether Pro announced itself mid-update in this request: its files
	 * were missing at include time, its include-time try failed, or its
	 * engines refused to register (all add `ctxfeed_pro_installation_incomplete`).
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	public static function mid_update(): bool {
		try {
			return function_exists( 'apply_filters' ) && (bool) apply_filters( 'ctxfeed_pro_installation_incomplete', false );
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Whether the loaded Pro finished the part of its boot that shapes feeds.
	 *
	 * - Pro with `Bootstrap::booted()` answers itself.
	 * - Pro 8.0.5 … 8.0.27 has no such method; it fires `ctxfeed_pro_booted`
	 *   at the end of a successful, licensed boot, and a caught boot error
	 *   leaves the count at 0. That action comes after its endpoints and
	 *   abilities, so a throw there would hold feeds although the shims and
	 *   engines are wired: see old_pro_shaped_feeds().
	 * - No V8 Pro Bootstrap loaded (LegacyPro, or no Pro): no signal, true.
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	public static function booted(): bool {
		try {
			$bootstrap = 'CTXFeed\\Pro\\Bootstrap';
			if ( ! class_exists( $bootstrap, false ) ) {
				return true;
			}
			if ( is_callable( array( $bootstrap, 'booted' ) ) ) {
				return (bool) call_user_func( array( $bootstrap, 'booted' ) );
			}
			if ( function_exists( 'did_action' ) && did_action( 'ctxfeed_pro_booted' ) > 0 ) {
				return true;
			}

			return self::old_pro_shaped_feeds();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Pro 8.0.5 … 8.0.27 (no `booted()`) whose boot threw after the part that
	 * shapes feeds. Its boot runs init_compatibility(), then init_engines()
	 * (8.0.17+), then loads EndpointModule in init_endpoints() — nothing loads
	 * that class earlier (checked at every tag). So EndpointModule loaded =
	 * the shims and engines are wired, even if endpoints or abilities threw
	 * afterwards. Its `ctxfeed_pro_engines_registered` action fires at
	 * INCLUDE time, before the shims: alone it proves nothing, but where
	 * EngineRegistry is loaded it must have fired (all engines hooked).
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	private static function old_pro_shaped_feeds(): bool {
		if ( ! class_exists( 'CTXFeed\\Pro\\Endpoint\\EndpointModule', false ) ) {
			return false;
		}
		if ( class_exists( 'CTXFeed\\Pro\\Engine\\EngineRegistry', false ) ) {
			return function_exists( 'did_action' ) && did_action( 'ctxfeed_pro_engines_registered' ) > 0;
		}

		return true;
	}

	/**
	 * Whether Pro is licensed. Unlicensed Pro adds nothing to a feed, so it
	 * never holds one — on any path. Pro's License class answers when it is
	 * loaded (Pro's include time). When it is not — Pro paused itself before
	 * loading it, or Pro did not load in this request — Pro's stored licence
	 * record is read the way License::is_active() reads it (an option, not a
	 * class: nothing of Pro's is loaded). Any error: licensed (never publish
	 * on a guess).
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	public static function licensed(): bool {
		try {
			$license = 'CTXFeed\\Pro\\License\\License';
			if ( class_exists( $license, false ) && is_callable( array( $license, 'is_active' ) ) ) {
				return (bool) call_user_func( array( $license, 'is_active' ) );
			}

			return self::stored_licence_active();
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Pro's License::is_active() without Pro's class: the stored record has
	 * a key, instance, product id and status 'active', and its 7-day grace
	 * window (if one opened) has not run out; then Pro's own
	 * `ctxfeed_pro_license_active` filter (staging overrides). No record
	 * stored: unlicensed. A stored value that is not a record: licensed.
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	private static function stored_licence_active(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return true;
		}
		$record = get_option( self::licence_option(), null );
		if ( is_array( $record ) ) {
			$started = isset( $record['grace_started'] ) ? (int) $record['grace_started'] : 0;
			$active  = isset( $record['key'], $record['instance'], $record['product_id'], $record['status'] )
				&& '' !== $record['key']
				&& 'active' === $record['status']
				&& ! ( $started > 0 && time() >= $started + self::LICENCE_GRACE );
		} else {
			$active = null !== $record && false !== $record && '' !== $record;
			$record = array();
		}

		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'ctxfeed_pro_license_active', $active, $record ) : $active;
	}

	/**
	 * Pro's licence option (= License::option_key()):
	 * `WebAppick_{md5(slug)}_manage_license`, slug = Pro's plugin folder —
	 * from its constants when its main file ran, else from the basename
	 * WordPress lists — through Pro's `ctxfeed_pro_license_slug` filter.
	 *
	 * @since 8.0.38
	 *
	 * @return string
	 */
	private static function licence_option(): string {
		$slug     = 'webappick-product-feed-for-woocommerce-pro';
		$basename = '';
		if ( defined( 'WOO_FEED_PRO_BASENAME' ) ) {
			$basename = (string) WOO_FEED_PRO_BASENAME;
		} elseif ( defined( 'WOO_FEED_PRO_FILE' ) && function_exists( 'plugin_basename' ) ) {
			$basename = (string) plugin_basename( (string) WOO_FEED_PRO_FILE );
		} else {
			$basename = self::listed();
		}
		$parts = explode( '/', $basename );
		if ( ! empty( $parts[0] ) ) {
			$slug = $parts[0];
		}
		if ( function_exists( 'apply_filters' ) ) {
			$slug = (string) apply_filters( 'ctxfeed_pro_license_slug', $slug );
		}

		return 'WebAppick_' . md5( $slug ) . '_manage_license';
	}

	/**
	 * Pro's basename when WordPress lists it as active on this site or
	 * network-wide, '' otherwise. Matches the main file name, so a renamed
	 * folder is found too. Odd option values (not an array, non-string
	 * entries) are skipped, never a TypeError.
	 *
	 * @since 8.0.38
	 *
	 * @return string
	 */
	public static function listed(): string {
		try {
			$candidates = array();
			if ( function_exists( 'get_option' ) ) {
				$active = get_option( 'active_plugins', array() );
				if ( is_array( $active ) ) {
					$candidates = array_values( $active );
				}
			}
			if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
				$network = get_site_option( 'active_sitewide_plugins', array() );
				if ( is_array( $network ) ) {
					$candidates = array_merge( $candidates, array_keys( $network ) );
				}
			}
			foreach ( $candidates as $basename ) {
				if ( is_string( $basename ) && '' !== $basename && self::MAIN_FILE === basename( $basename ) ) {
					return $basename;
				}
			}
		} catch ( \Throwable $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Whether a listed Pro that did not load in this request is being
	 * replaced right now. Only meaningful while WOO_FEED_PRO_VERSION is
	 * undefined (false otherwise). Evidence, in order:
	 * - its main file is readable: WordPress recovery mode skipped it (true
	 *   until the admin exits recovery mode); otherwise it was copied after
	 *   WordPress's include loop, or a plugin-load filter / --skip-plugins
	 *   left it out of this request — true for MISSING_GRACE seconds from
	 *   the first sighting only (reason_message() says "not loaded");
	 * - its folder exists without the main file, and changed within the
	 *   evidence window;
	 * - its folder is gone and `upgrade-temp-backup/plugins/<folder>` exists:
	 *   that plugins folder changed, and Pro's backup was moved there (its
	 *   change time), within the window;
	 * - maintenance mode, or the automatic updater's lock taken within the
	 *   window (a killed update leaves the lock behind);
	 * - otherwise (no evidence): true for MISSING_GRACE seconds from the
	 *   first sighting, then false (treated as deleted).
	 *
	 * @since 8.0.38
	 *
	 * @param string $basename Pro's basename, from listed().
	 * @return bool
	 */
	public static function being_replaced( string $basename ): bool {
		try {
			if ( '' === $basename || defined( 'WOO_FEED_PRO_VERSION' ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
				return false;
			}
			clearstatcache();
			$plugins  = rtrim( (string) WP_PLUGIN_DIR, '/\\' );
			$folder   = dirname( $basename );
			$foldered = '.' !== $folder && '' !== $folder;

			if ( is_readable( $plugins . '/' . $basename ) ) {
				return self::recovery_mode() || self::missing_within_grace();
			}
			if ( $foldered && is_dir( $plugins . '/' . $folder ) ) {
				if ( self::recent( $plugins . '/' . $folder ) ) {
					return true;
				}
			} elseif ( $foldered && defined( 'WP_CONTENT_DIR' ) ) {
				$backup = rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/upgrade-temp-backup/plugins';
				if ( is_dir( $backup . '/' . $folder ) && self::recent( $backup ) && self::moved_recently( $backup . '/' . $folder ) ) {
					return true;
				}
			}
			if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
				return true;
			}
			if ( self::updater_locked() ) {
				return true;
			}

			return self::missing_within_grace();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether WordPress recovery mode is why a listed Pro did not load: the
	 * request runs in recovery mode and Pro is not loaded.
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	public static function recovery_mode(): bool {
		try {
			return ! defined( 'WOO_FEED_PRO_VERSION' ) && function_exists( 'wp_is_recovery_mode' ) && (bool) wp_is_recovery_mode();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Whether Pro is expected in this request but not ready, so a feed run
	 * must wait. Only a licensed Pro is expected (unlicensed Pro adds nothing
	 * to a feed): Pro announced itself mid-update; or it is listed, did not
	 * load and is being replaced; or it loaded and its boot did not finish.
	 * During plugins_loaded Pro's boot has not run yet: false. Any error:
	 * true (never publish on a guess).
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	public static function paused(): bool {
		try {
			if ( self::mid_update() ) {
				return self::licensed();
			}
			if ( ! defined( 'WOO_FEED_PRO_VERSION' ) ) {
				$basename = self::listed();

				return '' !== $basename && self::licensed() && self::being_replaced( $basename );
			}
			self::forget_missing();
			if ( ! did_action( 'plugins_loaded' ) || doing_action( 'plugins_loaded' ) ) {
				return false;
			}

			return ! self::booted() && self::licensed();
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/**
	 * Whether a listed Pro is simply not loaded on this request although its
	 * main file is in place and it did not pause itself: a plugin-load
	 * filter or WP-CLI --skip-plugins left it out (or an update finished
	 * copying it after WordPress's include loop). Not recovery mode — that
	 * has its own message.
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	public static function not_loaded(): bool {
		try {
			if ( defined( 'WOO_FEED_PRO_VERSION' ) || ! defined( 'WP_PLUGIN_DIR' ) || self::mid_update() || self::recovery_mode() ) {
				return false;
			}
			$basename = self::listed();

			return '' !== $basename && is_readable( rtrim( (string) WP_PLUGIN_DIR, '/\\' ) . '/' . $basename );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * The message for a run that waits: 'pro' (with a recovery-mode and a
	 * "not loaded on this request" variant) or 'free'. '' for any other
	 * reason.
	 *
	 * @since 8.0.38
	 *
	 * @param string $reason 'pro' or 'free'.
	 * @return string
	 */
	public static function reason_message( string $reason ): string {
		if ( 'pro' === $reason ) {
			if ( self::recovery_mode() ) {
				return __( 'CTX Feed Pro is paused by WordPress recovery mode. Feeds are not generated without it, so they never go out without your Pro settings. Fix the error WordPress reported for CTX Feed Pro, then exit recovery mode.', 'woo-feed' );
			}
			if ( self::not_loaded() ) {
				return __( 'CTX Feed Pro is not loaded on this request, although it is active and its files are in place. Feeds wait for it so they do not go out without your Pro settings. If a plugin turns other plugins off on some pages, or WP-CLI runs with --skip-plugins, let CTX Feed Pro load there.', 'woo-feed' );
			}

			return __( 'CTX Feed Pro is being updated or could not start. Feeds are not generated without it, so they never go out without your Pro settings. Try again in a minute.', 'woo-feed' );
		}
		if ( 'free' === $reason ) {
			return __( 'CTX Feed is being updated or part of it could not start. Feeds are not generated until it has loaded completely. Try again in a minute.', 'woo-feed' );
		}

		return '';
	}

	/**
	 * Whether a path changed within the evidence window. A future time
	 * (clock skew) counts as recent.
	 *
	 * @since 8.0.38
	 *
	 * @param string $path File or folder.
	 * @return bool
	 */
	private static function recent( string $path ): bool {
		$mtime = filemtime( $path );
		if ( false === $mtime ) {
			return false;
		}

		return time() - (int) $mtime <= self::evidence_window();
	}

	/**
	 * Whether a folder was moved (renamed) or created within the evidence
	 * window: its change time. Moving a folder keeps its modification time
	 * but sets its change time — Pro's own backup, not the shared parent
	 * that any other plugin's update refreshes.
	 *
	 * @since 8.0.38
	 *
	 * @param string $path Folder.
	 * @return bool
	 */
	private static function moved_recently( string $path ): bool {
		$ctime = filectime( $path );
		if ( false === $ctime ) {
			return false;
		}

		return time() - (int) $ctime <= self::evidence_window();
	}

	/**
	 * Whether WordPress's automatic updater holds its lock and took it within
	 * the evidence window. The option's value is the time the lock was
	 * taken; a killed update leaves it behind for hours, or for good once
	 * automatic updates are turned off. No time stored: no evidence.
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	private static function updater_locked(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$taken = get_option( 'auto_updater.lock' );
		if ( ! is_numeric( $taken ) || (int) $taken <= 0 ) {
			return false;
		}

		return time() - (int) $taken <= self::evidence_window();
	}

	/**
	 * The evidence window: the wait maximum, never shorter than EVIDENCE_WINDOW.
	 *
	 * @since 8.0.38
	 *
	 * @return int Seconds.
	 */
	private static function evidence_window(): int {
		$max = function_exists( 'apply_filters' ) ? (int) apply_filters( 'ctxfeed_update_wait_max_seconds', self::EVIDENCE_WINDOW ) : self::EVIDENCE_WINDOW;

		// Never past the scheduler's 30-minute ceiling: a day-old updater lock
		// or backup folder is not an update in progress.
		return min( 1800, max( self::EVIDENCE_WINDOW, $max ) );
	}

	/**
	 * "Listed, not loaded, no evidence": true for MISSING_GRACE seconds from
	 * the first sighting (stored, not autoloaded), false afterwards.
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	private static function missing_within_grace(): bool {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return false;
		}
		$now   = time();
		$since = get_option( self::MISSING_SINCE_OPTION, 0 );
		$since = is_numeric( $since ) ? (int) $since : 0;
		if ( $since <= 0 || $since > $now ) {
			update_option( self::MISSING_SINCE_OPTION, $now, false );

			return true;
		}

		return $now - $since < self::MISSING_GRACE;
	}

	/**
	 * Pro is loaded: forget an earlier "missing" sighting older than a day,
	 * so a later episode gets its own grace.
	 *
	 * @since 8.0.38
	 *
	 * @return void
	 */
	private static function forget_missing(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
			return;
		}
		$since = get_option( self::MISSING_SINCE_OPTION, false );
		if ( false === $since ) {
			return;
		}
		// A recent sighting stays: Pro may be left out of cron requests only
		// (a plugin organizer) while this request loaded it — forgetting it
		// here would restart the cron requests' countdown on every admin
		// page load.
		if ( is_numeric( $since ) && time() - (int) $since <= self::MISSING_FORGET_AFTER && (int) $since <= time() ) {
			return;
		}
		delete_option( self::MISSING_SINCE_OPTION );
	}
}
