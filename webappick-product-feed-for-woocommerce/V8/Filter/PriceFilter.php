<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "wf" is an established V5-era prefix registered in phpcs.xml.dist; renaming would break 100K+ existing installs.
/**
 * PriceFilter — Filters products based on price range.
 *
 * Excludes products outside min/max price thresholds and optionally
 * excludes products with empty or zero prices.
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-4.1, FLTR-FRD-4.2
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Price filter.
 *
 * @since 8.0.0
 */
class PriceFilter implements FilterInterface {

	/**
	 * Check if a product passes the price filter.
	 *
	 * Evaluates min/max price range and empty price exclusion.
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-4.1, FLTR-FRD-4.2
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		$price = (float) $product->get_price();

		$min = (float) $config->get( 'filter_price_min', 0 );
		$max = (float) $config->get( 'filter_price_max', 0 );

		// Minimum price check. @implements FLTR-FRD-4.1.
		if ( $min > 0 && $price < $min ) {
			return false;
		}

		// Maximum price check. @implements FLTR-FRD-4.1.
		if ( $max > 0 && $price > $max ) {
			return false;
		}

		// Empty price exclusion. @implements FLTR-FRD-4.2.
		if ( $config->get( 'exclude_empty_price', false ) && $price <= 0 ) {
			return false;
		}

		return true;
	}
}
