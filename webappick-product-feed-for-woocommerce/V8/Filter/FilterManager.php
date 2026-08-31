<?php
/**
 * FilterManager — Orchestrates product filtering based on feed config rules.
 *
 * Determines which products to include/exclude from the feed using
 * short-circuit evaluation. Accepts filters via DI constructor injection.
 * Fires per-filter action hooks for monitoring and debugging.
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-1.1, FLTR-FRD-1.2, FLTR-FRD-1.3, FLTR-FRD-1.4
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter manager with short-circuit evaluation.
 *
 * @since 8.0.0
 */
class FilterManager {

	/**
	 * Ordered array of filter instances.
	 *
	 * @since 8.0.0
	 * @var FilterInterface[]
	 */
	private $filters;

	/**
	 * Construct FilterManager with DI-injected filters.
	 *
	 * Filters SHALL NOT be instantiated internally.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-1.1
	 *
	 * @param array $filters Ordered array of FilterInterface instances.
	 */
	public function __construct( array $filters ) {
		$this->filters = $filters;
	}

	/**
	 * Check if a product passes all filters (short-circuit evaluation).
	 *
	 * Iterates through the filter chain. On the first failure, fires the
	 * `ctxfeed_filter_excluded` action and returns false immediately.
	 * After all filters pass, applies the `ctxfeed_product_should_include`
	 * filter for final override.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-1.2
	 * @hook ctxfeed_filter_passed   Action fired when a filter passes.
	 * @hook ctxfeed_filter_excluded Action fired when a filter excludes.
	 * @hook ctxfeed_product_should_include Filter for final override.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product should be included in feed.
	 */
	public function should_include( \WC_Product $product, Config $config ): bool {
		foreach ( $this->filters as $filter ) {
			if ( ! $filter instanceof FilterInterface ) {
				continue;
			}

			$filter_name = $this->get_filter_name( $filter );

			if ( ! $filter->passes( $product, $config ) ) {
				/**
				 * Fires when a filter excludes a product.
				 *
				 * @since 8.0.0
				 *
				 * @param string      $filter_name Filter identifier (e.g., 'stock', 'price').
				 * @param \WC_Product $product     WooCommerce product.
				 * @param Config      $config      Feed configuration.
				 */
				do_action( 'ctxfeed_filter_excluded', $filter_name, $product, $config );

				return false;
			}

			/**
			 * Fires when a filter passes a product.
			 *
			 * @since 8.0.0
			 *
			 * @param string      $filter_name Filter identifier (e.g., 'stock', 'price').
			 * @param \WC_Product $product     WooCommerce product.
			 * @param Config      $config      Feed configuration.
			 */
			do_action( 'ctxfeed_filter_passed', $filter_name, $product, $config );
		}

		/**
		 * Filter the final include/exclude decision.
		 *
		 * Fires after all filters have passed. Allows final override
		 * of the inclusion decision.
		 *
		 * @since 8.0.0
		 *
		 * @param bool        $include Whether to include the product.
		 * @param \WC_Product $product WooCommerce product.
		 * @param Config      $config  Feed configuration.
		 */
		return (bool) apply_filters( 'ctxfeed_product_should_include', true, $product, $config );
	}

	/**
	 * Derive a filter name from the class short name.
	 *
	 * Strips the "Filter" suffix and converts to lowercase snake_case.
	 * Example: StockFilter → stock, CategoryFilter → category,
	 * ProductTypeFilter → product_type.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-1.3
	 *
	 * @param FilterInterface $filter Filter instance.
	 *
	 * @return string Lowercase filter name.
	 */
	private function get_filter_name( FilterInterface $filter ): string {
		$class_name = get_class( $filter );
		$short_name = substr( strrchr( $class_name, '\\' ), 1 );

		// Remove "Filter" suffix.
		$name = str_replace( 'Filter', '', $short_name );

		// Convert PascalCase to snake_case.
		$name = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $name ) );

		return $name;
	}
}
