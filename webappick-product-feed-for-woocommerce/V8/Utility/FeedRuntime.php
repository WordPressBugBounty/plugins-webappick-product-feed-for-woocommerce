<?php
/**
 * FeedRuntime — run feed generation under the PHP defaults feed values
 * are computed for, whatever another plugin or the host changed (CBT-810).
 *
 * Several PHP settings are process-wide and change feed values silently:
 * LC_NUMERIC (float to text on PHP 7.4: 23.79 becomes "23,79"), LC_CTYPE
 * once PCRE locale tables are active (case folding, /i matching),
 * `precision` / `serialize_precision`, `default_charset` and the default
 * timezone. A plugin that sets one of them for its own page leaves it set
 * for the feed batch that runs later in the same request.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.39
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pin, run, restore.
 *
 * Each value is pinned only when it differs from the default and restored
 * in `finally` only when it still holds the pinned value, so a value a
 * third-party callback set during the batch is kept. LC_CTYPE is switched
 * only when PCRE already uses locale tables — the only case where switching
 * and switching back is lossless (a naive save / C / restore turns PCRE
 * locale tables ON for the rest of the request on an environment-inherited
 * locale). Under ZTS no locale is touched: setlocale() is process-wide
 * across threads there.
 *
 * Implements CBT-809 plan §1.1.
 *
 * @since 8.0.39
 */
final class FeedRuntime {

	/**
	 * Locale every pinned category is switched to.
	 */
	private const LOCALE = 'C';

	/**
	 * Ini values pinned when different: key => pinned value.
	 */
	private const INI = array(
		'precision'           => '14',
		'serialize_precision' => '-1',
	);

	/**
	 * Charset `default_charset` is pinned to.
	 */
	private const CHARSET = 'UTF-8';

	/**
	 * Default timezone pinned (WordPress itself sets UTC in wp-settings.php).
	 */
	private const TIMEZONE = 'UTC';

	/**
	 * Nesting depth — inner calls run their callback unchanged.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Number of outermost runs started in this request (identifies the run
	 * a per-run memo belongs to, CBT-819).
	 *
	 * @var int
	 */
	private static $run_id = 0;

	/**
	 * Host values the outermost active run replaced: key => host value.
	 *
	 * @var array<string, string>
	 */
	private static $pinned = array();

	/**
	 * Whether the "values were pinned" info line was written this request.
	 *
	 * @var bool
	 */
	private static $logged = false;

	/**
	 * Whether the "WCML ran under the host number locale" line was written.
	 *
	 * @var bool
	 */
	private static $logged_host_numeric = false;

	/**
	 * Test seam: null = read PHP_ZTS, bool = simulated thread-safe build.
	 *
	 * @var bool|null
	 */
	private static $zts = null;

	/**
	 * Run `$callback` with the feed defaults pinned, restoring the host state after.
	 *
	 * Re-entrant: a nested call runs `$callback` directly. Returns whatever `$callback`
	 * returns and lets its exceptions through.
	 *
	 * @since 8.0.39
	 *
	 * @hook ctxfeed_runtime_guard Return false to run feed generation under
	 *                             the request's PHP state unchanged.
	 *
	 * @param callable $callback Work to run.
	 * @return mixed The callback's return value.
	 */
	public static function run( callable $callback ) {
		if ( self::$depth > 0 || ! self::enabled() ) {
			return $callback();
		}

		++self::$depth;
		++self::$run_id;
		$pins = array();
		try {
			$pins         = self::pin();
			self::$pinned = $pins;
			if ( ! empty( $pins ) ) {
				self::log_pins( $pins );
			}

			return $callback();
		} finally {
			--self::$depth;
			self::$pinned = array();
			self::restore( $pins );
		}
	}

	/**
	 * Run `$callback` under the number locale the host had before run() pinned
	 * LC_NUMERIC, then pin it again.
	 *
	 * Only does anything on PHP < 8 (the only versions whose float-to-text
	 * follows LC_NUMERIC) inside a run that really pinned LC_NUMERIC. Used
	 * for one third-party conversion call whose result must match the
	 * storefront the same host renders (WCML exchange rates, owner decision
	 * CBT-809 #9). Outside that case `$callback` runs directly.
	 *
	 * @since 8.0.39
	 *
	 * @param callable $callback Work to run.
	 * @return mixed The callback's return value.
	 */
	public static function with_host_number_locale( callable $callback ) {
		$host = self::$pinned['LC_NUMERIC'] ?? '';
		if ( '' === $host || PHP_VERSION_ID >= 80000 || self::is_zts() || ! function_exists( 'setlocale' ) ) {
			return $callback();
		}

		if ( self::LOCALE !== setlocale( LC_NUMERIC, '0' ) || false === setlocale( LC_NUMERIC, $host ) ) {
			return $callback();
		}

		if ( ! self::$logged_host_numeric ) {
			self::$logged_host_numeric = true;
			self::info( sprintf( '[RUNTIME] A currency conversion ran under the host number locale %s so the feed matches the storefront price (PHP < 8).', $host ) );
		}

		try {
			return $callback();
		} finally {
			if ( setlocale( LC_NUMERIC, '0' ) === $host ) {
				setlocale( LC_NUMERIC, self::LOCALE );
			}
		}
	}

	/**
	 * Id of the run in progress, 0 outside a run.
	 *
	 * Each outermost run() gets a new id, so a value memoised under one id
	 * lives for one feed batch (or finalize / preview) only — the store
	 * settings it was read from cannot change in the middle of one. Used by
	 * Utility\Date to read the store timezone once per batch (CBT-819).
	 * Always 0 when the guard is switched off (`ctxfeed_runtime_guard`).
	 *
	 * @since 8.0.39
	 *
	 * @return int
	 */
	public static function current_run(): int {
		return self::$depth > 0 ? self::$run_id : 0;
	}

	/**
	 * Whether PCRE currently builds its character tables from the locale.
	 *
	 * True only after a userland setlocale(LC_CTYPE|LC_ALL) to a locale whose
	 * single-byte case pairs differ from C: Turkish I/i, or Latin / Cyrillic
	 * high-byte pairs (0xC5 / 0xE5). The two patterns are compiled once per
	 * locale.
	 *
	 * @since 8.0.39
	 *
	 * @return bool
	 */
	public static function pcre_uses_locale_tables(): bool {
		return 0 === preg_match( '/i/i', 'I' ) || 1 === preg_match( "/\xC5/i", "\xE5" );
	}

	/**
	 * Test seam: simulate a thread-safe (ZTS) PHP build, or pass null to
	 * read PHP_ZTS again.
	 *
	 * @since 8.0.39
	 * @internal Tests only.
	 *
	 * @param bool|null $zts Simulated ZTS flag.
	 * @return void
	 */
	public static function simulate_zts( ?bool $zts ): void {
		self::$zts = $zts;
	}

	/**
	 * Whether the guard is on (filter `ctxfeed_runtime_guard`, default on).
	 *
	 * @return bool
	 */
	private static function enabled(): bool {
		if ( ! function_exists( 'apply_filters' ) ) {
			return true;
		}

		/**
		 * Whether feed generation pins the PHP number/case locale, precision,
		 * charset and timezone for its duration (CBT-810). Escape hatch only.
		 *
		 * @since 8.0.39
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'ctxfeed_runtime_guard', true );
	}

	/**
	 * Whether this PHP build is thread-safe.
	 *
	 * @return bool
	 */
	private static function is_zts(): bool {
		return null !== self::$zts ? self::$zts : ( defined( 'PHP_ZTS' ) && (bool) PHP_ZTS );
	}

	/**
	 * Whether a locale name is the C locale.
	 *
	 * @param mixed $name Locale name from setlocale().
	 * @return bool
	 */
	private static function is_c_locale( $name ): bool {
		return 'C' === $name || 'POSIX' === $name;
	}

	/**
	 * Pin every value that differs from the feed default.
	 *
	 * @return array<string, string> Pinned key => host value, in pin order.
	 */
	private static function pin(): array {
		$pins = array();

		if ( ! self::is_zts() && function_exists( 'setlocale' ) ) {
			// PHP never inherits LC_NUMERIC from the environment: a non-C value
			// means a userland setlocale() ran. Only LC_CTYPE / LC_ALL feed the
			// PCRE tables, so this switch never changes them.
			$numeric = setlocale( LC_NUMERIC, '0' );
			if ( is_string( $numeric ) && ! self::is_c_locale( $numeric ) && false !== setlocale( LC_NUMERIC, self::LOCALE ) ) {
				$pins['LC_NUMERIC'] = $numeric;
			}

			// Only when PCRE tables are already active: then the locale name
			// written back on restore produces the very same tables.
			$ctype = setlocale( LC_CTYPE, '0' );
			if ( is_string( $ctype ) && ! self::is_c_locale( $ctype ) && self::pcre_uses_locale_tables() && false !== setlocale( LC_CTYPE, self::LOCALE ) ) {
				$pins['LC_CTYPE'] = $ctype;
			}
		}

		if ( function_exists( 'ini_get' ) && function_exists( 'ini_set' ) ) {
			foreach ( self::INI as $key => $value ) {
				$current = ini_get( $key );
				// phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Per-request pin of a value another plugin changed; restored in finally (CBT-810).
				if ( is_string( $current ) && $value !== $current && false !== ini_set( $key, $value ) ) {
					$pins[ $key ] = $current;
				}
			}

			$charset = ini_get( 'default_charset' );
			// phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Per-request pin of a value another plugin changed; restored in finally (CBT-810).
			if ( is_string( $charset ) && '' !== $charset && ! self::is_utf8( $charset ) && false !== ini_set( 'default_charset', self::CHARSET ) ) {
				$pins['default_charset'] = $charset;
			}
		}

		if ( function_exists( 'date_default_timezone_get' ) && function_exists( 'date_default_timezone_set' ) ) {
			$timezone = date_default_timezone_get();
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Pins the UTC default WordPress itself sets, for the batch only; restored in finally (CBT-810).
			if ( self::TIMEZONE !== $timezone && date_default_timezone_set( self::TIMEZONE ) ) {
				$pins['timezone'] = $timezone;
			}
		}

		return $pins;
	}

	/**
	 * Restore every pinned value that still holds the pinned value.
	 *
	 * @param array<string, string> $pins Pinned key => host value.
	 * @return void
	 */
	private static function restore( array $pins ): void {
		foreach ( array_reverse( $pins, true ) as $key => $host ) {
			switch ( $key ) {
				case 'LC_NUMERIC':
				case 'LC_CTYPE':
					$category = 'LC_NUMERIC' === $key ? LC_NUMERIC : LC_CTYPE;
					if ( self::LOCALE === setlocale( $category, '0' ) ) {
						setlocale( $category, $host );
					}
					break;

				case 'timezone':
					if ( self::TIMEZONE === date_default_timezone_get() ) {
						// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Puts back the timezone the host had before the batch (CBT-810).
						date_default_timezone_set( $host );
					}
					break;

				default:
					$pinned = 'default_charset' === $key ? self::CHARSET : ( self::INI[ $key ] ?? null );
					if ( null !== $pinned && ini_get( $key ) === $pinned ) {
						// phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Puts back the value the host had before the batch (CBT-810).
						ini_set( $key, $host );
					}
					break;
			}
		}
	}

	/**
	 * Whether a charset name is UTF-8 (ASCII-only comparison).
	 *
	 * @param string $charset Charset name.
	 * @return bool
	 */
	private static function is_utf8( string $charset ): bool {
		return 0 === strcasecmp( $charset, 'UTF-8' ) || 0 === strcasecmp( $charset, 'UTF8' );
	}

	/**
	 * Write the once-per-request "values were pinned" line.
	 *
	 * @param array<string, string> $pins Pinned key => host value.
	 * @return void
	 */
	private static function log_pins( array $pins ): void {
		if ( self::$logged ) {
			return;
		}
		self::$logged = true;

		$parts = array();
		foreach ( $pins as $key => $host ) {
			$parts[] = sprintf( '%s %s', $key, '' === $host ? '(empty)' : $host );
		}

		self::info( sprintf( '[RUNTIME] Feed generation ran with PHP defaults instead of the values another plugin or the host set: %s. They are restored after each batch.', implode( ', ', $parts ) ) );
	}

	/**
	 * System-log info line; logging never breaks generation.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function info( string $message ): void {
		try {
			if ( class_exists( \CTXFeed\V8\Core\Logger::class ) ) {
				\CTXFeed\V8\Core\Logger::info( $message );
			}
		} catch ( \Throwable $e ) {
			// A log line must never stop a feed batch.
			unset( $e );
		}
	}
}
