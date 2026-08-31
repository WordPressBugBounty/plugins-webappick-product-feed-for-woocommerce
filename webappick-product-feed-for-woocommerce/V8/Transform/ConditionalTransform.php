<?php
/**
 * ConditionalTransform — Applies if/then rules to product attributes.
 *
 * Enables value overrides based on conditions (e.g., if stock < 5,
 * set availability). Pro feature gated via FeatureGate.
 * Uses switch/strpos for PHP 7.4+ compatibility (AD-XFRM-003, AD-XFRM-004).
 *
 * @package    CTXFeed
 * @subpackage V8/Transform
 * @since      8.0.0
 * @implements XFRM-FRD-7.1, XFRM-FRD-7.2, XFRM-FRD-7.3, XFRM-FRD-7.4
 */

namespace CTXFeed\V8\Transform;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\FeatureGate;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conditional transform (Pro feature).
 *
 * @since 8.0.0
 */
class ConditionalTransform implements TransformInterface {

	/**
	 * Apply conditional if/then rules to product attributes.
	 *
	 * Gated behind `FeatureGate::has( 'custom_filters' )`. Rules are
	 * read from `conditional_rules` config array and applied sequentially.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-7.1
	 *
	 * @param array  $product_data Resolved product attributes.
	 * @param Config $config       Feed configuration.
	 *
	 * @return array Product data with conditional overrides applied.
	 */
	public function transform( array $product_data, Config $config ): array {
		// Pro feature gate. @implements XFRM-FRD-7.1.
		if ( ! FeatureGate::has( 'custom_filters' ) ) {
			return $product_data;
		}

		$rules = $config->get( 'conditional_rules', array() );

		foreach ( $rules as $rule ) {
			$product_data = $this->apply_rule( $product_data, $rule );
		}

		return $product_data;
	}

	/**
	 * Apply a single conditional rule.
	 *
	 * If the field exists and the condition evaluates true, apply the
	 * `then` overrides to the product data.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-7.4
	 *
	 * @param array $data Product attributes.
	 * @param array $rule Rule configuration with field, operator, value, then.
	 *
	 * @return array Product data with rule applied.
	 */
	private function apply_rule( array $data, array $rule ): array {
		$field    = isset( $rule['field'] ) ? $rule['field'] : '';
		$operator = isset( $rule['operator'] ) ? $rule['operator'] : '';
		$value    = isset( $rule['value'] ) ? $rule['value'] : '';
		$then     = isset( $rule['then'] ) ? $rule['then'] : array();

		if ( empty( $field ) || ! isset( $data[ $field ] ) ) {
			return $data;
		}

		if ( $this->evaluate( $data[ $field ], $operator, $value ) ) {
			foreach ( $then as $target => $new_value ) {
				$data[ $target ] = $new_value;
			}
		}

		return $data;
	}

	/**
	 * Evaluate a condition against an actual value.
	 *
	 * Supports 7 operators per XFRM-FRD-7.3. Uses switch (not match)
	 * and strpos (not str_contains) for PHP 7.4+ compatibility.
	 *
	 * @since 8.0.0
	 * @implements XFRM-FRD-7.3
	 *
	 * @param mixed  $actual   Actual attribute value.
	 * @param string $operator Comparison operator.
	 * @param mixed  $expected Expected value to compare against.
	 *
	 * @return bool True if condition is met.
	 */
	private function evaluate( $actual, string $operator, $expected ): bool {
		switch ( $operator ) {
			case 'equals':
				// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison, Universal.Operators.StrictComparisons.LooseEqual -- Intentional loose comparison: condition values compare mixed types (string config vs numeric attribute).
				return $actual == $expected;

			case 'not_equals':
				// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison, Universal.Operators.StrictComparisons.LooseNotEqual -- Intentional loose comparison: condition values compare mixed types (string config vs numeric attribute).
				return $actual != $expected;

			case 'contains':
				return false !== strpos( (string) $actual, (string) $expected );

			case 'greater_than':
				return (float) $actual > (float) $expected;

			case 'less_than':
				return (float) $actual < (float) $expected;

			case 'is_empty':
				return empty( $actual );

			case 'is_not_empty':
				return ! empty( $actual );

			default:
				return false;
		}
	}
}
