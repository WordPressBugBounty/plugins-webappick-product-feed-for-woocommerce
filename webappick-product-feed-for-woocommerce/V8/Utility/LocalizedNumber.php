<?php
/**
 * LocalizedNumber — parse a possibly-already-formatted number back to a float.
 *
 * The feed pipeline can run number_format() on a price more than once:
 * PriceResolver formats at resolution, NumberTransform formats price
 * attributes in every feed's default pipeline, and output codes 6/7 format
 * again when mapped. Re-formatting a formatted string through a bare
 * `(float)` cast corrupts it whenever the thousand separator parses as a
 * decimal point — with decimals=0, decimal "," and thousand ".",
 * 1499 → "1.499" → (float) 1.499 → "1", so a 1 499 SEK product reached
 * Google as 1 SEK (support #68878, Käpprätt AB).
 *
 * This helper is the exact inverse of number_format() UNDER THE SAME feed
 * configuration: a value matching the configured grouped-thousands pattern
 * is unwound (strip thousand separator, normalise the decimal separator to
 * '.'), a decimal-separator-only value is normalised, and a plain
 * machine-format numeric passes through. Every formatting stage that
 * parses with this helper before calling number_format() becomes
 * idempotent — running it once or three times yields the same output.
 *
 * Ambiguity note: with thousand separator '.', the string "1.499" could in
 * principle be a machine-format 1.499. Inside this pipeline that reading is
 * wrong by construction — values reaching the transforms have already been
 * formatted by PriceResolver with the SAME configuration — so the
 * config-formatted interpretation deliberately wins.
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
	 * Parse a value that may already be formatted with the feed's separators.
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
}
