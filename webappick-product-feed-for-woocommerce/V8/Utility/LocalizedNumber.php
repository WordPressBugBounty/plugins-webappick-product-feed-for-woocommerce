<?php
/**
 * LocalizedNumber — read a price as a float, raw or already formatted.
 *
 * Two readers, one per kind of input (CBT-811):
 *
 * - {@see parse_raw()} — a RAW value (the WooCommerce price, a converted or
 *   computed number): a machine-format numeric wins ("17.991" is 17.991),
 *   exactly what V5 `FormatOutput` did with its bare `(float)` cast. Only a
 *   value that is NOT a machine number ("1.499,50", "1 499") is unwound with
 *   the feed's separators. This is what the Price (6) and Rounded Price (7)
 *   output types read, because their input is raw.
 * - {@see parse()} — a value the pipeline ITSELF formatted with the same
 *   feed configuration (a custom2 dynamic-attribute result, or code 6's own
 *   output): the configured-locale reading wins, so the exact inverse of
 *   number_format() is applied and every stage that parses before
 *   formatting stays idempotent. With decimals=0, decimal "," and thousand
 *   ".", 1499 → "1.499" must read back as 1499, not 1.499 — the bare
 *   `(float)` re-read shipped a 1 499 SEK product to Google as 1 SEK
 *   (support #68878, Käpprätt AB).
 *
 * Ambiguity note: with thousand separator ".", the string "17.991" is either
 * the machine number 17.991 or 17 991 formatted. Which reading is right
 * depends on where the value came from, never on its shape. Up to 8.0.38
 * codes 6/7 always used parse(), so a raw 17.991 under the Pro boxes ","/"."
 * shipped as "17.991,00" (1000× the price, CBT-809 C1). Raw input therefore
 * goes through parse_raw(); parse() is only for text this pipeline wrote.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.10
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locale-aware numeric parser.
 *
 * @since 8.0.10
 */
class LocalizedNumber {

	/**
	 * Parse a value this pipeline formatted with the feed's separators (format first).
	 *
	 * Use only for text the pipeline itself wrote with the same feed
	 * configuration; a raw value goes through {@see parse_raw()}.
	 *
	 * @since 8.0.10
	 *
	 * @param mixed  $value        Raw or formatted numeric value.
	 * @param string $decimal_sep  The feed's decimal separator.
	 * @param string $thousand_sep The feed's thousand separator.
	 *
	 * @return float|null The numeric value, or null when the value is not a
	 *                    number in either machine or configured-locale form.
	 */
	public static function parse( $value, string $decimal_sep, string $thousand_sep ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		// 1. Formatted with the configured separators — the inverse of
		// number_format() with this config (1–3 digits, then groups of 3).
		if ( '' !== $thousand_sep && false !== strpos( $value, $thousand_sep ) ) {
			$thou    = preg_quote( $thousand_sep, '/' );
			$dec     = '' !== $decimal_sep ? '(?:' . preg_quote( $decimal_sep, '/' ) . '\d+)?' : '';
			$pattern = '/^-?\d{1,3}(?:' . $thou . '\d{3})+' . $dec . '$/';

			if ( preg_match( $pattern, $value ) ) {
				$machine = str_replace( $thousand_sep, '', $value );
				if ( '' !== $decimal_sep && '.' !== $decimal_sep ) {
					$machine = str_replace( $decimal_sep, '.', $machine );
				}
				if ( is_numeric( $machine ) ) {
					return (float) $machine;
				}
			}
		}

		// 2. Decimal separator only (no thousands in the value).
		if ( '' !== $decimal_sep && '.' !== $decimal_sep && false !== strpos( $value, $decimal_sep ) ) {
			$machine = str_replace( $decimal_sep, '.', $value );
			if ( is_numeric( $machine ) ) {
				return (float) $machine;
			}
		}

		// 3. Plain machine-format numeric ("1499", "1499.99", "-3.5e2").
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Parse a RAW value: machine format first, the feed's separators as a fallback.
	 *
	 * V5 `FormatOutput::get_price_format()` read the raw value with a bare
	 * `(float)` cast. A value that is a machine-format number therefore keeps
	 * its machine meaning whatever the separators are ("17.991" → 17.991,
	 * "12.500" → 12.5, "1499" → 1499). Anything else ("1.499,50", "1 499",
	 * "1,299.00") is handed to {@see parse()}, which unwinds the configured
	 * separators or returns null. On the default machine format (2 / "." /
	 * none) this returns exactly what parse() returns.
	 *
	 * @since 8.0.39
	 * @implements XFRM-FRD-4.6 (codes 6/7 read raw input, CBT-811)
	 *
	 * @param mixed  $value        Raw numeric value.
	 * @param string $decimal_sep  The feed's decimal separator.
	 * @param string $thousand_sep The feed's thousand separator.
	 *
	 * @return float|null The numeric value, or null when the value is not a
	 *                    number in either machine or configured-locale form.
	 */
	public static function parse_raw( $value, string $decimal_sep, string $thousand_sep ): ?float {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		if ( is_numeric( $value ) ) {
			return (float) $value;
		}

		return self::parse( $value, $decimal_sep, $thousand_sep );
	}
}
