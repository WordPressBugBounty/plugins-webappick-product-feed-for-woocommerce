<?php
/**
 * ProductServiceProvider — Registers product module services.
 *
 * Third ServiceProvider in the Bootstrap registration order.
 * Binds 18 services: cache_warmer, 12 resolvers, attribute_resolver,
 * product_repository, product_query, attribute_registry, and taxonomy_registry.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-14.1, PROD-FRD-14.2
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product module service provider.
 *
 * @since 8.0.0
 */
class ProductServiceProvider extends ServiceProvider {

	/**
	 * Register product services into the container.
	 *
	 * Services registered:
	 * - cache_warmer:          Bulk cache priming (3 queries for entire batch).
	 * - meta_resolver:         Post meta resolution (cache-primed).
	 * - taxonomy_resolver:     Taxonomy term resolution with hierarchical paths.
	 * - price_resolver:        Price resolution with tax and currency formatting.
	 * - image_resolver:        Featured, gallery, and additional image URLs.
	 * - seo_resolver:          Yoast/RankMath/AIOSEO meta resolution.
	 * - custom_field_resolver: ACF/Toolset/raw meta resolution.
	 * - v5_custom_field_resolver: V5-compat woo_feed_* identifier resolution
	 *                             (variation _var suffix, legacy old key
	 *                             fallback, availability_date ISO formatting).
	 * - variation_resolver:    Parent product fallback for variations.
	 * - shipping_resolver:    Dynamic WC shipping zone resolution.
	 * - tax_resolver:         Dynamic WC tax rate resolution.
	 * - wp_option_resolver:   WordPress site option resolution (wf_option_*).
	 * - attr_mapping_resolver: User-defined attribute mapping resolution (wp_attr_mapping_*).
	 * - dynamic_attr_resolver: Conditional dynamic attribute resolution (wf_dattribute_*).
	 * - attribute_resolver:    Central router dispatching to sub-resolvers.
	 * - product_repository:    Batch product resolution with dual-filter chain.
	 * - product_query:         WC_Product_Query builder with variation expansion.
	 * - attribute_registry:    Grouped attribute catalog for admin UI.
	 * - taxonomy_registry:     Product taxonomy catalog for admin UI.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-14.1
	 *
	 * @param Container $container The DI container instance.
	 *
	 * @return void
	 */
	public function register( Container $container ): void {

		// Foundation services (no dependencies).
		$container->register(
			'product.cache_warmer',
			function () {
				return new CacheWarmer();
			} 
		);

		$container->register(
			'product.meta_resolver',
			function () {
				return new MetaResolver();
			} 
		);

		$container->register(
			'product.taxonomy_resolver',
			function () {
				return new TaxonomyResolver();
			} 
		);

		$container->register(
			'product.price_resolver',
			function () {
				return new PriceResolver();
			} 
		);

		$container->register(
			'product.image_resolver',
			function () {
				return new ImageResolver();
			} 
		);

		$container->register(
			'product.seo_resolver',
			function () {
				return new SEOResolver();
			} 
		);

		$container->register(
			'product.custom_field_resolver',
			function () {
				return new CustomFieldResolver();
			} 
		);

		// V5-compat custom-field resolver — handles woo_feed_* identifier
		// meta keys with the legacy lookup chain (variation _var suffix, old
		// woo_feed_identifier_* fallback, parent walk, availability_date ISO
		// formatting). PROD-FRD-10.2.
		$container->register(
			'product.v5_custom_field_resolver',
			function () {
				return new V5CustomFieldResolver();
			} 
		);

		// V5-compat category-mapping resolver — handles wf_cmapping_*
		// attribute keys at feed-gen time. Closes a silent data-loss
		// regression where V8 returned empty cells for any V5 customer
		// using `wf_cmapping_<name>` as a feed config attribute value.
		// PROD-FRD-10.4.
		$container->register(
			'product.category_mapping_resolver',
			function () {
				return new CategoryMappingResolver();
			} 
		);

		$container->register(
			'product.variation_resolver',
			function () {
				return new VariationResolver();
			} 
		);

		$container->register(
			'product.shipping_resolver',
			function () {
				return new ShippingResolver();
			} 
		);

		$container->register(
			'product.tax_resolver',
			function () {
				return new TaxResolver();
			} 
		);

		$container->register(
			'product.wp_option_resolver',
			function () {
				return new WpOptionResolver();
			} 
		);

		$container->register(
			'product.attr_mapping_resolver',
			function () {
				return new AttributeMappingResolver();
			} 
		);

		$container->register(
			'product.dynamic_attr_resolver',
			function () {
				return new DynamicAttributeResolver();
			} 
		);

		// Integration services (depend on foundation).
		$container->register(
			'product.attribute_resolver',
			function ( Container $c ) {
				return new AttributeResolver(
					$c->resolve( 'product.meta_resolver' ),
					$c->resolve( 'product.taxonomy_resolver' ),
					$c->resolve( 'product.price_resolver' ),
					$c->resolve( 'product.image_resolver' ),
					$c->resolve( 'product.seo_resolver' ),
					$c->resolve( 'product.custom_field_resolver' ),
					$c->resolve( 'product.variation_resolver' ),
					$c->resolve( 'product.shipping_resolver' ),
					$c->resolve( 'product.tax_resolver' ),
					$c->resolve( 'product.wp_option_resolver' ),
					$c->resolve( 'product.attr_mapping_resolver' ),
					$c->resolve( 'product.dynamic_attr_resolver' ),
					$c->resolve( 'product.v5_custom_field_resolver' ),
					$c->resolve( 'product.category_mapping_resolver' )
				);
			} 
		);

		$container->register(
			'product.repository',
			function ( Container $c ) {
				return new ProductRepository(
					$c->resolve( 'product.cache_warmer' ),
					$c->resolve( 'product.attribute_resolver' )
				);
			} 
		);

		$container->register(
			'product.review_resolver',
			function ( Container $c ) {
				return new ReviewResolver(
					$c->resolve( 'product.attribute_resolver' )
				);
			} 
		);

		$container->register(
			'product.query',
			function () {
				return new ProductQuery();
			} 
		);

		$container->register(
			'product.attribute_registry',
			function () {
				return new AttributeRegistry();
			} 
		);

		$container->register(
			'product.taxonomy_registry',
			function () {
				return new TaxonomyRegistry();
			} 
		);
	}

	/**
	 * Boot the product service provider.
	 *
	 * Resolves circular dependencies via setter injection:
	 * - VariationResolver needs AttributeResolver to re-resolve from parent product.
	 * - AttributeMappingResolver needs AttributeResolver to resolve source attributes.
	 * - DynamicAttributeResolver needs AttributeResolver to resolve condition/output attributes.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-14.2
	 *
	 * @param Container $container The DI container instance.
	 *
	 * @return void
	 */
	public function boot( Container $container ): void {
		$attribute_resolver = $container->resolve( 'product.attribute_resolver' );

		// Break circular dependency: VariationResolver ↔ AttributeResolver.
		$variation_resolver = $container->resolve( 'product.variation_resolver' );
		$variation_resolver->set_attribute_resolver( $attribute_resolver );

		// Break circular dependency: AttributeMappingResolver ↔ AttributeResolver.
		$attr_mapping_resolver = $container->resolve( 'product.attr_mapping_resolver' );
		$attr_mapping_resolver->set_attribute_resolver( $attribute_resolver );

		// Break circular dependency: DynamicAttributeResolver ↔ AttributeResolver.
		$dynamic_attr_resolver = $container->resolve( 'product.dynamic_attr_resolver' );
		$dynamic_attr_resolver->set_attribute_resolver( $attribute_resolver );

		// Force the tax location to the feed's target country during feed
		// generation, so "Price with Tax" attributes reflect the destination
		// market's tax (and still compute on a cron run with no shopper).
		// No rate for that country → price without tax. Inert outside feeds.
		( new FeedTaxLocation() )->register();
	}
}
