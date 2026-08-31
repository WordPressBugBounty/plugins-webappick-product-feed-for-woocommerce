<?php
/**
 * CategoryMappingEndpoint — REST API for category mapping CRUD.
 *
 * Reads/writes category mappings from wp_options (wf_cmapping_*).
 * Backward-compatible with the V5 storage format while exposing
 * a clean API to the V8 React frontend.
 *
 * V5 stores serialized arrays:
 *   mappingname     — Display name.
 *   mappingprovider — Template/merchant key (e.g. "google", "facebook").
 *   cmapping        — [ category_id => category_value, ... ]
 *   gcl-cmapping    — Google/Facebook-specific category mappings.
 *
 * V8 frontend expects:
 *   name     — Display name.
 *   template — Merchant key.
 *   mappings — { category_id: category_value, ... }
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.0
 */

namespace CTXFeed\V8\API;

use CTXFeed\V8\Template\TemplateLocator;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category Mapping REST endpoint.
 *
 * @since 8.0.0
 */
class CategoryMappingEndpoint extends RestController {

	/**
	 * Option name prefix — matches V5 constant.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const PREFIX = 'wf_cmapping_';

	/**
	 * Transient key for the cached WooCommerce category list. Rebuilt
	 * from `get_terms('product_cat', hide_empty=false)` — cheap for
	 * a small catalog, but a full round-trip through get_terms +
	 * hierarchy building costs 200–400ms on shops with 200+ categories.
	 * That's every category-mapping modal open, every search keystroke
	 * (in older code), every pagination page — snowballs fast.
	 *
	 * TTL: 12 hours. Invalidated on `created_product_cat` /
	 * `edited_product_cat` / `delete_product_cat` (see register_cache_invalidation_hooks).
	 *
	 * @since 8.0.0
	 */
	// Versioned to force a rebuild whenever the shape of the cached
	// payload changes (e.g. the hierarchy-first sort added below).
	// Bump the suffix when the shape or order changes so old rows in
	// the options table don't get served.
	const CAT_CACHE_TRANSIENT = 'ctxfeed_cat_map_categories_v2';
	const CAT_CACHE_TTL       = 12 * HOUR_IN_SECONDS;

	/**
	 * Transient key prefix for the parsed merchant-taxonomy list.
	 *
	 * The parse_taxonomy_file() reader streams a multi-thousand-line .txt on
	 * every call (every mapping-modal open). The parsed result is stable until the file
	 * itself changes, so we cache it for a month, keyed by the file's basename
	 * (collision-free across google/facebook/generic taxonomy files, and the
	 * same key whether the bundled or the uploads-override copy is read).
	 * Busted explicitly by flush_google_taxonomy_cache() after a manual update.
	 *
	 * @since 8.0.0
	 */
	const TAXONOMY_PARSE_CACHE_PREFIX = 'ctxfeed_taxonomy_parsed_';

	/**
	 * Default page size for the paginated categories endpoint. Matches
	 * V5's `stringsDocs.categoryPaginationLength` so the two UIs behave
	 * identically for the customer.
	 *
	 * @since 8.0.0
	 */
	const CAT_DEFAULT_PER_PAGE = 100;
	const CAT_MAX_PER_PAGE     = 500;

	/**
	 * Register category mapping routes.
	 *
	 * @since 8.0.0
	 * @return void
	 */
	public function register_routes(): void {
		// List all / Create new.
		register_rest_route(
			$this->namespace,
			'/category-mapping',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_mappings' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_mapping' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			) 
		);

		// WooCommerce product categories (hierarchical, cached, paginated).
		// Query params:
		// page      — 1-based page number (default 1)
		// per_page  — items per page, capped at CAT_MAX_PER_PAGE (default 100)
		// search    — substring match on hierarchy string (case-insensitive)
		// Response shape: { items: [...], total, page, per_page }.
		register_rest_route(
			$this->namespace,
			'/category-mapping/categories',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_wc_categories' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => self::CAT_DEFAULT_PER_PAGE,
						'sanitize_callback' => 'absint',
					),
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			) 
		);

		// Template/merchant-specific categories.
		// Two modes:
		// ?template=X&search=Y  → autocomplete (case-insensitive substring), max 50 hits
		// ?template=X&ids=1,2,3 → direct lookup by ID (used to hydrate labels
		// for already-mapped values when the editor opens).
		register_rest_route(
			$this->namespace,
			'/category-mapping/template-categories',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_template_categories' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'template' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ids'      => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			) 
		);

		// Single mapping: Read / Update / Delete.
		register_rest_route(
			$this->namespace,
			'/category-mapping/(?P<name>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_mapping' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_mapping' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_mapping' ),
					'permission_callback' => array( $this, 'permission_check' ),
				),
			) 
		);
	}

	// =====================================================================
	// Format helpers — V5 ↔ V8 translation.
	// =====================================================================

	/**
	 * Detect V5 format (has 'mappingname' key).
	 *
	 * @since 8.0.0
	 *
	 * @param array $data Unserialized option data.
	 * @return bool True if V5 format.
	 */
	private function is_v5_format( array $data ): bool {
		return isset( $data['mappingname'] );
	}

	/**
	 * Convert V5 option data to V8 format.
	 *
	 * @since 8.0.0
	 *
	 * @param array  $data        V5 option data.
	 * @param string $option_name Full option name.
	 * @return array V8-formatted data.
	 */
	private function v5_to_v8( array $data, string $option_name ): array {
		$name     = $data['mappingname'] ?? '';
		$template = $data['mappingprovider'] ?? '';

		// V5 stores mappings as category_id => category_value.
		$cmapping     = isset( $data['cmapping'] ) ? (array) $data['cmapping'] : array();
		$gcl_cmapping = isset( $data['gcl-cmapping'] ) ? (array) $data['gcl-cmapping'] : array();

		// Merge both mapping arrays; gcl-cmapping takes priority if present.
		$mappings = array();
		foreach ( $cmapping as $cat_id => $cat_value ) {
			if ( ! empty( $cat_value ) ) {
				$mappings[ (string) $cat_id ] = $cat_value;
			}
		}
		foreach ( $gcl_cmapping as $cat_id => $cat_value ) {
			if ( ! empty( $cat_value ) ) {
				$mappings[ (string) $cat_id ] = $cat_value;
			}
		}

		return array(
			'name'        => $name,
			'template'    => $template,
			'mappings'    => $mappings,
			'option_name' => $option_name,
		);
	}

	/**
	 * Convert V8 data to V5-compatible storage format.
	 *
	 * @since 8.0.0
	 *
	 * @param string $name     Display name.
	 * @param string $template Template/merchant key.
	 * @param array  $mappings Category ID → value mappings.
	 * @return array V5-compatible option data.
	 */
	private function v8_to_v5( string $name, string $template, array $mappings ): array {
		$cmapping     = array();
		$gcl_cmapping = array();

		foreach ( $mappings as $cat_id => $cat_value ) {
			$cmapping[ $cat_id ]     = sanitize_text_field( $cat_value );
			$gcl_cmapping[ $cat_id ] = sanitize_text_field( $cat_value );
		}

		return array(
			'mappingname'     => sanitize_text_field( $name ),
			'mappingprovider' => sanitize_text_field( $template ),
			'cmapping'        => $cmapping,
			'gcl-cmapping'    => $gcl_cmapping,
		);
	}

	/**
	 * Deep-unserialize a value.
	 *
	 * V5 stores data via `update_option( $key, serialize( $array ) )`.
	 * WordPress's `maybe_serialize()` detects the serialized string
	 * and wraps it again, producing double-serialized values in the DB:
	 *   s:NNN:"a:4:{...}"
	 *
	 * A single `maybe_unserialize()` only peels one layer, leaving
	 * a serialized string. This helper keeps unserializing until
	 * we reach a non-string value (i.e. the actual array).
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $value Raw option_value from the DB.
	 * @return mixed Fully unserialized value.
	 */
	private function deep_unserialize( $value ) {
		$max_depth = 5; // Safety limit.
		$i         = 0;

		while ( is_string( $value ) && is_serialized( $value ) && $i < $max_depth ) {
			$value = maybe_unserialize( $value );
			++$i;
		}

		return $value;
	}

	/**
	 * Format a single option row for API response.
	 * Handles both V5 and V8 storage formats.
	 *
	 * @since 8.0.0
	 *
	 * @param array $row Database row with option_id, option_name, option_value.
	 * @return array|null Formatted mapping or null if invalid.
	 */
	private function format_mapping( array $row ): ?array {
		$data = $this->deep_unserialize( $row['option_value'] );

		if ( ! is_array( $data ) ) {
			return null;
		}

		$option_name = $row['option_name'];

		if ( $this->is_v5_format( $data ) ) {
			$v8_data = $this->v5_to_v8( $data, $option_name );
		} elseif ( isset( $data['name'] ) ) {
			// V8 native format.
			$v8_data = array(
				'name'        => $data['name'] ?? '',
				'template'    => $data['template'] ?? '',
				'mappings'    => $data['mappings'] ?? array(),
				'option_name' => $option_name,
			);
		} else {
			return null;
		}

		return array(
			'id'          => $row['option_id'],
			'option_name' => $option_name,
			'slug'        => str_replace( self::PREFIX, '', $option_name ),
			'data'        => $v8_data,
		);
	}

	// =====================================================================
	// CRUD endpoints.
	// =====================================================================

	/**
	 * List all category mappings.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_mappings( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $request is required by the register_rest_route() callback contract; this route lists every mapping and takes no parameters.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Category mappings are stored one per wp_options row under a shared prefix; get_option() cannot enumerate by prefix. Admin REST route only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC",
				self::PREFIX . '%'
			),
			ARRAY_A
		);

		$mappings = array();

		foreach ( $rows as $row ) {
			$formatted = $this->format_mapping( $row );
			if ( $formatted ) {
				$mappings[] = $formatted;
			}
		}

		return $this->success( $mappings );
	}

	/**
	 * Resolve the target option name from the URL route param `{name}`.
	 *
	 * The `/category-mapping/{name}` routes identify the mapping by the URL
	 * slug. Reading it with `get_param( 'name' )` is unsafe on the write
	 * routes: a JSON PUT body carries its own `name` field (the mapping's
	 * DISPLAY name), and in a real WP_REST_Request the JSON body shadows the
	 * URL param — so `update_mapping` would look up `wf_cmapping_<display
	 * name>` instead of the slug and return a false "Category mapping not
	 * found". Read the URL params explicitly, falling back to `get_param()`
	 * only when they are unavailable (e.g. a minimal test double).
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return string Full PREFIX-normalized option name, or '' when absent.
	 */
	private function resolve_route_option_name( \WP_REST_Request $request ): string {
		$name = '';

		if ( method_exists( $request, 'get_url_params' ) ) {
			$url_params = (array) $request->get_url_params();
			if ( isset( $url_params['name'] ) ) {
				$name = (string) $url_params['name'];
			}
		}

		if ( '' === $name ) {
			$name = (string) $request->get_param( 'name' );
		}

		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return '';
		}

		return 0 === strpos( $name, self::PREFIX ) ? $name : self::PREFIX . $name;
	}

	/**
	 * Get a single category mapping by name/slug.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_mapping( \WP_REST_Request $request ): \WP_REST_Response {
		$option_name = $this->resolve_route_option_name( $request );

		$raw = get_option( $option_name );

		if ( false === $raw ) {
			return $this->error( __( 'Category mapping not found.', 'woo-feed' ), 404 );
		}

		// Handle V5 double-serialized data.
		$data = $this->deep_unserialize( $raw );

		if ( ! is_array( $data ) ) {
			return $this->error( __( 'Category mapping data is invalid.', 'woo-feed' ), 500 );
		}

		if ( $this->is_v5_format( $data ) ) {
			$v8_data = $this->v5_to_v8( $data, $option_name );
		} else {
			$v8_data = array(
				'name'        => $data['name'] ?? '',
				'template'    => $data['template'] ?? '',
				'mappings'    => $data['mappings'] ?? array(),
				'option_name' => $option_name,
			);
		}

		return $this->success(
			array(
				'option_name' => $option_name,
				'slug'        => str_replace( self::PREFIX, '', $option_name ),
				'data'        => $v8_data,
			) 
		);
	}

	/**
	 * Create a new category mapping.
	 * Saves in V5-compatible format for backward compatibility.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function create_mapping( \WP_REST_Request $request ): \WP_REST_Response {
		$name     = sanitize_text_field( $request->get_param( 'name' ) );
		$template = sanitize_text_field( $request->get_param( 'template' ) );
		$mappings = $request->get_param( 'mappings' );

		if ( empty( $name ) ) {
			return $this->error( __( 'Mapping name is required.', 'woo-feed' ), 400 );
		}

		if ( empty( $template ) ) {
			return $this->error( __( 'Template is required.', 'woo-feed' ), 400 );
		}

		// Frontend sends mappings as an array of objects: [ { cat_id: value }, ... ]
		// Flatten to single object.
		$flat_mappings = $this->flatten_mappings( $mappings );

		// Convert to V5 format for storage.
		$config = $this->v8_to_v5( $name, $template, $flat_mappings );

		// Generate unique option name (matches V5 behavior).
		$slug        = sanitize_title( $name );
		$option_name = self::PREFIX . $slug;

		$counter = 2;
		while ( false !== get_option( $option_name ) ) {
			$option_name = self::PREFIX . $slug . '-' . $counter;
			++$counter;
		}

		$saved = update_option( $option_name, $config, false );

		if ( ! $saved ) {
			return $this->error( __( 'Failed to save category mapping.', 'woo-feed' ), 500 );
		}

		// Delete product attribute dropdown cache (matches V5 behavior).
		delete_transient( '__woo_feed_cache_woo_feed_dropdown_product_attributes' );

		return $this->success(
			array(
				'message'     => __( 'Category mapping created successfully.', 'woo-feed' ),
				'option_name' => $option_name,
				'data'        => array(
					'name'        => $name,
					'template'    => $template,
					'mappings'    => $flat_mappings,
					'option_name' => $option_name,
				),
			),
			201 
		);
	}

	/**
	 * Update an existing category mapping.
	 * Saves in V5-compatible format for backward compatibility.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function update_mapping( \WP_REST_Request $request ): \WP_REST_Response {
		$option_name = $this->resolve_route_option_name( $request );

		$raw_existing = get_option( $option_name );

		if ( false === $raw_existing ) {
			return $this->error( __( 'Category mapping not found.', 'woo-feed' ), 404 );
		}

		// Handle V5 double-serialized data.
		$existing = $this->deep_unserialize( $raw_existing );

		if ( ! is_array( $existing ) ) {
			return $this->error( __( 'Category mapping data is invalid.', 'woo-feed' ), 500 );
		}

		// Get current values for merge.
		if ( $this->is_v5_format( $existing ) ) {
			$current = $this->v5_to_v8( $existing, $option_name );
		} else {
			$current = array(
				'name'     => $existing['name'] ?? '',
				'template' => $existing['template'] ?? '',
				'mappings' => $existing['mappings'] ?? array(),
			);
		}

		// Apply updates from request body.
		$body = $request->get_json_params();

		$final_name     = ! empty( $body['name'] ) ? sanitize_text_field( $body['name'] ) : $current['name'];
		$final_template = ! empty( $body['template'] ) ? sanitize_text_field( $body['template'] ) : $current['template'];

		// Handle mappings — check for append mode.
		$type         = $request->get_param( 'type' );
		$new_mappings = isset( $body['mappings'] ) ? $this->flatten_mappings( $body['mappings'] ) : null;

		if ( null !== $new_mappings ) {
			if ( 'append' === $type ) {
				// Merge new mappings into existing. We use array_replace
				// (NOT array_merge) because category IDs are numeric-string
				// keys — array_merge would treat them as sequential ints
				// and REINDEX, silently corrupting every mapping's key.
				// array_replace preserves keys and overwrites on collision,
				// which is the correct "append this one row" semantic the
				// React autosave flow depends on.
				$final_mappings = array_replace( (array) $current['mappings'], $new_mappings );
			} else {
				// Replace all mappings.
				$final_mappings = $new_mappings;
			}
		} else {
			$final_mappings = $current['mappings'];
		}

		// Convert to V5 format for storage.
		$config = $this->v8_to_v5( $final_name, $final_template, (array) $final_mappings );

		update_option( $option_name, $config, false );

		// Delete product attribute dropdown cache (matches V5 behavior).
		delete_transient( '__woo_feed_cache_woo_feed_dropdown_product_attributes' );

		return $this->success(
			array(
				'message'     => __( 'Category mapping updated successfully.', 'woo-feed' ),
				'option_name' => $option_name,
				'data'        => array(
					'name'        => $final_name,
					'template'    => $final_template,
					'mappings'    => $final_mappings,
					'option_name' => $option_name,
				),
			) 
		);
	}

	/**
	 * Delete a category mapping.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function delete_mapping( \WP_REST_Request $request ): \WP_REST_Response {
		$option_name = $this->resolve_route_option_name( $request );

		$existing = get_option( $option_name );

		if ( false === $existing ) {
			return $this->error( __( 'Category mapping not found.', 'woo-feed' ), 404 );
		}

		delete_option( $option_name );

		// Delete product attribute dropdown cache (matches V5 behavior).
		delete_transient( '__woo_feed_cache_woo_feed_dropdown_product_attributes' );

		return $this->success(
			array(
				'message' => __( 'Category mapping deleted successfully.', 'woo-feed' ),
				'deleted' => true,
			) 
		);
	}

	// =====================================================================
	// WooCommerce categories endpoint.
	// =====================================================================

	/**
	 * Get WooCommerce product categories in hierarchical format.
	 *
	 * Returns flat list with hierarchy string for display:
	 * [ { id: 15, name: "Clothing", hierarchy: "Clothing" },
	 *   { id: 22, name: "T-Shirts", hierarchy: "Clothing > T-Shirts" } ]
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_wc_categories( \WP_REST_Request $request ): \WP_REST_Response {
		$categories = $this->load_all_wc_categories();

		if ( is_wp_error( $categories ) ) {
			return $this->error( $categories->get_error_message(), 500 );
		}

		// Optional case-insensitive substring filter on the hierarchy
		// string. Runs on the already-cached list — no extra DB.
		$search = (string) $request->get_param( 'search' );
		if ( '' !== $search ) {
			$needle     = strtolower( $search );
			$categories = array_values(
				array_filter(
					$categories,
					static function ( $cat ) use ( $needle ) {
						return false !== strpos( strtolower( $cat['hierarchy'] ), $needle );
					}
				) 
			);
		}

		$total = count( $categories );

		// Pagination — bounds-checked so out-of-range page returns []
		// (with total set) rather than 500-ing.
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = self::CAT_DEFAULT_PER_PAGE;
		}
		$per_page = min( $per_page, self::CAT_MAX_PER_PAGE );

		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$offset = ( $page - 1 ) * $per_page;
		$items  = $offset >= $total ? array() : array_slice( $categories, $offset, $per_page );

		return $this->success(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			) 
		);
	}

	/**
	 * Load the full list of WooCommerce product categories with pre-
	 * computed hierarchy strings, caching the result in a transient.
	 *
	 * The transient IS the source of truth for the endpoint — search
	 * and pagination happen in-memory on the cached array. Rebuilding
	 * costs a `get_terms` + one pass of hierarchy computation. Cache
	 * hit avoids both.
	 *
	 * Cache invalidation is wired via register_cache_invalidation_hooks()
	 * (called from the ServiceProvider on `init`).
	 *
	 * @since 8.0.0
	 *
	 * @return array|\WP_Error Cached categories, or WP_Error on term-query failure.
	 */
	private function load_all_wc_categories() {
		$cached = get_transient( self::CAT_CACHE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			) 
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$term_map = array();
		foreach ( $terms as $term ) {
			$term_map[ $term->term_id ] = $term;
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'id'        => $term->term_id,
				'name'      => $term->name,
				'slug'      => $term->slug,
				'parent'    => $term->parent,
				'count'     => $term->count,
				'hierarchy' => $this->build_hierarchy( $term, $term_map ),
			);
		}

		// Sort by hierarchy string so parents render before their children.
		// `get_terms` sorted by leaf `name`, which puts "Accessories" before
		// its parent "Clothing" — surprising in a flat list where the user
		// expects "Clothing" then "Clothing > Accessories". String compare
		// on the hierarchy handles this naturally because a prefix always
		// sorts before its longer form ("Clothing" < "Clothing > …").
		usort(
			$categories,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['hierarchy'], $b['hierarchy'] );
			} 
		);

		// BUG-0062: single, user-configurable cache lifetime (shared with the
		// attribute list + compat caches). The transient is also busted on
		// product_cat term changes (register_cache_invalidation_hooks), so this
		// TTL is a safety net. self::CAT_CACHE_TTL is retained only as the
		// documented former default.
		set_transient( self::CAT_CACHE_TRANSIENT, $categories, SettingsEndpoint::get_cache_ttl() );

		return $categories;
	}

	/**
	 * Register cache-invalidation hooks. Called once at plugin boot.
	 *
	 * Any user action that could change the category hierarchy string
	 * (add, rename, reparent, delete) must clear the transient so the
	 * next endpoint hit rebuilds. Missing an invalidation trigger =
	 * stale list in the admin UI for up to CAT_CACHE_TTL.
	 *
	 * Safe to call more than once — WP dedupes identical hook callbacks.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public static function register_cache_invalidation_hooks(): void {
		$flush = static function () {
			delete_transient( self::CAT_CACHE_TRANSIENT );
		};
		add_action( 'created_product_cat', $flush );
		add_action( 'edited_product_cat', $flush );
		add_action( 'delete_product_cat', $flush );
	}

	/**
	 * Build the full hierarchy path for a term.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_Term $term     The term object.
	 * @param array    $term_map Term ID → term object lookup.
	 * @return string Hierarchy string, e.g. "Clothing > T-Shirts > V-Neck".
	 */
	private function build_hierarchy( \WP_Term $term, array $term_map ): string {
		$parts   = array( $term->name );
		$current = $term;

		while ( $current->parent > 0 && isset( $term_map[ $current->parent ] ) ) {
			$current = $term_map[ $current->parent ];
			array_unshift( $parts, $current->name );
		}

		return implode( ' > ', $parts );
	}

	// =====================================================================
	// Template-specific categories (merchant taxonomy search).
	// =====================================================================

	/**
	 * Search merchant/template-specific categories.
	 *
	 * Reads the local taxonomy file for the given template (google, facebook, etc.)
	 * and returns matching categories filtered by search term.
	 *
	 * @since 8.0.0
	 *
	 * @param \WP_REST_Request $request REST request object.
	 * @return \WP_REST_Response
	 */
	public function get_template_categories( \WP_REST_Request $request ): \WP_REST_Response {
		$template = $request->get_param( 'template' );
		$search   = $request->get_param( 'search' );
		$ids      = $request->get_param( 'ids' );

		if ( empty( $template ) ) {
			return $this->error( __( 'Template parameter is required.', 'woo-feed' ), 400 );
		}

		// Resolve template key to taxonomy file.
		$file_path = $this->resolve_taxonomy_file( $template );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return $this->success( array() );
		}

		$categories = $this->parse_taxonomy_file( $file_path, $template );

		// Direct-ID lookup mode — takes precedence over search. Used by the
		// mapping editor to hydrate labels for existing saved IDs so the
		// dropdown shows "1604 - Apparel & Accessories > Clothing" instead
		// of the bare "1604" the user has stored. Returns exact matches
		// only; no substring behavior, no 50-item cap (a page holds up to
		// per_page mappings, so the caller controls the size).
		if ( ! empty( $ids ) ) {
			$requested = array_filter( array_map( 'absint', explode( ',', $ids ) ) );
			if ( ! empty( $requested ) ) {
				$as_set     = array_flip( $requested );
				$categories = array_values(
					array_filter(
						$categories,
						static function ( $cat ) use ( $as_set ) {
							return isset( $as_set[ (int) $cat['id'] ] );
						}
					) 
				);
			}
			return $this->success( $categories );
		}

		// Filter by search term if provided.
		if ( ! empty( $search ) ) {
			$search_lower = strtolower( $search );
			$categories   = array_values(
				array_filter(
					$categories,
					function ( $cat ) use ( $search_lower ) {
						// Match against both the raw name and the ID-prefixed label
						// so users can type either "clothing" or "1604".
						return false !== strpos( strtolower( $cat['name'] ), $search_lower )
						|| false !== strpos( strtolower( $cat['label'] ), $search_lower );
					} 
				)
			);
		}

		// Limit results to prevent huge payloads.
		$categories = array_slice( $categories, 0, 50 );

		return $this->success( $categories );
	}

	/**
	 * Resolve a template key to the local taxonomy file path.
	 *
	 * Maps common template identifiers to their taxonomy file.
	 * Google Shopping, Facebook Catalog, and their variants all
	 * use the same taxonomy files.
	 *
	 * @since 8.0.0
	 *
	 * @param string $template Template/merchant key.
	 * @return string|null File path or null if unsupported.
	 */
	private function resolve_taxonomy_file( string $template ): ?string {
		$taxonomy_dir = TemplateLocator::taxonomy_dir();
		$template     = strtolower( $template );

		// Map templates to their taxonomy files.
		$google_templates = array(
			'google',
			'google_shopping',
			'google_local',
			'google_local_inventory',
			'google_shopping_promotions',
			'google_dynamic_search_ads',
			'google_manufacturer',
			'pinterest',
			'pinterest_rss',
			'bing',
			'bing_shopping',
			'snapchat',
			'tiktok',
			'ideal',
			'pricepy',
			'become',
		);

		$facebook_templates = array(
			'facebook',
			'facebook_catalog',
			'instagram',
		);

		if ( in_array( $template, $google_templates, true ) ) {
			return $this->google_taxonomy_path();
		}

		if ( in_array( $template, $facebook_templates, true ) ) {
			return $taxonomy_dir . 'fb_taxonomy.txt';
		}

		// Try a generic file.
		$generic = $taxonomy_dir . $template . '_taxonomy.txt';
		if ( file_exists( $generic ) ) {
			return $generic;
		}

		// Fallback to Google taxonomy (most merchants use Google categories).
		$google_file = $this->google_taxonomy_path();
		if ( file_exists( $google_file ) ) {
			return $google_file;
		}

		return null;
	}

	/**
	 * Resolve the active Google taxonomy file, preferring the user-refreshed
	 * copy in uploads over the bundled plugin file.
	 *
	 * A manual "Update taxonomies" writes a fresh list to the uploads override
	 * (see ChannelEndpoint::update_google_taxonomy). Whenever that file is
	 * present it wins; otherwise we fall back to the taxonomy shipped with the
	 * plugin. All Google-derived channels (pinterest, bing, snapchat, …) funnel
	 * through here, so the override covers every one of them.
	 *
	 * @since 8.0.0
	 *
	 * @return string Absolute path to the Google taxonomy file.
	 */
	private function google_taxonomy_path(): string {
		$override = TemplateLocator::google_override_file();

		if ( '' !== $override && file_exists( $override ) ) {
			return $override;
		}

		return TemplateLocator::taxonomy_dir() . 'google_taxonomy.txt';
	}

	/**
	 * Parse a taxonomy text file into an array of { id, name } objects.
	 *
	 * Supports two formats:
	 * - Google: "ID - Category > Subcategory" (dash-separated, first line is comment)
	 * - Facebook: "ID,Category > Subcategory" (comma-separated, first line is header)
	 *
	 * @since 8.0.0
	 *
	 * @param string $file_path Path to the taxonomy file.
	 * @param string $template  Template key (to detect format).
	 * @return array Array of [ 'id' => int, 'name' => string ].
	 */
	private function parse_taxonomy_file( string $file_path, string $template ): array {
		// Serve the parsed list from cache when available — the file only
		// changes on a manual "Update taxonomies", which busts this key.
		$cache_key = self::taxonomy_parse_cache_key( $file_path );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams a multi-megabyte taxonomy .txt bundled with the plugin line by line; WP_Filesystem would load the whole file into memory and blow the admin request's memory limit.
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return array();
		}

		$categories  = array();
		$is_facebook = ( false !== strpos( strtolower( $template ), 'facebook' ) ||
						false !== strpos( strtolower( $template ), 'instagram' ) );

		// Skip first line (metadata/header).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fgets -- Discards the metadata/header line of the bundled taxonomy file; part of the line-by-line stream opened above.
		fgets( $handle );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fgets, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Canonical line-reading idiom: the assignment is deliberate and explicitly parenthesised, and the !== false test is what distinguishes EOF from a falsy blank line.
		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = trim( $line );
			if ( empty( $line ) ) {
				continue;
			}

			if ( $is_facebook ) {
				// Facebook format: "ID,Category > Subcategory".
				$parts = explode( ',', $line, 2 );
			} else {
				// Google format: "ID - Category > Subcategory".
				$parts = explode( '-', $line, 2 );
			}

			if ( count( $parts ) < 2 ) {
				continue;
			}

			$cat_id   = absint( trim( $parts[0] ) );
			$cat_name = trim( $parts[1] );

			if ( $cat_id > 0 && ! empty( $cat_name ) ) {
				$categories[] = array(
					'id'    => $cat_id,
					'name'  => $cat_name,
					// V5-parity: dropdowns show "<ID> - <Path>" as the visible
					// label. See V5\Common\DropDownOptions::googleTaxonomyArray()
					// line 860. Exposing the composed string from the API keeps
					// display logic out of the React layer and makes the ID
					// visible to power users looking values up in Google's spec.
					'label' => $cat_id . ' - ' . $cat_name,
				);
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the read handle opened above.

		set_transient( $cache_key, $categories, MONTH_IN_SECONDS );

		return $categories;
	}

	/**
	 * Cache key for a parsed taxonomy file, derived from its basename.
	 *
	 * Keyed by basename (not the full path) so the bundled and the
	 * uploads-override copy of `google_taxonomy.txt` share one key — an
	 * update writes the override, busts this key, and the next read reparses
	 * whichever copy now wins in resolve_taxonomy_file().
	 *
	 * @since 8.0.0
	 *
	 * @param string $file_path Absolute path to a taxonomy file.
	 * @return string Transient key.
	 */
	public static function taxonomy_parse_cache_key( string $file_path ): string {
		return self::TAXONOMY_PARSE_CACHE_PREFIX . sanitize_key( pathinfo( $file_path, PATHINFO_FILENAME ) );
	}

	/**
	 * Drop the cached parse of the Google taxonomy list.
	 *
	 * Called after a manual "Update taxonomies" so the FREE category-mapping
	 * UI serves the freshly downloaded list on the next request instead of
	 * waiting out the month-long TTL.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public static function flush_google_taxonomy_cache(): void {
		delete_transient( self::TAXONOMY_PARSE_CACHE_PREFIX . 'google_taxonomy' );
	}

	// =====================================================================
	// Utility helpers.
	// =====================================================================

	/**
	 * Flatten mappings from frontend format to a simple associative array.
	 *
	 * Frontend sends mappings as: [ { cat_id: value, ... }, { ... } ]
	 * We need: { cat_id: value, cat_id: value, ... }
	 *
	 * Also handles the case where mappings is already a flat object.
	 *
	 * @since 8.0.0
	 *
	 * @param mixed $mappings Raw mappings from request.
	 * @return array Flat associative array of category_id => category_value.
	 */
	private function flatten_mappings( $mappings ): array {
		if ( ! is_array( $mappings ) ) {
			return array();
		}

		$flat = array();

		// Check if it's an array of objects (frontend format).
		if ( isset( $mappings[0] ) && is_array( $mappings[0] ) ) {
			foreach ( $mappings as $group ) {
				foreach ( (array) $group as $cat_id => $cat_value ) {
					if ( ! empty( $cat_value ) ) {
						$flat[ (string) $cat_id ] = sanitize_text_field( $cat_value );
					}
				}
			}
		} else {
			// Already a flat associative array.
			foreach ( $mappings as $cat_id => $cat_value ) {
				if ( ! empty( $cat_value ) ) {
					$flat[ (string) $cat_id ] = sanitize_text_field( $cat_value );
				}
			}
		}

		return $flat;
	}
}
