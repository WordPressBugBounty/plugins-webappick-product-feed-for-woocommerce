<?php
/**
 * GuestContext — resolve feed data as a logged-out visitor.
 *
 * A feed must carry the price a shopper sees on the product page while
 * logged out — that is what Google checks the landing page against.
 * Scheduled runs (WP-Cron / Action Scheduler) already have no user, but a
 * small feed generates inside the admin's own REST request and the browser
 * runner drives batches from the admin's session, so prices were calculated
 * for the admin: member discounts (WooCommerce Memberships), role prices and
 * B2B rules leaked into manual runs, and the price flipped back on the next
 * scheduled run (CBT-739, HelpScout #69187).
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.36
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run a callback with no current user, restoring the caller afterwards.
 *
 * @since 8.0.36
 */
class GuestContext {

	/**
	 * Locale steps already logged this request ('switch', 'restore').
	 *
	 * @since 8.0.39
	 * @var array<string,bool>
	 */
	private static $logged = array();

	/**
	 * Run `$callback` as a logged-out visitor.
	 *
	 * Switches to user 0 only when someone is logged in, and ALWAYS restores
	 * the caller (even when the callback throws), so the rest of the REST
	 * request — permission checks, the response, notices — still runs as the
	 * admin. Nested calls are no-ops (the inner call already sees user 0).
	 *
	 * A visitor also reads the site in the site's language: when the
	 * caller's own language (their profile setting, which WordPress loads on
	 * admin and admin-ajax requests) differs, translations switch to the
	 * site locale for the callback and back afterwards — before the user
	 * switch, because under user 0 WordPress already reports the site locale
	 * and would treat the switch as a no-op. Strings WooCommerce translates
	 * at runtime, such as the default title of a shipping method, then
	 * match a WP-Cron run (CBT-820).
	 *
	 * @since 8.0.36
	 * @since 8.0.39 Switches to the site locale too.
	 *
	 * @hook ctxfeed_generate_as_guest Return false to keep the current user
	 *                                 (e.g. a feed that deliberately carries
	 *                                 the logged-in user's prices).
	 *
	 * @param callable $callback Work to run.
	 * @return mixed The callback's return value.
	 */
	public static function run( callable $callback ) {
		$previous = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		$switch = $previous > 0
			&& function_exists( 'wp_set_current_user' )
			&& (bool) apply_filters( 'ctxfeed_generate_as_guest', true, $previous );

		if ( ! $switch ) {
			return $callback();
		}

		// Never throws: a failed switch runs the callback in the caller's
		// language, as before 8.0.39.
		$locale_switched = self::switch_to_site_locale();

		try {
			wp_set_current_user( 0 );

			return $callback();
		} finally {
			try {
				wp_set_current_user( $previous );
			} finally {
				// Never throws, so the callback's own result (or exception)
				// is what the caller gets.
				if ( $locale_switched ) {
					self::restore_locale();
				}
			}
		}
	}

	/**
	 * Switch translations to the site locale when the caller's differs.
	 *
	 * Contained (CBT-820 review): switch_to_locale() reloads every loaded
	 * text domain and fires `change_locale` and `switch_locale`, so third-party
	 * code runs here. When any of it throws, a switch WordPress had already
	 * pushed is undone, the failure goes to the system log, and the run
	 * keeps the caller's language — it never fails the batch.
	 *
	 * @since 8.0.39
	 *
	 * @hook ctxfeed_generate_in_site_locale Return false to keep the caller's
	 *                                       language for the run.
	 *
	 * @return bool True when a switch is in effect (restore_locale() owed).
	 */
	private static function switch_to_site_locale(): bool {
		if ( ! function_exists( 'switch_to_locale' ) || ! function_exists( 'determine_locale' ) || ! function_exists( 'get_locale' ) ) {
			return false;
		}

		$site = '';

		try {
			$site   = (string) get_locale();
			$caller = (string) determine_locale();

			if ( '' === $site || $site === $caller ) {
				return false;
			}

			/**
			 * Whether a feed run switches translations to the site locale when the
			 * logged-in caller uses another language (CBT-820).
			 *
			 * @since 8.0.39
			 *
			 * @param bool   $switch Default true.
			 * @param string $site   Site locale.
			 * @param string $caller The caller's locale.
			 */
			if ( ! (bool) apply_filters( 'ctxfeed_generate_in_site_locale', true, $site, $caller ) ) {
				return false;
			}

			$before = self::switched_locale();
		} catch ( \Throwable $e ) {
			self::log_locale_failure( 'switch', $site, $e );

			return false;
		}

		try {
			return (bool) switch_to_locale( $site );
		} catch ( \Throwable $e ) {
			// WordPress records the switch before it reloads translations
			// and fires its actions: undo a switch that is half done.
			$after = self::switched_locale();
			if ( '' !== $after && $after !== $before && ( $site === $after || '?' === $after ) ) {
				self::restore_locale();
			}

			self::log_locale_failure( 'switch', $site, $e );

			return false;
		}
	}

	/**
	 * Undo our locale switch; a throwing listener is logged, never raised.
	 *
	 * WordPress's restore_previous_locale() drops the switch before it reloads
	 * translations and fires `change_locale` / `restore_previous_locale`,
	 * so a listener that throws there still leaves the switch undone.
	 *
	 * @since 8.0.39
	 *
	 * @return void
	 */
	private static function restore_locale(): void {
		if ( ! function_exists( 'restore_previous_locale' ) ) {
			return;
		}

		try {
			restore_previous_locale();
		} catch ( \Throwable $e ) {
			self::log_locale_failure( 'restore', '', $e );
		}
	}

	/**
	 * The locale switched to right now, or '' when none.
	 *
	 * @since 8.0.39
	 *
	 * @return string
	 */
	private static function switched_locale(): string {
		if ( function_exists( 'get_switched_locale' ) ) {
			return (string) get_switched_locale();
		}

		// WordPress before 6.2: only whether a switch is in effect.
		return function_exists( 'is_locale_switched' ) && is_locale_switched() ? '?' : '';
	}

	/**
	 * One system-log line per request for a locale switch that threw.
	 *
	 * Diagnostics, so Logger::warning() (ctxfeed-system.log) — never the
	 * WooCommerce `ctxfeed` source. Logger is looked up with is_callable():
	 * this runs around every batch, also while an update replaces files.
	 *
	 * @since 8.0.39
	 *
	 * @param string     $step   'switch' or 'restore'.
	 * @param string     $locale Site locale ('' when not read).
	 * @param \Throwable $error  What the listener threw.
	 * @return void
	 */
	private static function log_locale_failure( string $step, string $locale, \Throwable $error ): void {
		if ( isset( self::$logged[ $step ] ) ) {
			return;
		}
		self::$logged[ $step ] = true;

		try {
			if ( class_exists( '\CTXFeed\V8\Core\Logger' ) && is_callable( array( '\CTXFeed\V8\Core\Logger', 'warning' ) ) ) {
				\CTXFeed\V8\Core\Logger::warning(
					'switch' === $step
						? 'Feed run kept the logged-in user\'s language: switching to the site language failed in a third-party listener.'
						: 'Restoring the logged-in user\'s language after a feed run failed in a third-party listener.',
					array(
						'locale' => $locale,
						'error'  => get_class( $error ) . ': ' . $error->getMessage(),
					)
				);
			}
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}
}
