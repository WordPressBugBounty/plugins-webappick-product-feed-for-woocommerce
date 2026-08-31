<?php
/**
 * ApiServiceProvider — Registers REST API endpoint services and routes.
 *
 * Binds 7 endpoint singletons to the DI container during register(),
 * then hooks `rest_api_init` in boot() to call register_routes() on
 * each endpoint and fires the `ctxfeed_rest_routes_registered` action
 * for Pro/third-party extensibility.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 * @implements API-FRD-6.1, API-FRD-6.2
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Core\Container;
use CTXFeed\V8\Core\ServiceProvider;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API service provider.
 *
 * Registers REST endpoint services and wires route registration
 * to the WordPress `rest_api_init` hook.
 *
 * @since 8.0.0
 */
class ApiServiceProvider extends ServiceProvider {

	/**
	 * Register endpoint singletons in the container.
	 *
	 * Phase 1: Only bind factories — no resolution.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-6.1
	 *
	 * @param Container $container DI container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->register(
			'api.feed',
			function () {
				return new FeedEndpoint();
			} 
		);

		$container->register(
			'api.product',
			function () {
				return new ProductEndpoint();
			} 
		);

		$container->register(
			'api.channel',
			function () {
				return new ChannelEndpoint();
			} 
		);

		$container->register(
			'api.settings',
			function () {
				return new SettingsEndpoint();
			} 
		);

		$container->register(
			'api.health',
			function () {
				return new HealthEndpoint();
			} 
		);

		$container->register(
			'api.migration',
			function () {
				return new MigrationEndpoint();
			} 
		);

		$container->register(
			'api.category_mapping',
			function () {
				return new CategoryMappingEndpoint();
			} 
		);

		$container->register(
			'api.wp_options',
			function () {
				return new WpOptionsEndpoint();
			} 
		);

		$container->register(
			'api.status',
			function () {
				return new StatusEndpoint();
			} 
		);

		$container->register(
			'api.cache',
			function () {
				return new CacheEndpoint();
			} 
		);

		$container->register(
			'api.support',
			function () {
				return new SupportEndpoint();
			} 
		);

		$container->register(
			'api.version',
			function () {
				return new VersionEndpoint();
			} 
		);

		$container->register(
			'api.ftp_test',
			function () {
				return new FtpTestEndpoint();
			} 
		);

		$container->register(
			'api.setup',
			function () {
				return new SetupEndpoint();
			}
		);

		$container->register(
			'api.our_plugins',
			function () {
				return new OurPluginsEndpoint();
			}
		);
	}

	/**
	 * Boot the API module — register routes via rest_api_init.
	 *
	 * Phase 3: Resolve endpoints and wire route registration to
	 * the WordPress `rest_api_init` hook. Fires the
	 * `ctxfeed_rest_routes_registered` action for Pro/third-party extensions.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-6.2
	 * @hook ctxfeed_rest_routes_registered
	 *
	 * @param Container $container DI container.
	 * @return void
	 */
	public function boot( Container $container ): void {
		$feed             = $container->resolve( 'api.feed' );
		$product          = $container->resolve( 'api.product' );
		$channel          = $container->resolve( 'api.channel' );
		$settings         = $container->resolve( 'api.settings' );
		$health           = $container->resolve( 'api.health' );
		$migration        = $container->resolve( 'api.migration' );
		$category_mapping = $container->resolve( 'api.category_mapping' );
		$wp_options       = $container->resolve( 'api.wp_options' );
		$status           = $container->resolve( 'api.status' );
		$cache            = $container->resolve( 'api.cache' );
		$support          = $container->resolve( 'api.support' );
		$version          = $container->resolve( 'api.version' );
		$ftp_test         = $container->resolve( 'api.ftp_test' );
		$setup            = $container->resolve( 'api.setup' );
		$our_plugins      = $container->resolve( 'api.our_plugins' );

		$endpoints = array(
			'feed'             => $feed,
			'product'          => $product,
			'channel'          => $channel,
			'settings'         => $settings,
			'health'           => $health,
			'migration'        => $migration,
			'category_mapping' => $category_mapping,
			'wp_options'       => $wp_options,
			'status'           => $status,
			'cache'            => $cache,
			'support'          => $support,
			'version'          => $version,
			'ftp_test'         => $ftp_test,
			'setup'            => $setup,
			'our_plugins'      => $our_plugins,
		);

		add_action(
			'rest_api_init',
			function () use ( $endpoints ) {
				foreach ( $endpoints as $endpoint ) {
					$endpoint->register_routes();
				}

				/**
				 * Fires after all V8 REST routes have been registered.
				 *
				 * @since 8.0.0
				 *
				 * @param array $endpoints Keyed array of endpoint instances.
				 */
				do_action( 'ctxfeed_rest_routes_registered', $endpoints );
			} 
		);

		// Cache invalidation for the filter-counts transient —
		// refreshed whenever a product is saved, deleted, or its
		// stock status changes. These hooks must register on every
		// request (not just rest_api_init) so wp-admin edits
		// properly invalidate the cache. Skipped during a bulk import
		// (WP_IMPORTING): a large import fires this on every one of
		// thousands of products, and each call is a wp_options DELETE for
		// a transient nobody is reading mid-import — the recompute on the
		// next Filters-tab open (or the TTL) picks up the final state.
		$invalidate_filter_counts = static function () {
			if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
				return;
			}
			delete_transient( 'ctxfeed_v8_filter_counts' );
		};
		add_action( 'save_post_product', $invalidate_filter_counts );
		add_action( 'deleted_post', $invalidate_filter_counts );

		// Cache invalidation for the category-mapping categories list.
		// A user adding or editing a product_cat term in wp-admin does
		// NOT hit our REST route, so registering the hooks here (on
		// every request) is required — otherwise the transient stays
		// stale for up to 12h and the mapping UI shows a wrong list.
		CategoryMappingEndpoint::register_cache_invalidation_hooks();
		add_action( 'trashed_post', $invalidate_filter_counts );
		add_action( 'woocommerce_product_set_stock_status', $invalidate_filter_counts );
		add_action( 'woocommerce_variation_set_stock_status', $invalidate_filter_counts );

		// Cache invalidation for the mapping-attribute dropdown list. A newly
		// added custom attribute / custom field / global attribute should show
		// up in the Make-Feed value picker immediately, not after the 5-min
		// TTL — the frustration being a merchant who edits a product, generates
		// a feed to check channel requirements, and doesn't see the new field.
		// The delete is O(1); the (expensive) rebuild is LAZY — only on the
		// next dropdown fetch, so a save never pays for it. Skipped during a
		// bulk import (WP_IMPORTING): thousands of saves would each drop it for
		// nothing, and the 5-min TTL catches up once the import finishes.
		// Stock-status changes are deliberately NOT wired here — they don't
		// change the SET of available attributes.
		$invalidate_attribute_dropdown = static function () {
			if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
				return;
			}
			ProductEndpoint::flush_mapping_attributes_cache();
		};
		add_action( 'save_post_product', $invalidate_attribute_dropdown );
		add_action( 'woocommerce_update_product', $invalidate_attribute_dropdown );
		add_action( 'woocommerce_new_product', $invalidate_attribute_dropdown );
		add_action( 'woocommerce_attribute_added', $invalidate_attribute_dropdown );
		add_action( 'woocommerce_attribute_updated', $invalidate_attribute_dropdown );
		add_action( 'woocommerce_attribute_deleted', $invalidate_attribute_dropdown );

		// Attribute Mapping / Dynamic Attribute / Category Mapping (matched by
		// their AttributeRegistry option-name prefixes) AND the exposed-WP-options
		// list (the single `wpfp_option` key) are all selectable value sources in
		// the Make-Feed dropdown, written straight to wp_options — never through a
		// product save, so none of the product hooks above catch them. Saving,
		// editing or deleting one must drop the same cache or the new entry is
		// invisible in the value picker until the 5-min TTL lapses. One handler;
		// added/updated/deleted all pass the option name first. Reuses the
		// WP_IMPORTING-guarded closure above.
		$invalidate_dropdown_on_mapping_option = static function ( $option ) use ( $invalidate_attribute_dropdown ) {
			$option = (string) $option;
			// The exposed-options list is one fixed key, not a prefix.
			if ( WpOptionsEndpoint::STORAGE_KEY === $option ) {
				$invalidate_attribute_dropdown();
				return;
			}
			$prefixes = array(
				\CTXFeed\V8\Product\AttributeRegistry::PREFIX_ATTR_MAPPING,  // wp_attr_mapping_ prefix.
				\CTXFeed\V8\Product\AttributeRegistry::PREFIX_DYN_ATTRIBUTE, // wf_dattribute_ prefix.
				\CTXFeed\V8\Product\AttributeRegistry::PREFIX_CAT_MAPPING,   // wf_cmapping_ prefix.
			);
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $option, $prefix ) ) {
					$invalidate_attribute_dropdown();
					return;
				}
			}
		};
		add_action( 'added_option', $invalidate_dropdown_on_mapping_option, 10, 1 );
		add_action( 'updated_option', $invalidate_dropdown_on_mapping_option, 10, 1 );
		add_action( 'deleted_option', $invalidate_dropdown_on_mapping_option, 10, 1 );

		// ACF field-structure changes add/remove the "ACF Fields" dropdown group.
		// They happen in ACF's field-group editor (post types acf-field-group /
		// acf-field), not on a product save, so hook ACF's own hooks. Harmless
		// no-ops when ACF is not installed (they never fire).
		//
		// CRITICAL: several of these are ACF *filters*, not actions —
		// `acf/update_field` and `acf/update_field_group` are applied via
		// apply_filters() and the field / field-group they carry MUST be returned,
		// or ACF stores null and the field never saves (a new field cannot be
		// created while CTX Feed is active). So the callback flushes the cache and
		// then returns its first argument verbatim: correct for the filters, and
		// harmless for the delete/trash action variants (which ignore the return).
		$invalidate_attribute_dropdown_acf = static function ( $value = null ) use ( $invalidate_attribute_dropdown ) {
			$invalidate_attribute_dropdown();
			return $value;
		};
		foreach ( array(
			'acf/update_field_group',
			'acf/delete_field_group',
			'acf/trash_field_group',
			'acf/untrash_field_group',
			'acf/update_field',
			'acf/delete_field',
		) as $acf_hook ) {
			add_filter( $acf_hook, $invalidate_attribute_dropdown_acf );
		}

		// Product removed → an attribute only that product carried may be gone.
		// Gated to the product post type so unrelated post deletions (blog
		// posts, pages) don't needlessly churn the cache.
		$invalidate_attribute_dropdown_on_product_delete = static function ( $post_id ) use ( $invalidate_attribute_dropdown ) {
			if ( 'product' === get_post_type( $post_id ) ) {
				$invalidate_attribute_dropdown();
			}
		};
		add_action( 'deleted_post', $invalidate_attribute_dropdown_on_product_delete );
		add_action( 'trashed_post', $invalidate_attribute_dropdown_on_product_delete );
	}
}
