<?php
/**
 * AttributeFilter — Filters products by WooCommerce product attributes.
 *
 * Supports multi-attribute AND logic with include/exclude operators per
 * attribute. E.g., include only products with pa_color = 'red' AND
 * pa_size = 'large'. Logs WP_Error from taxonomy queries.
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-7.1, FLTR-FRD-7.2, FLTR-FRD-7.3
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attribute filter with AND logic.
 *
 * @since 8.0.0
 */
class AttributeFilter implements FilterInterface {

	/**
	 * Check if a product passes all attribute filters.
	 *
	 * Uses AND logic: all attribute filter entries must pass.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-7.1, FLTR-FRD-7.2, FLTR-FRD-7.3
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		$attr_filters = $config->get( 'attribute_filters', array() );

		if ( empty( $attr_filters ) ) {
			return true;
		}

		foreach ( $attr_filters as $filter ) {
			$taxonomy = isset( $filter['attribute'] ) ? $filter['attribute'] : '';
			$values   = isset( $filter['values'] ) ? $filter['values'] : array();
			$operator = isset( $filter['operator'] ) ? $filter['operator'] : 'include';

			if ( empty( $taxonomy ) || empty( $values ) ) {
				continue;
			}

			$product_terms = wp_get_post_terms( $product->get_id(), $taxonomy, array( 'fields' => 'slugs' ) );

			// Log WP_Error before continuing. @implements FLTR-FRD-7.3.
			if ( is_wp_error( $product_terms ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for taxonomy errors.
				error_log(
					sprintf(
						'CTXFeed: AttributeFilter WP_Error for product %d, taxonomy %s: %s',
						$product->get_id(),
						$taxonomy,
						$product_terms->get_error_message()
					)
				);
				continue;
			}

			$match = ! empty( array_intersect( $product_terms, $values ) );

			// Operator evaluation via switch. @implements FLTR-FRD-7.2.
			switch ( $operator ) {
				case 'include':
					if ( ! $match ) {
						return false;
					}
					break;

				case 'exclude':
					if ( $match ) {
						return false;
					}
					break;
			}
		}

		return true;
	}
}
