<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed -- "wf" is an established V5-era prefix registered in phpcs.xml.dist; renaming would break 100K+ existing installs.
/**
 * FilterServiceProvider — Registers filter module services.
 *
 * Fifth ServiceProvider in the Bootstrap registration order.
 * Binds the FREE filters (visibility, price, tag, attribute) and the
 * FilterManager orchestrator. During boot(), resolves them, builds the
 * ordered array, applies the `ctxfeed_product_filters` hook — where the Pro
 * plugin slots in every Filters-tab selection filter (CBT-650) and its
 * rule engines (CBT-645..647) — and injects the result into FilterManager.
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
	 * - filter.visibility:   Hidden-product removal + WC "hide out of stock" (free defaults;
	 *                        the two Pro toggles arrive via ctxfeed_visibility_filter_options).
	 * - filter.price:        Price range filter.
	 * - filter.tag:          Tag include/exclude filter.
	 * - filter.attribute:    WC attribute filter with AND logic.
	 * - filter.manager:      FilterManager orchestrator (populated during boot).
	 *
	 * NOT bound here since CBT-650 (Pro engines, Pro\Engine\ProductSelection):
	 * filter.stock, filter.empty_field, filter.product_id, filter.category —
	 * the Filters-tab product selection the free UI shows locked.
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
			'filter.visibility',
			function () {
				return new VisibilityFilter();
			} 
		);

		$container->register(
			'filter.price',
			function () {
				return new PriceFilter();
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

		// The Pro filter engines live in the Pro plugin and reach the chain
		// through ctxfeed_product_filters: the Filters-tab selection filters
		// (Engine\ProductSelection, CBT-650) and the product-type / custom /
		// advanced rule engines (Engine\Filters, CBT-645).

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
	 * Resolves the free filters, builds the ordered array per FLTR-FRD-1.4,
	 * applies the `ctxfeed_product_filters` filter, and re-registers the
	 * FilterManager with the final filter array.
	 *
	 * Filter order (cheap checks first) once the Pro engines are hooked:
	 * 1. ProductIdFilter    [Pro, before VisibilityFilter]
	 * 2. StockFilter        [Pro, before VisibilityFilter]
	 * 3. VisibilityFilter   (free defaults + Pro toggles)
	 * 4. EmptyFieldFilter   [Pro, after VisibilityFilter]
	 * 5. PriceFilter
	 * 6. CategoryFilter     [Pro, after PriceFilter]
	 * 7. TagFilter
	 * 8. AttributeFilter
	 * 9. ProductTypeFilter  [Pro plugin, via ctxfeed_product_filters]
	 * 10. CustomFilter      [Pro plugin, via ctxfeed_product_filters]
	 * 11. AdvanceFilter     [Pro plugin, appended last]
	 * Free without Pro runs 3, 5, 7, 8 only.
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
		// Build ordered filter array per FLTR-FRD-1.4.
		// Cheap checks first (stock status, visibility, empty fields),
		// more expensive ones later (categories, tags, attributes, custom,
		// advanced conditions).
		$filters = array(
			// The free chain. The Pro ProductSelection engine slots
			// ProductIdFilter + StockFilter before, EmptyFieldFilter after
			// VisibilityFilter and CategoryFilter after PriceFilter (CBT-650);
			// the Pro Filters engine appends ProductTypeFilter, CustomFilter
			// and — last, it resolves attribute values per row — AdvanceFilter
			// (CBT-645). All through the ctxfeed_product_filters filter below.
			$container->resolve( 'filter.visibility' ),
			$container->resolve( 'filter.price' ),
			$container->resolve( 'filter.tag' ),
			$container->resolve( 'filter.attribute' ),
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
