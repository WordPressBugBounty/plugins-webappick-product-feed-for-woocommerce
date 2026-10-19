<?php
/**
 * Date — feed and product-panel dates that never depend on PHP's default
 * timezone (CBT-819, CBT-809 plan §1.2 / §2 H).
 *
 * `strtotime()` reads a date with no zone in `date_default_timezone_get()`.
 * WordPress sets that to UTC, but a plugin, a theme or the host can change it
 * and leave it changed for the rest of the request — and the admin product
 * save runs outside the feed runtime guard.
 * A date the merchant picked is a date in the STORE's timezone (CBT-719), so
 * every parse here names its zone: the store timezone (`wp_timezone()`) by
 * default, or the zone the caller passes (UTC for `*_gmt` columns).
 *
 * Cost: inside a feed batch (FeedRuntime::run) the store timezone is read
 * once per batch and blog, and each distinct date text is formatted once
 * per batch (at most MEMO_MAX entries) — a catalogue where every product
 * carries the same restock date pays one parse per batch. Outside a batch
 * (admin save, REST reads) nothing is memoised.
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
 * Zone-explicit date parsing and formatting.
 *
 * @since 8.0.39
 */
final class Date {

	/**
	 * Distinct values memoised per batch, at most.
	 */
	private const MEMO_MAX = 256;

	/**
	 * The UTC zone, built once per request.
	 *
	 * @var \DateTimeZone|null
	 */
	private static $utc = null;

	/**
	 * FeedRuntime run id the memo below belongs to (0 = none).
	 *
	 * @var int
	 */
	private static $memo_run = 0;

	/**
	 * Blog id the memo below belongs to.
	 *
	 * @var int
	 */
	private static $memo_blog = 0;

	/**
	 * Store timezone read in the memo scope.
	 *
	 * @var \DateTimeZone|null
	 */
	private static $memo_zone = null;

	/**
	 * Store-timezone results of iso8601() in the memo scope, keyed by value.
	 *
	 * @var array<string, string>
	 */
	private static $memo_iso = array();

	/**
	 * ISO 8601 (`c`) for a feed date.
	 *
	 * A value that already starts `YYYY-MM-DDT` passes through unchanged (what
	 * the channel transforms always did). Anything else is parsed in `$zone`
	 * — the store timezone when null — and written as `Y-m-d\TH:i:sP`, so
	 * `2026-12-25` in a New York store is `2026-12-25T00:00:00-05:00`. A value
	 * that carries its own offset keeps it (same instant). An unparseable
	 * value comes back unchanged; `''` stays `''`. Never `strtotime()`.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed              $value Date text.
	 * @param \DateTimeZone|null $zone  Zone of a date without one; null = store timezone.
	 * @return string
	 */
	public static function iso8601( $value, ?\DateTimeZone $zone = null ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;
		if ( '' === $value || 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T/', $value ) ) {
			return $value;
		}

		// An explicit zone (UTC for GMT columns): distinct values, no memo.
		if ( null !== $zone ) {
			return self::format_iso( $value, $zone );
		}

		if ( ! self::in_batch() ) {
			return self::format_iso( $value, self::read_store_zone() );
		}

		if ( isset( self::$memo_iso[ $value ] ) ) {
			return self::$memo_iso[ $value ];
		}

		if ( null === self::$memo_zone ) {
			self::$memo_zone = self::read_store_zone();
		}

		$iso = self::format_iso( $value, self::$memo_zone );
		if ( count( self::$memo_iso ) < self::MEMO_MAX ) {
			self::$memo_iso[ $value ] = $iso;
		}

		return $iso;
	}

	/**
	 * `Y-m-d` for an HTML5 `<input type="date">`.
	 *
	 * A valid `YYYY-MM-DD` passes through unchanged — re-parsing it can only
	 * move it (under a UTC+9 default, `strtotime()` + `gmdate()` turned
	 * `2026-12-25` into `2026-12-24`, one day earlier on every product save).
	 * Other text (`December 25, 2026`, `12/25/2026`, an ISO timestamp) is
	 * parsed in `$zone` — the store timezone when null — and its calendar
	 * date returned as written: `2026-12-25T00:00:00+02:00` is `2026-12-25`.
	 * An unparseable value comes back unchanged; `''` stays `''`.
	 *
	 * @since 8.0.39
	 *
	 * @param mixed              $value Date text.
	 * @param \DateTimeZone|null $zone  Zone of a date without one; null = store timezone.
	 * @return string
	 */
	public static function ymd( $value, ?\DateTimeZone $zone = null ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) && checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return $value;
		}

		try {
			return ( new \DateTimeImmutable( $value, $zone ?? self::store_zone() ) )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return $value;
		}
	}

	/**
	 * The store timezone (Settings → General), UTC when it cannot be read.
	 *
	 * `wp_timezone()` is WordPress 5.3+ and throws on a corrupt
	 * `timezone_string` option; neither may break a feed or a product save.
	 *
	 * @since 8.0.39
	 *
	 * @return \DateTimeZone
	 */
	public static function store_zone(): \DateTimeZone {
		if ( ! self::in_batch() ) {
			return self::read_store_zone();
		}

		if ( null === self::$memo_zone ) {
			self::$memo_zone = self::read_store_zone();
		}

		return self::$memo_zone;
	}

	/**
	 * Read the store timezone now (no memo).
	 *
	 * @return \DateTimeZone
	 */
	private static function read_store_zone(): \DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			try {
				$zone = wp_timezone();
				if ( $zone instanceof \DateTimeZone ) {
					return $zone;
				}
			} catch ( \Throwable $e ) {
				// Fall through to UTC, the zone WordPress itself runs PHP in.
				unset( $e );
			}
		}

		return self::utc();
	}

	/**
	 * `c` for `$value` read in `$zone`; the value itself when unparseable.
	 *
	 * @param string        $value Date text (not ISO, not empty).
	 * @param \DateTimeZone $zone  Zone of a date without one.
	 * @return string
	 */
	private static function format_iso( string $value, \DateTimeZone $zone ): string {
		try {
			return ( new \DateTimeImmutable( $value, $zone ) )->format( 'c' );
		} catch ( \Exception $e ) {
			return $value;
		}
	}

	/**
	 * Whether a feed batch (FeedRuntime run) is in progress; entering a new
	 * run, or another blog, drops the previous memo.
	 *
	 * @return bool
	 */
	private static function in_batch(): bool {
		$run = FeedRuntime::current_run();
		if ( 0 === $run ) {
			return false;
		}

		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		if ( $run !== self::$memo_run || $blog !== self::$memo_blog ) {
			self::$memo_run  = $run;
			self::$memo_blog = $blog;
			self::$memo_zone = null;
			self::$memo_iso  = array();
		}

		return true;
	}

	/**
	 * The UTC zone (for `*_gmt` values).
	 *
	 * @since 8.0.39
	 *
	 * @return \DateTimeZone
	 */
	public static function utc(): \DateTimeZone {
		if ( null === self::$utc ) {
			self::$utc = new \DateTimeZone( 'UTC' );
		}

		return self::$utc;
	}
}
