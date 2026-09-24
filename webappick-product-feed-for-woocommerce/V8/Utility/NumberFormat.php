<?php
/**
 * NumberFormat — the feed's price number format, V5 semantics.
 *
 * Port of V5 `Utility\Config::get_number_format()` (CBT-635 / CBT-640, owner
 * decision 2026-09-23: price formatting must behave as V5 did, free and Pro)
 * with the FREE half only. The base is the MACHINE format — 2 decimals, "."
 * decimal separator, NO thousand separator ("173427.98", CBT-641): safe for
 * every channel, unlike the store's WooCommerce display settings V5 used
 * ("1,099.90 USD" on US stores, "16.980" on HUF stores).
 *
 * The Pro branch — the feed's Filters-tab boxes, applied only for the boxes
 * the merchant filled in — lives in the Pro plugin
 * (`CTXFeed\Pro\Engine\NumberFormatOverrides`, CBT-643) and reaches this
 * resolver through the V5 `ctx_feed_number_format` filter. This file never
 * reads the boxes, so a comma seeded into a V5-era feed can never reach a
 * free user's file (wordpress.org "Feed generation ignores WP default
 * thousand separator", CBT-640). The boxes are never pre-populated.
 *
 * This triple is consumed ONLY by the "Price" (6) and "Rounded Price" (7)
 * output types and by custom2 dynamic-attribute results — exactly the three
 * places V5 used it. Plain price rows ship the raw WooCommerce value.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.27
 */

namespace CTXFeed\V8\Utility;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the feed's number format triple.
 *
 * @since 8.0.27
 */
final class NumberFormat {

	/**
	 * Resolve `[ decimals, decimal_separator, thousand_separator ]` for a feed.
	 *
	 * V5 `Config::get_number_format()`, free half:
	 *  - base = 2 decimals, `.`, no thousands (MACHINE format, CBT-641) — V5
	 *    used the store's WooCommerce display settings here;
	 *  - the array then passes through the V5 `ctx_feed_number_format` filter,
	 *    where the Pro engine applies the filled Filters-tab boxes (CBT-643)
	 *    and templates such as Idealo forced their own triple in V5.
	 *
	 * Separators are decoded with `wp_specialchars_decode( wp_unslash() )`
	 * so a slashed or entity-encoded form value (`&nbsp;`) formats correctly,
	 * as V5 `FormatOutput::get_price_format()` did.
	 *
	 * @since 8.0.27
	 * @implements XFRM-FRD-4.6 (codes 6/7), PROD-FRD-10 (custom2 dynamic attributes)
	 * @hook ctx_feed_number_format V5 filter, the resolved array (the Pro engine applies the boxes here).
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return array{decimals:int, decimal_separator:string, thousand_separator:string}
	 */
	public static function resolve( Config $config ): array {
		$number_format = array(
			'decimal_separator'  => '.',
			'thousand_separator' => '',
			'decimals'           => 2,
		);

		/**
		 * Filter the resolved feed number format (V5 hook).
		 *
		 * @since 8.0.27
		 *
		 * @param array  $number_format { decimals, decimal_separator, thousand_separator }.
		 * @param Config $config        Feed configuration.
		 */
		$number_format = (array) apply_filters( 'ctx_feed_number_format', $number_format, $config ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- V5 hook name kept verbatim for third-party parity.

		return array(
			'decimals'           => isset( $number_format['decimals'] ) ? (int) $number_format['decimals'] : 2,
			'decimal_separator'  => self::decode( isset( $number_format['decimal_separator'] ) ? (string) $number_format['decimal_separator'] : '.' ),
			'thousand_separator' => self::decode( isset( $number_format['thousand_separator'] ) ? (string) $number_format['thousand_separator'] : '' ),
		);
	}

	/**
	 * Format a value the way V5 `FormatOutput::get_price_format()` did.
	 *
	 * @since 8.0.27
	 *
	 * @param float $number Parsed numeric value.
	 * @param array $nf     Triple from {@see resolve()}.
	 *
	 * @return string
	 */
	public static function format( float $number, array $nf ): string {
		return number_format( $number, (int) $nf['decimals'], (string) $nf['decimal_separator'], (string) $nf['thousand_separator'] );
	}

	/**
	 * Decode a stored separator (`wp_specialchars_decode( wp_unslash() )`, V5).
	 *
	 * @since 8.0.27
	 *
	 * @param string $separator Stored separator.
	 *
	 * @return string
	 */
	private static function decode( string $separator ): string {
		if ( function_exists( 'wp_specialchars_decode' ) && function_exists( 'wp_unslash' ) ) {
			return (string) wp_specialchars_decode( wp_unslash( $separator ) );
		}
		return $separator;
	}
}
