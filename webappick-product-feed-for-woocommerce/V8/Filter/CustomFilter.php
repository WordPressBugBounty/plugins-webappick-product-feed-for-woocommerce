<?php
/**
 * CustomFilter — Pro feature for advanced filtering rules.
 *
 * Supports custom meta-key conditions with 7 operators. Pro feature
 * gated via FeatureGate. Uses switch/strpos for PHP 7.4+ compatibility
 * (AD-FLTR-002, AD-FLTR-003).
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-9.1, FLTR-FRD-9.2, FLTR-FRD-9.3, FLTR-FRD-9.4
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\FeatureGate;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom filter (Pro feature).
 *
 * @since 8.0.0
 */
class CustomFilter implements FilterInterface {

	/**
	 * Check if a product passes all custom filter rules.
	 *
	 * Gated behind `FeatureGate::has( 'custom_filters' )`. Rules are
	 * read from `custom_filter_rules` config and evaluated sequentially.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-9.1, FLTR-FRD-9.4
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		// Pro feature gate. @implements FLTR-FRD-9.1.
		if ( ! FeatureGate::has( 'custom_filters' ) ) {
			return true;
		}

		$custom_rules = $config->get( 'custom_filter_rules', array() );

		// Sequential evaluation. @implements FLTR-FRD-9.4.
		foreach ( $custom_rules as $rule ) {
			if ( ! $this->evaluate_rule( $product, $rule ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Evaluate a single custom filter rule against a product.
	 *
	 * Reads the meta key from the product and compares against the
	 * expected value using the specified operator. Uses switch (not
	 * match) and strpos (not str_contains) for PHP 7.4 compatibility.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-9.2, FLTR-FRD-9.3
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param array       $rule    Rule with meta_key, operator, value.
	 *
	 * @return bool True if rule passes, false to exclude.
	 */
	private function evaluate_rule( \WC_Product $product, array $rule ): bool {
		$meta_key = isset( $rule['meta_key'] ) ? $rule['meta_key'] : '';
		$operator = isset( $rule['operator'] ) ? $rule['operator'] : 'equals';
		$expected = isset( $rule['value'] ) ? $rule['value'] : '';

		if ( empty( $meta_key ) ) {
			return true;
		}

		$actual = $product->get_meta( $meta_key );

		switch ( $operator ) {
			case 'equals':
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Intentional loose comparison: meta values are loosely typed (V5 parity; "10" must equal 10).
				return $actual == $expected;

			case 'not_equals':
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Intentional loose comparison: meta values are loosely typed (V5 parity; "10" must equal 10).
				return $actual != $expected;

			case 'contains':
				return false !== strpos( (string) $actual, (string) $expected );

			case 'not_contains':
				return false === strpos( (string) $actual, (string) $expected );

			case 'regex':
				return (bool) preg_match( $expected, (string) $actual );

			case 'is_empty':
				return empty( $actual );

			case 'is_not_empty':
				return ! empty( $actual );

			default:
				return true;
		}
	}
}
