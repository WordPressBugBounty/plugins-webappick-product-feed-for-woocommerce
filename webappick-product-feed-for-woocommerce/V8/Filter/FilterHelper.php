<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "wf" is an established V5-era prefix registered in phpcs.xml.dist; renaming would break 100K+ existing installs.
/**
 * FilterHelper — Shared helpers for filter classes.
 *
 * Provides a safe truthy-value normalizer so filter classes don't silently
 * misinterpret V5 string values like 'no' (which casts to bool `true` in
 * PHP because it's a non-empty string).
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 */

namespace CTXFeed\V8\Filter;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter helpers.
 *
 * @since 8.0.0
 */
class FilterHelper {

	/**
	 * Normalize a feedrules value to a strict boolean.
	 *
	 * Handles V5-era string values ('yes', 'no', 'on', 'off', '1', '0', 'y', 'n',
	 * 'enable', 'disable', 'true', 'false'), as well as native booleans and ints.
	 * Anything that doesn't clearly resolve to truthy is treated as `false`.
	 *
	 * This avoids the PHP pitfall where `(bool) 'no'` returns `true` because
	 * 'no' is a non-empty string.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Raw feedrules value.
	 * @return bool
	 */
	public static function bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}

		$normalised = strtolower( trim( $value ) );

		return in_array(
			$normalised,
			array( 'yes', 'y', 'on', 'enable', '1', 'true' ),
			true
		);
	}
}
