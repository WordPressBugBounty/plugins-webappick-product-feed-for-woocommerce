<?php
/**
 * TypelessProductFilter — keeps half-written typeless products out (CBT-683).
 *
 * Products with no product type saved join simple-including feeds (see
 * Product\ProductQuery / Product\TypelessProducts). They usually come from
 * imports and are sometimes broken, so each one is checked on the object
 * the generation loop already loaded: WooCommerce must load it as a simple
 * product and its built-in getters must not throw. Every other product —
 * and every product on a store without typeless products — passes at the
 * cost of one memoised boolean.
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.30
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Product\ProductQuery;
use CTXFeed\V8\Product\TypelessProducts;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typeless product validity filter.
 *
 * @since 8.0.30
 */
class TypelessProductFilter implements FilterInterface {

	/**
	 * The feed config the cached verdict belongs to (identity-compared, so
	 * a new feed in the same request re-decides).
	 *
	 * @var Config|null
	 */
	private $config = null;

	/**
	 * Whether the check is active for $config's feed: the store has
	 * products without a product type for that status set.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Check if a product passes.
	 *
	 * @since 8.0.30
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @param Config      $config  Feed configuration.
	 *
	 * @return bool True if product passes, false to exclude.
	 */
	public function passes( \WC_Product $product, Config $config ): bool {
		// Decided once per feed config: the same status set the feed query
		// used, so the widening and this check switch on together. On a store
		// without typeless products every later product costs one identity
		// comparison.
		if ( $this->config !== $config ) {
			$this->config = $config;
			$this->active = TypelessProducts::exist( ProductQuery::resolve_post_status( $config ) );
		}
		if ( ! $this->active ) {
			return true;
		}

		// Variations always carry their parent's type context; only product
		// posts can be typeless.
		if ( $product->is_type( 'variation' ) ) {
			return true;
		}

		if ( ! TypelessProducts::lacks_type( (int) $product->get_id() ) ) {
			return true;
		}

		if ( TypelessProducts::is_usable( $product ) ) {
			return true;
		}

		Logger::warning(
			sprintf( 'Product #%d has no product type and WooCommerce cannot read it cleanly — left out of the feed.', (int) $product->get_id() )
		);

		return false;
	}
}
