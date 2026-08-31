<?php
/**
 * ProductTypeFilter — Filters products by WooCommerce product type.
 *
 * Supports include/exclude lists of product types (simple, variable,
 * grouped, external) and third-party custom types. Exclusion takes
 * priority over inclusion. Pro feature gated via FeatureGate.
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-8.1, FLTR-FRD-8.2, FLTR-FRD-8.3, FLTR-FRD-8.4
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\FeatureGate;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product type filter (Pro feature).
 *
 * @since 8.0.0
 */
class ProductTypeFilter implements FilterInterface {

	/**
	 * Check if a product passes the product type filter.
	 *
	 * Gated behind `FeatureGate::has( 'product_type_filter' )`.
	 * Supports include/exclude lists with exclusion taking priority.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-8.1, FLTR-FRD-8.2, FLTR-FRD-8.3
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		// Pro feature gate. @implements FLTR-FRD-8.1.
		if ( ! FeatureGate::has( 'product_type_filter' ) ) {
			return true;
		}

		$include_types = $config->get( 'include_product_types', array() );
		$exclude_types = $config->get( 'exclude_product_types', array() );

		// No type config set — pass all. @implements FLTR-FRD-8.2.
		if ( empty( $include_types ) && empty( $exclude_types ) ) {
			return true;
		}

		$product_type = $product->get_type();

		// Exclusion takes priority. @implements FLTR-FRD-8.3.
		if ( ! empty( $exclude_types ) && in_array( $product_type, $exclude_types, true ) ) {
			return false;
		}

		// If inclusion list set, product type must be in the list.
		if ( ! empty( $include_types ) && ! in_array( $product_type, $include_types, true ) ) {
			return false;
		}

		return true;
	}
}
