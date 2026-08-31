<?php
/**
 * FilterInterface — Contract for all product filter classes.
 *
 * Each filter evaluates whether a product should be included in
 * feed output. Returns true to include, false to exclude.
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-2.1
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter interface.
 *
 * @since 8.0.0
 */
interface FilterInterface {

	/**
	 * Check if a product passes this filter.
	 *
	 * Implementations SHALL return true if the product passes (include),
	 * false if the product fails (exclude), and true when no applicable
	 * config rules exist (no-op behavior).
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool;
}
