<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "wf" is an established V5-era prefix registered in phpcs.xml.dist; renaming would break 100K+ existing installs.
/**
 * FilterServiceProvider — Registers filter module services.
 *
 * Fifth ServiceProvider in the Bootstrap registration order.
 * Binds 10 services: 9 individual filters and the FilterManager orchestrator.
 * During boot(), resolves filters, builds ordered array, applies filter hook,
 * and injects into FilterManager.
 *
 * @package    CTXFeed
 * @subpackage V8/Filter
 * @since      8.0.0
 * @implements FLTR-FRD-10.1, FLTR-FRD-10.2
 */

namespace CTXFeed\V8\Filter;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter module service provider.
 *
 * @since 8.0.0
 */
class FilterServiceProvider extends ServiceProvider {

	/**
	 * Register filter services into the container.
	 *
	 * Services registered:
	 * - filter.stock:        Out-of-stock / backorder filter (V5 keys: is_outOfStock, is_backorder).
	 * - filter.visibility:   Hidden + out-of-stock visibility (V5 keys: product_visibility, outofstock_visibility).
	 * - filter.empty_field:  Empty description/image/price (V5 keys: is_emptyDescription/Image/Price).
	 * - filter.price:        Price range filter.
	 * - filter.category:     Category include/exclude filter.
	 * - filter.tag:          Tag include/exclude filter.
	 * - filter.attribute:    WC attribute filter with AND logic.
	 * - filter.product_type: Product type filter (Pro feature).
	 * - filter.custom:       Custom meta-key filter rules (Pro feature).
	 * - filter.manager:      FilterManager orchestrator (populated during boot).
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-10.1
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->register(
			'filter.stock',
			function () {
				return new StockFilter();
			} 
		);

		$container->register(
			'filter.visibility',
			function () {
				return new VisibilityFilter();
			} 
		);

		$container->register(
			'filter.empty_field',
			function () {
				return new EmptyFieldFilter();
			} 
		);

		$container->register(
			'filter.product_id',
			function () {
				return new ProductIdFilter();
			} 
		);

		$container->register(
			'filter.price',
			function () {
				return new PriceFilter();
			} 
		);

		$container->register(
			'filter.category',
			function () {
				return new CategoryFilter();
			} 
		);

		$container->register(
			'filter.tag',
			function () {
				return new TagFilter();
			} 
		);

		$container->register(
			'filter.attribute',
			function () {
				return new AttributeFilter();
			} 
		);

		$container->register(
			'filter.product_type',
			function () {
				return new ProductTypeFilter();
			} 
		);

		$container->register(
			'filter.custom',
			function () {
				return new CustomFilter();
			} 
		);

		// Advanced filter — V5-compat condition-based filter (Pro feature,
		// gated by FeatureGate::has('product_filter')). Reads V5 keys
		// directly. Resolved here as a placeholder; AttributeResolver
		// dependency is injected in boot() once Product module is bound.
		$container->register(
			'filter.advance',
			function () {
				return new AdvanceFilter();
			} 
		);

		// Manager is registered as placeholder; populated in boot().
		$container->register(
			'filter.manager',
			function () {
				return new FilterManager( array() );
			} 
		);
	}

	/**
	 * Boot filter services.
	 *
	 * Resolves all 7 filters, builds the ordered array per FLTR-FRD-1.4,
	 * applies the `ctxfeed_product_filters` filter, and re-registers the
	 * FilterManager with the final filter array.
	 *
	 * Filter order (cheap checks first):
	 * 1. StockFilter        (V5 keys)
	 * 2. VisibilityFilter   (V5 keys)
	 * 3. EmptyFieldFilter   (V5 keys)
	 * 4. PriceFilter
	 * 5. CategoryFilter
	 * 6. TagFilter
	 * 7. AttributeFilter
	 * 8. ProductTypeFilter  [Pro]
	 * 9. CustomFilter       [Pro]
	 *
	 * @since 8.0.0
	 * @implements FLTR-FRD-10.2
	 * @hook ctxfeed_product_filters Filter to modify the filter chain.
	 *
	 * @param Container $container DI container.
	 *
	 * @return void
	 */
	public function boot( Container $container ): void {
		// Re-bind AdvanceFilter with the AttributeResolver dependency.
		// Product module's register() has already run by this point so the
		// resolver is bound; we couldn't inject it during this provider's
		// register() because phase-1 ordering doesn't guarantee the other
		// module's services are constructed yet.
		if ( $container->has( 'product.attribute_resolver' ) ) {
			$attribute_resolver = $container->resolve( 'product.attribute_resolver' );
			$container->register(
				'filter.advance',
				function () use ( $attribute_resolver ) {
					return new AdvanceFilter( $attribute_resolver );
				} 
			);
		}

		// Build ordered filter array per FLTR-FRD-1.4.
		// Cheap checks first (stock status, visibility, empty fields),
		// more expensive ones later (categories, tags, attributes, custom,
		// advanced conditions).
		$filters = array(
			// Fastest checks first (array membership, stock status).
			$container->resolve( 'filter.product_id' ),
			$container->resolve( 'filter.stock' ),
			$container->resolve( 'filter.visibility' ),
			$container->resolve( 'filter.empty_field' ),
			$container->resolve( 'filter.price' ),
			$container->resolve( 'filter.category' ),
			$container->resolve( 'filter.tag' ),
			$container->resolve( 'filter.attribute' ),
			$container->resolve( 'filter.product_type' ),
			$container->resolve( 'filter.custom' ),
			// AdvanceFilter last — it resolves attribute values per-row,
			// which is the most expensive check in the chain. Cheaper
			// filters that already excluded a product save the cost.
			$container->resolve( 'filter.advance' ),
		);

		/**
		 * Filter the product filter chain.
		 *
		 * Allows third-party code to add, remove, or reorder filters.
		 * All items MUST implement FilterInterface.
		 *
		 * @since 8.0.0
		 *
		 * @param FilterInterface[] $filters Ordered array of filter instances.
		 */
		$filters = apply_filters( 'ctxfeed_product_filters', $filters );

		// Re-register manager with final filter array.
		$container->register(
			'filter.manager',
			function () use ( $filters ) {
				return new FilterManager( $filters );
			} 
		);
	}
}
