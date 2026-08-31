<?php
/**
 * ProductEndpoint — REST API for product data preview and attribute listing.
 *
 * Returns resolved product attributes for feed preview, debugging,
 * and the grouped attribute list used by the admin UI for channel mapping.
 *
 * Delegates attribute catalog to AttributeRegistry and taxonomy catalog
 * to TaxonomyRegistry — keeping the endpoint thin (routing + response only).
 *
 * Endpoints:
 *   GET /ctxfeed/v8/products/preview              — Paginated product list with core attributes.
 *   GET /ctxfeed/v8/products/{id}/attributes       — Full attribute dump for a single product.
 *   GET /ctxfeed/v8/products/attributes            — Grouped attribute list for channel mapping dropdown.
 *   GET /ctxfeed/v8/products/search                — Search products by title.
 *   GET /ctxfeed/v8/products/ids                   — List all product IDs.
 *   GET /ctxfeed/v8/products/taxonomies            — Product taxonomies with terms.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 * @implements API-FRD-4.1
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\Container;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product REST endpoint.
 *
 * @since 8.0.0
 */
class ProductEndpoint extends RestController {

	/**
	 * Product-count ceiling for the Filters-tab warning badges.
	 *
	 * Above this many products, {@see get_filter_counts()} stops computing
	 * the per-filter warning counts. Those counts are several unbounded
	 * aggregate queries (including an unindexable `post_content` scan), and on
	 * a large catalog the cost of an advisory badge isn't justified — a store
	 * this size is past needing the hand-holding (owner decision, 2026-08-03).
	 *
	 * @since 8.0.0
	 * @var int
	 */
	public const FILTER_COUNTS_MAX_PRODUCTS = 5000;

	/**
	 * Transient key prefix for the grouped mapping-attribute dropdown list.
	 * The full key appends the context (see MAPPING_ATTR_CONTEXTS).
	 *
	 * @since 8.0.0
	 * @var string
	 */
	public const MAPPING_ATTR_CACHE_PREFIX = 'ctxfeed_v8_mapping_attributes_';

	/**
	 * The contexts the mapping-attribute list is cached under — the route
	 * validates `context` to exactly this set, so these are the only keys.
	 * Used to invalidate every cached variant at once.
	 *
	 * @since 8.0.0
	 * @var string[]
	 */
	public const MAPPING_ATTR_CONTEXTS = array( 'full', 'basic' );

	/**
	 * Default attributes to resolve in preview.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private const PREVIEW_ATTRIBUTES = array(
		'id'                => array(
			'type'    => 'attribute',
			'wc_attr' => 'id',
		),
		'title'             => array(
			'type'    => 'attribute',
			'wc_attr' => 'title',
		),
		'description'       => array(
			'type'    => 'attribute',
			'wc_attr' => 'description',
		),
		'short_description' => array(
			'type'    => 'attribute',
			'wc_attr' => 'short_description',
		),
		'link'              => array(
			'type'    => 'attribute',
			'wc_attr' => 'link',
		),
		'image_link'        => array(
			'type'    => 'image',
			'wc_attr' => 'featured_image',
		),
		'price'             => array(
			'type'    => 'price',
			'wc_attr' => 'price',
		),
		'regular_price'     => array(
			'type'    => 'price',
			'wc_attr' => 'regular_price',
		),
		'sale_price'        => array(
			'type'    => 'price',
			'wc_attr' => 'sale_price',
		),
		'availability'      => array(
			'type'    => 'attribute',
			'wc_attr' => 'availability',
		),
		'sku'               => array(
			'type'    => 'attribute',
			'wc_attr' => 'sku',
		),
		'product_type'      => array(
			'type'    => 'attribute',
			'wc_attr' => 'type',
		),
		'weight'            => array(
			'type'    => 'attribute',
			'wc_attr' => 'weight',
		),
		'categories'        => array(
			'type'    => 'taxonomy',
			'wc_attr' => 'product_cat',
		),
	);

	/**
	 * Register product routes.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-4.1
	 *
	 * @return void
	 */
	public function register_routes(): void {

		// Paginated product preview.
		register_rest_route(
			$this->namespace,
			'/products/preview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preview_products' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'per_page' => array(
						'default'           => 10,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return $value >= 1 && $value <= 200;
						},
					),
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return $value >= 1;
						},
					),
				),
			) 
		);

		// Single product attribute dump.
		register_rest_route(
			$this->namespace,
			'/products/(?P<id>\d+)/attributes',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_product_attributes' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			) 
		);

		// Grouped attribute list for channel mapping dropdown.
		// V8 equivalent of V5's /ctxfeed/v1/drop_down?type=product_attributes.
		register_rest_route(
			$this->namespace,
			'/products/attributes',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_mapping_attributes' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'context' => array(
						'default'           => 'full',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return in_array( $value, array( 'full', 'basic' ), true );
						},
					),
				),
			) 
		);

		// Search products by title.
		register_rest_route(
			$this->namespace,
			'/products/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_products' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'search'      => array(
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search_type' => array(
						'default'           => 'smart',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							// 'smart' searches title + SKU + ID in one go.
							// 'paste_ids' accepts a comma-separated list of IDs.
							// Legacy values accepted for backward compat.
							return in_array( $value, array( 'smart', 'paste_ids', 'title', 'id', 'sku' ), true );
						},
					),
					'include'     => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Comma-separated product IDs to hydrate. Used on edit-mode load.', 'woo-feed' ),
					),
					'per_page'    => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			) 
		);

		// List all product IDs.
		register_rest_route(
			$this->namespace,
			'/products/ids',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_product_ids' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// Product counts per filter type — powers the filter-warning icons.
		register_rest_route(
			$this->namespace,
			'/products/filter-counts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_filter_counts' ),
				'permission_callback' => array( $this, 'permission_check' ),
			) 
		);

		// Product taxonomies — list WooCommerce product taxonomies with terms.
		register_rest_route(
			$this->namespace,
			'/products/taxonomies',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_product_taxonomies' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'taxonomy'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Specific taxonomy slug to retrieve (e.g., product_cat). Empty returns all.', 'woo-feed' ),
					),
					'hide_empty' => array(
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			) 
		);
	}

	/**
	 * Preview resolved product data.
	 *
	 * Returns a paginated list of products with core attributes resolved
	 * via the full V8 resolver chain (CacheWarmer → AttributeResolver → filters).
	 *
	 * @since 8.0.0
	 * @implements API-FRD-4.1
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function preview_products( \WP_REST_Request $request ): \WP_REST_Response {
		$container = Container::get_instance();

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Single-line @var type hint for static analysis.
		/** @var \CTXFeed\V8\Product\ProductQuery $query */
		$query = $container->resolve( 'product.query' );

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Single-line @var type hint for static analysis.
		/** @var \CTXFeed\V8\Product\ProductRepository $repository */
		$repository = $container->resolve( 'product.repository' );

		// Build a minimal Config for preview (no feed-specific settings).
		$config = new Config(
			array(
				'product_types' => array( 'simple', 'variable', 'grouped', 'external' ),
				'is_variations' => 'n',
			) 
		);

		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$offset   = ( $page - 1 ) * $per_page;

		// Get paginated product IDs.
		$ids   = $query->get_paginated_ids( $config, $offset, $per_page );
		$total = $query->get_total_count( $config );

		if ( empty( $ids ) ) {
			return $this->success(
				array(
					'products' => array(),
					'total'    => $total,
					'page'     => $page,
					'per_page' => $per_page,
				) 
			);
		}

		// Resolve attributes for this batch via full V8 pipeline.
		// Implements PROD-FRD-2.1: CacheWarmer → AttributeResolver → dual-filter chain.
		$dtos     = $repository->get_products( $ids, self::PREVIEW_ATTRIBUTES, $config );
		$products = array();

		foreach ( $dtos as $dto ) {
			$products[] = array(
				'id'         => $dto->id,
				'type'       => $dto->type,
				'attributes' => $dto->to_array(),
			);
		}

		return $this->success(
			array(
				'products' => $products,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'pages'    => (int) ceil( $total / $per_page ),
			) 
		);
	}

	/**
	 * Get all resolved attributes for a single product.
	 *
	 * Returns every attribute the resolver chain can extract,
	 * useful for debugging and feed mapping preview.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-4.1
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_product_attributes( \WP_REST_Request $request ): \WP_REST_Response {
		$product_id = (int) $request->get_param( 'id' );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return $this->error( "Product #{$product_id} not found.", 404 );
		}

		$container = Container::get_instance();

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Single-line @var type hint for static analysis.
		/** @var \CTXFeed\V8\Product\ProductRepository $repository */
		$repository = $container->resolve( 'product.repository' );

		// Comprehensive attribute structure for full product inspection.
		$structure = array_merge(
			self::PREVIEW_ATTRIBUTES,
			array(
				'slug'           => array(
					'type'    => 'attribute',
					'wc_attr' => 'slug',
				),
				'stock_quantity' => array(
					'type'    => 'attribute',
					'wc_attr' => 'stock_quantity',
				),
				'stock_status'   => array(
					'type'    => 'attribute',
					'wc_attr' => 'stock_status',
				),
				'length'         => array(
					'type'    => 'attribute',
					'wc_attr' => 'length',
				),
				'width'          => array(
					'type'    => 'attribute',
					'wc_attr' => 'width',
				),
				'height'         => array(
					'type'    => 'attribute',
					'wc_attr' => 'height',
				),
				'date_created'   => array(
					'type'    => 'attribute',
					'wc_attr' => 'date_created',
				),
				'tags'           => array(
					'type'    => 'taxonomy',
					'wc_attr' => 'product_tag',
				),
			) 
		);

		$config = new Config( array() );
		$dtos   = $repository->get_products( array( $product_id ), $structure, $config );

		if ( empty( $dtos ) ) {
			return $this->error( "Product #{$product_id} could not be resolved.", 422 );
		}

		$dto = $dtos[0];

		return $this->success(
			array(
				'id'         => $dto->id,
				'type'       => $dto->type,
				'attributes' => $dto->to_array(),
			) 
		);
	}

	/**
	 * Get all available product attributes grouped by category for channel mapping.
	 *
	 * Delegates to AttributeRegistry::get_for_dropdown() for the full attribute
	 * catalog, then applies V8 + V5 legacy filters for backward compatibility.
	 *
	 * @since 8.0.0
	 * @implements API-FRD-4.1
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_mapping_attributes( \WP_REST_Request $request ): \WP_REST_Response {
		$context = $request->get_param( 'context' );

		// Use transient cache for the full attribute list (expensive queries for dynamic groups).
		$cache_key  = self::MAPPING_ATTR_CACHE_PREFIX . $context;
		$attributes = get_transient( $cache_key );

		if ( false === $attributes ) {
			$container  = Container::get_instance();
			$registry   = $container->resolve( 'product.attribute_registry' );
			$attributes = $registry->get_for_dropdown( $context );
			// BUG-0062: single, user-configurable cache lifetime. Freshness is
			// guaranteed by the invalidation hooks (ApiServiceProvider) that flush
			// this on any product/attribute change, so the TTL is a safety net.
			set_transient( $cache_key, $attributes, SettingsEndpoint::get_cache_ttl() );
		}

		/**
		 * Filter the attribute list for channel mapping dropdowns.
		 *
		 * V8 equivalent of V5's `woo_feed_product_attribute_dropdown` filter.
		 * Pro plugin and third-party extensions can add custom attribute groups here.
		 *
		 * @since 8.0.0
		 *
		 * @param array  $attributes Grouped attribute list.
		 * @param string $context    'full' or 'basic'.
		 */
		$attributes = apply_filters( 'ctxfeed_product_attribute_dropdown', $attributes, $context );

		// Also fire V5 legacy filter for backward compatibility with existing extensions.
		$attributes = apply_filters( 'woo_feed_product_attribute_dropdown', $attributes );

		return $this->success( $attributes );
	}

	/**
	 * Delete the cached mapping-attribute dropdown list (every context).
	 *
	 * Called from the cache-invalidation hooks (see ApiServiceProvider) when
	 * the set of available product attributes could have changed — a product
	 * saved, a custom field added, or a global attribute created/edited/
	 * removed — so the mapping UI reflects it without waiting out the 5-minute
	 * TTL. Cheap (one delete per context); the expensive rebuild is lazy, on
	 * the next dropdown fetch only.
	 *
	 * @since 8.0.0
	 * @return void
	 */
	public static function flush_mapping_attributes_cache(): void {
		foreach ( self::MAPPING_ATTR_CONTEXTS as $context ) {
			delete_transient( self::MAPPING_ATTR_CACHE_PREFIX . $context );
		}
	}

	/**
	 * Search products by title.
	 *
	 * V8 equivalent of V5's `/ctxfeed/v1/products?search=`.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function search_products( \WP_REST_Request $request ): \WP_REST_Response {
		$search      = (string) $request->get_param( 'search' );
		$search_type = (string) $request->get_param( 'search_type' );
		$include_raw = (string) $request->get_param( 'include' );
		$per_page    = (int) $request->get_param( 'per_page' );

		$limit = max( 1, min( $per_page, 200 ) );

		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// `include` param: hydrate selected IDs back to {id,title,sku} for
		// display when the feed loads in edit mode. Bypasses search_type.
		if ( '' !== $include_raw ) {
			$ids = array_values(
				array_filter(
					array_map( 'absint', explode( ',', $include_raw ) ),
					static function ( $i ) {
						return $i > 0;
					}
				) 
			);

			if ( empty( $ids ) ) {
				return $this->success(
					array(
						'products' => array(),
						'total'    => 0,
					) 
				);
			}

			$args['post__in'] = $ids;
			$args['orderby']  = 'post__in';
		} else {
			// Apply the search-type strategy.
			switch ( $search_type ) {
				case 'paste_ids':
					$ids = array_values(
						array_filter(
							array_map( 'absint', explode( ',', $search ) ),
							static function ( $i ) {
								return $i > 0;
							}
						) 
					);
					if ( empty( $ids ) ) {
						return $this->success(
							array(
								'products' => array(),
								'total'    => 0,
							) 
						);
					}
					$args['post__in'] = $ids;
					$args['orderby']  = 'post__in';
					break;

				// Legacy single-mode searches kept for any deep-linked UI state.
				case 'id':
					if ( '' === $search || ! ctype_digit( $search ) ) {
						return $this->success(
							array(
								'products' => array(),
								'total'    => 0,
							) 
						);
					}
					$args['post__in'] = array( (int) $search );
					break;

				case 'sku':
					if ( '' === $search ) {
						return $this->success(
							array(
								'products' => array(),
								'total'    => 0,
							) 
						);
					}
					$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- SKU search requires a meta lookup; the query is paginated and admin-only.
						array(
							'key'     => '_sku',
							'value'   => $search,
							'compare' => 'LIKE',
						),
					);
					break;

				case 'title':
					if ( '' !== $search ) {
						$args['s'] = $search;
					}
					break;

				case 'smart':
				default:
					// Default single-input search: matches title OR SKU OR ID.
					// Runs up to 3 quick WP_Queries, merges + dedupes results.
					if ( '' === $search ) {
						// No query → return nothing (let the user type).
						return $this->success(
							array(
								'products' => array(),
								'total'    => 0,
							) 
						);
					}

					$smart_ids = $this->smart_search_ids( $search, $limit, $args['post_status'] );

					if ( empty( $smart_ids ) ) {
						return $this->success(
							array(
								'products' => array(),
								'total'    => 0,
							) 
						);
					}

					$args['post__in'] = $smart_ids;
					$args['orderby']  = 'post__in';
					break;
			}
		}

		$query   = new \WP_Query( $args );
		$results = array();

		foreach ( $query->posts as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$results[] = array(
				'id'    => $product_id,
				'title' => $product->get_name(),
				'type'  => $product->get_type(),
				'sku'   => $product->get_sku(),
			);
		}

		return $this->success(
			array(
				'products' => $results,
				'total'    => $query->found_posts,
			) 
		);
	}

	/**
	 * List all product IDs.
	 *
	 * V8 equivalent of V5's `/ctxfeed/v1/products/ids`.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_product_ids( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the REST callback signature.
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_posts_per_page -- Returns the full ID list for the admin bulk-select UI; fields=>ids keeps the query light.
			'fields'         => 'ids',
		);

		$query = new \WP_Query( $args );

		return $this->success(
			array(
				'ids'   => $query->posts,
				'total' => $query->found_posts,
			) 
		);
	}

	/**
	 * Get product counts per filter type.
	 *
	 * Powers warning messages on the filter toggles like "5 products have
	 * no images". Returns a count for each V5-compatible filter key so the
	 * UI can display a warning only when count > 0.
	 *
	 * Cached for 1 hour via transient to avoid repeating 6 unbounded
	 * WP_Queries on every page load. Cache key includes the latest product
	 * modification time so newly-added or edited products refresh counts.
	 *
	 * Supports ?refresh=1 to force a fresh query.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_filter_counts( \WP_REST_Request $request ): \WP_REST_Response {
		$transient_key = 'ctxfeed_v8_filter_counts';
		$force_refresh = (bool) $request->get_param( 'refresh' );

		if ( $force_refresh ) {
			delete_transient( $transient_key );
		} else {
			$cached = get_transient( $transient_key );
			if ( is_array( $cached ) ) {
				return $this->success( $cached );
			}
		}

		// Large catalogs: skip the per-filter warning counts entirely. Each
		// count is an unbounded aggregate query (one is a post_content scan),
		// so on a big store they are the Filters tab's main cost. Above the
		// threshold we return `disabled` and no per-filter counts — the UI
		// then shows the static descriptions with no warning badges.
		if ( $this->total_filterable_products() > self::FILTER_COUNTS_MAX_PRODUCTS ) {
			$payload = array(
				'disabled'  => true,
				'threshold' => self::FILTER_COUNTS_MAX_PRODUCTS,
			);
			set_transient( $transient_key, $payload, HOUR_IN_SECONDS );
			return $this->success( $payload );
		}

		$counts = array(
			'disabled'            => false,
			'product_visibility'  => $this->count_hidden_products(),
			'is_emptyPrice'       => $this->count_empty_price_products(),
			'is_emptyImage'       => $this->count_empty_image_products(),
			'is_emptyDescription' => $this->count_empty_description_products(),
			'is_outOfStock'       => $this->count_out_of_stock_products(),
			'is_backorder'        => $this->count_backorder_products(),
		);

		// Short cache — 5 minutes is enough to cover concurrent page loads
		// without serving badly stale data.
		set_transient( $transient_key, $counts, 5 * MINUTE_IN_SECONDS );

		return $this->success( $counts );
	}

	/**
	 * Total product count across the statuses the filter counts consider.
	 *
	 * Uses wp_count_posts() (a single grouped COUNT, cached by core) rather
	 * than running the per-filter aggregates — this is the cheap pre-check
	 * that decides whether the Filters-tab warnings are computed at all.
	 *
	 * @since 8.0.0
	 *
	 * @return int
	 */
	private function total_filterable_products(): int {
		$counts = wp_count_posts( 'product' );
		if ( ! is_object( $counts ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $this->filter_count_statuses() as $status ) {
			if ( isset( $counts->{$status} ) ) {
				$total += (int) $counts->{$status};
			}
		}

		return $total;
	}

	/**
	 * Get the list of product post statuses to include in filter counts.
	 *
	 * Includes published, draft, pending, and private products — matches
	 * the statuses that could legitimately end up in a feed.
	 *
	 * @since 8.0.0
	 *
	 * @return string[]
	 */
	private function filter_count_statuses(): array {
		return array( 'publish', 'draft', 'pending', 'private' );
	}

	/**
	 * Smart product ID search — matches title OR SKU OR exact ID.
	 *
	 * Runs up to three fast WP_Queries and merges + dedupes the results.
	 * Preserves insertion order so exact-ID matches surface first, then
	 * title matches, then SKU matches.
	 *
	 * @since 8.0.0
	 *
	 * @param string   $search     Search term.
	 * @param int      $limit      Max IDs to return.
	 * @param string[] $statuses   Post statuses to include.
	 *
	 * @return int[] Merged, deduped list of product IDs.
	 */
	private function smart_search_ids( string $search, int $limit, array $statuses ): array {
		$collected = array();

		// 1. Exact ID match (only if the search is pure digits).
		if ( ctype_digit( $search ) ) {
			$id = (int) $search;
			if ( $id > 0 ) {
				$post = get_post( $id );
				if ( $post && 'product' === $post->post_type && in_array( $post->post_status, $statuses, true ) ) {
					$collected[ $id ] = $id;
				}
			}
		}

		// 2. Title search.
		$title_q = new \WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => $statuses,
				's'              => $search,
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			) 
		);
		foreach ( $title_q->posts as $id ) {
			$collected[ $id ] = $id;
			if ( count( $collected ) >= $limit ) {
				break;
			}
		}

		// 3. SKU search (only if we still have room and search isn't empty).
		if ( count( $collected ) < $limit ) {
			$sku_q = new \WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => $statuses,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- SKU search requires a meta lookup; capped by $limit with no_found_rows.
						array(
							'key'     => '_sku',
							'value'   => $search,
							'compare' => 'LIKE',
						),
					),
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				) 
			);
			foreach ( $sku_q->posts as $id ) {
				$collected[ $id ] = $id;
				if ( count( $collected ) >= $limit ) {
					break;
				}
			}
		}

		return array_values( $collected );
	}

	/**
	 * Count products hidden from the catalog (exclude-from-catalog tax term).
	 *
	 * @since 8.0.0
	 *
	 * @return int
	 */
	private function count_hidden_products(): int {
		global $wpdb;

		$statuses = $this->filter_count_statuses();
		$in       = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate COUNT diagnostic; $in is a fixed post-status list escaped with esc_sql(), and results are cheap enough not to cache.
		$count = $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			 WHERE p.post_type = 'product'
			   AND p.post_status IN ( {$in} )
			   AND tt.taxonomy = 'product_visibility'
			   AND t.name = 'exclude-from-catalog'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Count products without featured image AND without gallery image.
	 *
	 * @since 8.0.0
	 *
	 * @return int
	 */
	private function count_empty_image_products(): int {
		global $wpdb;

		$statuses = $this->filter_count_statuses();
		$in       = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate COUNT diagnostic; $in is a fixed post-status list escaped with esc_sql(), and results are cheap enough not to cache.
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product'
			   AND p.post_status IN ( {$in} )
			   AND NOT EXISTS (
				 SELECT 1 FROM {$wpdb->postmeta} pm
				 WHERE pm.post_id = p.ID
				   AND pm.meta_key = '_thumbnail_id'
				   AND pm.meta_value != ''
				   AND pm.meta_value != '0'
			   )
			   AND NOT EXISTS (
				 SELECT 1 FROM {$wpdb->postmeta} pm2
				 WHERE pm2.post_id = p.ID
				   AND pm2.meta_key = '_product_image_gallery'
				   AND pm2.meta_value != ''
			   )"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Count products with empty post_content (description).
	 *
	 * @since 8.0.0
	 *
	 * @return int
	 */
	private function count_empty_description_products(): int {
		global $wpdb;

		$statuses = $this->filter_count_statuses();
		$in       = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate COUNT diagnostic; $in is a fixed post-status list escaped with esc_sql(), and results are cheap enough not to cache.
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'product'
			   AND post_status IN ( {$in} )
			   AND TRIM( COALESCE( post_content, '' ) ) = ''"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Count products with stock_status = outofstock.
	 *
	 * @since 8.0.0
	 *
	 * @return int
	 */
	private function count_out_of_stock_products(): int {
		return $this->count_products_by_stock_meta( 'outofstock' );
	}

	/**
	 * Count products with stock_status = onbackorder.
	 *
	 * @since 8.0.0
	 *
	 * @return int
	 */
	private function count_backorder_products(): int {
		return $this->count_products_by_stock_meta( 'onbackorder' );
	}

	/**
	 * Count products whose `_stock_status` meta matches a value.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Target stock status value.
	 * @return int
	 */
	private function count_products_by_stock_meta( string $value ): int {
		global $wpdb;

		$statuses = $this->filter_count_statuses();
		$in       = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate COUNT diagnostic; $in is a fixed post-status list escaped with esc_sql(), and results are cheap enough not to cache.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type = 'product'
				   AND p.post_status IN ( {$in} )
				   AND pm.meta_key = '_stock_status'
				   AND pm.meta_value = %s",
				$value
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Count products with empty _price meta.
	 *
	 * @since 8.0.0
	 *
	 * @return int
	 */
	private function count_empty_price_products(): int {
		global $wpdb;

		$statuses = $this->filter_count_statuses();
		$in       = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate COUNT diagnostic; $in is a fixed post-status list escaped with esc_sql(), and results are cheap enough not to cache.
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product'
			   AND p.post_status IN ( {$in} )
			   AND NOT EXISTS (
				 SELECT 1 FROM {$wpdb->postmeta} pm
				 WHERE pm.post_id = p.ID
				   AND pm.meta_key = '_price'
				   AND pm.meta_value != ''
				   AND pm.meta_value IS NOT NULL
			   )"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Get WooCommerce product taxonomies with their terms.
	 *
	 * Delegates to TaxonomyRegistry for all taxonomy discovery and term
	 * resolution. Returns all taxonomies registered for the 'product' post
	 * type (product_cat, product_tag, custom taxonomies, pa_* global attributes)
	 * along with their terms.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_product_taxonomies( \WP_REST_Request $request ): \WP_REST_Response {
		$container         = Container::get_instance();
		$registry          = $container->resolve( 'product.taxonomy_registry' );
		$specific_taxonomy = $request->get_param( 'taxonomy' );
		$hide_empty        = $request->get_param( 'hide_empty' );

		// If a specific taxonomy is requested, return just that one.
		if ( ! empty( $specific_taxonomy ) ) {
			$data = $registry->get_taxonomy( $specific_taxonomy, $hide_empty );

			if ( null === $data ) {
				return $this->error(
					sprintf(
						/* translators: %s: taxonomy slug */
						__( 'Taxonomy "%s" does not exist.', 'woo-feed' ),
						$specific_taxonomy
					),
					404
				);
			}

			return $this->success(
				array(
					'taxonomies' => array( $data ),
				) 
			);
		}

		// Get all product taxonomies with terms.
		$result = $registry->get_all_with_terms( $hide_empty );

		return $this->success(
			array(
				'taxonomies' => $result,
				'total'      => count( $result ),
			) 
		);
	}
}
