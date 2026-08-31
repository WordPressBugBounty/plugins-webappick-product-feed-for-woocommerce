<?php
/**
 * ProductQuery — Builds WC_Product_Query with feed filter rules.
 *
 * Returns only product IDs (not full objects) for memory efficiency.
 * Supports variation expansion, pagination, and total count.
 *
 * @package    CTXFeed
 * @subpackage V8/Product
 * @since      8.0.0
 * @implements PROD-FRD-3.1, PROD-FRD-3.2, PROD-FRD-3.3, PROD-FRD-3.4
 */

namespace CTXFeed\V8\Product;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product query builder.
 *
 * @since 8.0.0
 */
class ProductQuery {

	/**
	 * Transient key prefix for the per-feed product-ID snapshot.
	 *
	 * The snapshot caches the resolved product-ID list for the duration
	 * of a single generation cycle. Without it, every batch in a 200K
	 * product feed would re-run the full WC_Product_Query (and the
	 * `expand_variations` variation queries), turning what should be one
	 * pass into 400+ identical query cycles.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const SNAPSHOT_PREFIX = 'ctxfeed_query_snapshot_';

	/**
	 * Snapshot transient TTL — 3 hours.
	 *
	 * Sized to outlast a slow generation so the ID list can't expire
	 * mid-run (an expiry mid-flight would rebuild the list against a
	 * catalog that may have changed since scheduling, duplicating or
	 * skipping products). `get_transient()` does NOT extend a transient's
	 * TTL on read, so this window must cover the whole run on its own: at
	 * the TIME_PER_PRODUCT budget a 200K-product feed runs ~2.8h, so 3h
	 * leaves headroom. Short enough that an abandoned generation still
	 * self-cleans within a few hours.
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const SNAPSHOT_TTL = 10800;

	/**
	 * Max parent IDs per variation query.
	 *
	 * {@see fetch_variation_map()} chunks the parent-ID list so a large
	 * catalog never builds a single multi-thousand-item `IN ()` clause when
	 * expanding variations.
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const VARIATION_QUERY_CHUNK = 2000;

	/**
	 * Feed name for snapshot binding.
	 *
	 * When set, get_total_count() and get_paginated_ids() share a
	 * cached ID list across batches. Set via {@see set_feed_name()}
	 * by the FeedScheduler / FeedGenerator at the start of a generation
	 * cycle. Empty (the default) means no caching — the legacy
	 * "query every time" behaviour, useful for ad-hoc previews and
	 * counts in the UI.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private string $feed_name = '';

	/**
	 * Bind this query to a feed for snapshot caching.
	 *
	 * Idempotent. Pass an empty string to opt out of caching.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed slug, or '' to disable snapshotting.
	 *
	 * @return void
	 */
	public function set_feed_name( string $feed_name ): void {
		$this->feed_name = $feed_name;
	}

	/**
	 * Get the snapshot transient key for this feed.
	 *
	 * @since 8.0.0
	 *
	 * @return string Transient key, or '' if no feed bound.
	 */
	private function snapshot_key(): string {
		return $this->feed_name ? self::SNAPSHOT_PREFIX . $this->feed_name : '';
	}

	/**
	 * Drop the cached product-ID snapshot for a feed.
	 *
	 * Call this from FeedGenerator::finalize() and FeedScheduler::cancel()
	 * so the next generation cycle starts with a fresh query — otherwise
	 * a feed re-run within the 2-hour TTL window would emit yesterday's
	 * product set.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed slug. Empty = use the bound name.
	 *
	 * @return void
	 */
	public function clear_snapshot( string $feed_name = '' ): void {
		$name = $feed_name ? $feed_name : $this->feed_name;
		if ( '' === $name ) {
			return;
		}
		delete_transient( self::SNAPSHOT_PREFIX . $name );

		// Unbind this instance when the feed it was bound to is the one being
		// cleared, so a reused ProductQuery can never read a stale snapshot.
		if ( $name === $this->feed_name ) {
			$this->feed_name = '';
		}
	}

	/**
	 * Get all product IDs matching feed configuration filters.
	 *
	 * Builds WC_Product_Query with ID-only return for lightweight results.
	 * Applies category/type filters from Config and expands variations
	 * when configured.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-3.1, PROD-FRD-3.2
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return int[] Array of product IDs.
	 */
	public function get_ids( Config $config ): array {
		// User-configurable feed sort (V5's "Sort feed by"), defaulting to the
		// stable ID-ascending order the snapshot + batching rely on.
		list( $orderby, $order ) = $this->resolve_sort(
			(string) $config->get( 'feed_sort', '' ),
			(string) $config->get( 'feed_sort_order', '' )
		);

		$args = array(
			'status'  => $this->resolve_post_status( $config ),
			'limit'   => -1,
			'return'  => 'ids',
			'orderby' => $orderby,
			'order'   => $order,
		);

		// Product type filter.
		$product_types = $config->get( 'product_types', array( 'simple', 'variable', 'grouped', 'external' ) );
		$args['type']  = $product_types;

		// Category filter is applied per-product in CategoryFilter — it has
		// full include/exclude mode support via filter_mode. Keeping the
		// category filter out of the query avoids double-filtering and lets
		// exclude-mode work correctly (WC_Product_Query's `category` arg is
		// include-only).

		// Allow V8 and compat plugins to modify query args.
		// @hook ctxfeed_product_query_args.
		$args = apply_filters( 'ctxfeed_product_query_args', $args, $config );

		$query       = new \WC_Product_Query( $args );
		$product_ids = $query->get_products();

		// Expand variations if configured.
		// @implements PROD-FRD-3.2.
		if ( in_array( 'variable', (array) $product_types, true ) ) {
			$include_variations = $config->get( 'is_variations', 'y' );

			// A blank "Include variations" field is persisted as '' (the key is
			// present), so Config::get()'s 'y' default never fires and the value
			// matches NEITHER the expand branch NOR the single-variation branch
			// below — every variable product would silently export parent-only
			// (identical to 'n'), even though the intended default is 'y' (All
			// Variations) and the sibling Config::is_variations() reads '' as ON.
			// Normalise the empty/null value to the 'y' default before branching.
			if ( '' === $include_variations || null === $include_variations ) {
				$include_variations = 'y';
			}

			// Review feeds are PRODUCT-level: WooCommerce reviews live on the
			// parent product, never on individual variations. Always keep the
			// parent (ignore the is_variations setting) so ReviewResolver finds
			// its reviews — the product block still lists every variation's
			// identifiers (see ReviewResolver::resolve_product_ids). Without
			// this, a review feed with "All Variations" iterates review-less
			// variations and emits an empty <reviews> list.
			if ( 'googlereview' === $config->get_provider() ) {
				$include_variations = 'n';
			}

			// 'y' = All Variations (parents replaced by their child variations).
			// 'both' = Variable + Variations (parent kept, children appended).
			// 'n' = Variable Products / Parent only (no change).
			// default/cheap/expensive/first/last = replace each variable parent
			// with exactly ONE chosen child variation (V5 parity).
			if ( 'y' === $include_variations || 'both' === $include_variations ) {
				$product_ids = $this->expand_variations(
					$product_ids,
					$config,
					'both' === $include_variations
				);
			} elseif ( in_array( $include_variations, array( 'default', 'cheap', 'expensive', 'first', 'last' ), true ) ) {
				$product_ids = $this->substitute_single_variation( $product_ids, $include_variations );
			}
		}

		// @hook ctxfeed_product_ids
		return apply_filters( 'ctxfeed_product_ids', $product_ids, $config );
	}

	/**
	 * Resolve the feed-sort config into WC_Product_Query orderby + order.
	 *
	 * Restores V5's "Sort feed by" control. The choice is whitelisted to
	 * WC_Product_Query-native orderby values so it can never inject an
	 * arbitrary column; an unknown/empty choice falls back to the stable
	 * ID-ascending order the per-feed snapshot and multi-batch slicing depend
	 * on. When no explicit direction is given, each sort uses its natural
	 * default (newest-first for date/modified — V5 parity — ascending
	 * otherwise). Developers can still fully override via the
	 * `ctxfeed_product_query_args` filter.
	 *
	 * @since 8.0.0
	 *
	 * @param string $sort  Sort key (id|date|modified|title|menu_order|random).
	 * @param string $order Direction (ASC|DESC), or '' for the sort's default.
	 * @return array{0:string,1:string} [ orderby, order ].
	 */
	private function resolve_sort( string $sort, string $order ): array {
		$orderby_map = array(
			'id'         => 'ID',
			'date'       => 'date',
			'modified'   => 'modified',
			'title'      => 'title',
			'menu_order' => 'menu_order',
			'random'     => 'rand',
		);

		$sort = strtolower( trim( $sort ) );

		if ( '' === $sort || ! isset( $orderby_map[ $sort ] ) ) {
			// Default / unknown → stable ID ascending (snapshot- and test-safe).
			return array( 'ID', 'ASC' );
		}

		if ( 'random' === $sort ) {
			return array( 'rand', 'ASC' ); // Direction is irrelevant for rand.
		}

		$order = strtoupper( trim( $order ) );
		if ( 'ASC' !== $order && 'DESC' !== $order ) {
			// Natural default per sort: newest-first for time, A→Z otherwise.
			$order = in_array( $sort, array( 'date', 'modified' ), true ) ? 'DESC' : 'ASC';
		}

		return array( $orderby_map[ $sort ], $order );
	}

	/**
	 * Get total count of matching products.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-3.3
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return int Total product count.
	 */
	public function get_total_count( Config $config ): int {
		return count( $this->get_or_snapshot_ids( $config ) );
	}

	/**
	 * Get a paginated slice of product IDs.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-3.4
	 *
	 * @param Config $config Feed configuration.
	 * @param int    $offset Starting offset.
	 * @param int    $limit  Maximum IDs to return.
	 *
	 * @return int[] Sliced array of product IDs.
	 */
	public function get_paginated_ids( Config $config, int $offset, int $limit ): array {
		$all_ids = $this->get_or_snapshot_ids( $config );

		return array_slice( $all_ids, $offset, $limit );
	}

	/**
	 * Resolve product IDs, using a per-feed snapshot if one exists.
	 *
	 * Behaviour:
	 *   - If no `feed_name` is bound, falls through to {@see get_ids()}
	 *     every call (legacy preview-style behaviour, no side effects).
	 *   - If a `feed_name` is bound and a snapshot transient exists,
	 *     returns the snapshot — same IDs every batch, no DB cost.
	 *   - If a `feed_name` is bound and no snapshot exists, runs
	 *     {@see get_ids()} once and persists the result for all
	 *     subsequent batches in the same generation cycle.
	 *
	 * Snapshot membership is intentional: a feed generation should emit
	 * the product set as it existed when scheduling started. Products
	 * added or removed mid-generation are picked up on the next run, not
	 * the current one.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return int[] Array of product IDs.
	 */
	private function get_or_snapshot_ids( Config $config ): array {
		$key = $this->snapshot_key();

		if ( '' === $key ) {
			// No feed bound — preview/count usage. Run the live query.
			return $this->get_ids( $config );
		}

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = $this->get_ids( $config );
		set_transient( $key, $ids, self::SNAPSHOT_TTL );

		return $ids;
	}

	/**
	 * Expand variable product IDs into their variation IDs.
	 *
	 * Non-variable products pass through unchanged. For each variable
	 * product: with `$keep_parent` false the parent is replaced by its child
	 * variation IDs ("All Variations"); with `$keep_parent` true the parent ID
	 * is kept and its variation IDs are appended after it ("Variable +
	 * Variations").
	 *
	 * Performance (PROD-FRD-3.2): variations are resolved with a single
	 * ID-only query per chunk of parents ({@see fetch_variation_map()})
	 * instead of instantiating a WC_Product object for every parent. The old
	 * `wc_get_product( $id )->get_children()` loop hydrated a full product
	 * (all meta, price data, etc.) for each of potentially tens of thousands
	 * of variable parents — the dominant cost when building the snapshot for
	 * a large variable catalog. We only ever ask the database for IDs.
	 *
	 * A parent that appears in the variation map is, by definition, a variable
	 * product with at least one variation; anything else (simple / grouped /
	 * external, or a degenerate variable product with no variations) passes
	 * through unchanged.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-3.2
	 *
	 * @param int[]  $product_ids Array of product IDs.
	 * @param Config $config      Feed configuration.
	 * @param bool   $keep_parent Keep the variable parent row alongside its variations.
	 *
	 * @return int[] Expanded array with variation IDs.
	 */
	private function expand_variations( array $product_ids, Config $config, bool $keep_parent = false ): array {
		if ( empty( $product_ids ) ) {
			return array();
		}

		// Second query: variation IDs for the whole set, grouped by parent.
		$children_by_parent = $this->fetch_variation_map( $product_ids, $config );

		$expanded = array();

		foreach ( $product_ids as $id ) {
			$id = (int) $id;

			if ( isset( $children_by_parent[ $id ] ) ) {
				// Variable parent with ≥1 variation. 'both' keeps the parent
				// row before its variations; 'y' replaces it with the
				// variations only.
				if ( $keep_parent ) {
					$expanded[] = $id;
				}
				foreach ( $children_by_parent[ $id ] as $child_id ) {
					$expanded[] = $child_id;
				}
			} else {
				$expanded[] = $id;
			}
		}

		return $expanded;
	}

	/**
	 * Resolve the variation IDs for a set of product IDs as an ID-only map.
	 *
	 * Runs one `product_variation` query per chunk of parent IDs, requesting
	 * only the `id => parent` pairs (never a product object). Non-variable
	 * parents in `$parent_ids` simply have no `product_variation` children and
	 * contribute nothing, so the caller does not need to know each product's
	 * type in advance. The query mirrors WooCommerce's own variable data store
	 * (`read_children`): `post_status` publish + private, ordered by
	 * `menu_order` then `ID`, so the resulting child order matches
	 * `WC_Product::get_children()` for output parity.
	 *
	 * The parent IDs are chunked so a large catalog never builds a single
	 * multi-thousand-item `IN ()` clause.
	 *
	 * @since 8.0.0
	 *
	 * @param int[]  $parent_ids Product IDs to fetch variations for.
	 * @param Config $config     Feed configuration (reserved for hooks).
	 *
	 * @return array<int,int[]> Map of parent ID => ordered child variation IDs.
	 */
	private function fetch_variation_map( array $parent_ids, Config $config ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $config kept for signature parity with the query pipeline; reserved for variation-query hooks.
		global $wpdb;

		$parent_ids = array_values( array_unique( array_map( 'intval', $parent_ids ) ) );

		$map = array();

		foreach ( array_chunk( $parent_ids, self::VARIATION_QUERY_CHUNK ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			// Read the parent→child relationship straight from the posts table.
			// We deliberately do NOT use WP_Query/get_posts with
			// `fields => 'id=>parent'` here. That mode has a WordPress core cache
			// quirk: this variation lookup runs TWICE during one generation (once
			// for the schedule-time count, once inside the batch), and on the
			// SECOND identical query WP_Query returns the map from its object
			// cache with the keys mangled to the string `"post_parent:{id}"`
			// instead of the child id. `expand_variations()` then casts those to
			// (int) 0, `wc_get_product( 0 )` returns false in the batch loop, and
			// EVERY variation silently drops — so "Variable + Variation"
			// (is_variations=both) and "All Variations" (y) feeds export the
			// parents only. (A WPML site hit the same symptom via a different path
			// — its result filter also zeroes untranslated variation ids — so a
			// raw read fixes both.) A variation is a structural child of its
			// parent regardless of language; the per-language product set is still
			// enforced afterwards by the `ctxfeed_product_ids` filter (which keeps
			// only the feed-language variations via the WPML/Polylang shims).
			// Ordering mirrors WooCommerce's own read_children (menu_order, then
			// ID) over publish + private children.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Structural child-ID lookup that MUST bypass WP_Query's id=>parent result cache (it mangles keys to "post_parent:{id}" on the repeated query, and WPML zeroes ids too); $placeholders is only %d tokens (one per parent id) interpolated into the query then filled by prepare() with the int parent ids — the standard safe dynamic-IN() pattern; results feed the per-feed snapshot which is itself cached.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_parent FROM {$wpdb->posts}
					 WHERE post_type = 'product_variation'
					   AND post_status IN ( 'publish', 'private' )
					   AND post_parent IN ($placeholders)
					 ORDER BY menu_order ASC, ID ASC",
					$chunk
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			foreach ( (array) $rows as $row ) {
				$parent_id = (int) $row->post_parent;
				if ( ! isset( $map[ $parent_id ] ) ) {
					$map[ $parent_id ] = array();
				}
				$map[ $parent_id ][] = (int) $row->ID;
			}
		}

		return $map;
	}

	/**
	 * Replace each variable parent with exactly ONE chosen child variation.
	 *
	 * Implements the single-pick `is_variations` modes — default / cheap /
	 * expensive / first / last. Unlike `expand_variations()`, the parent is
	 * NOT kept: each variable product collapses to a single variation row.
	 * Non-variable products pass through unchanged. When a variation cannot
	 * be determined (no children, empty price map, no default match, or the
	 * product was deleted mid-run) the parent ID is kept — matching V5's
	 * ProductHelper::get_product_object() parent fallback.
	 *
	 * @since 8.0.0
	 * @implements PROD-FRD-3.2
	 *
	 * @param int[]  $product_ids Array of product IDs (variable parents included).
	 * @param string $mode        One of default|cheap|expensive|first|last.
	 *
	 * @return int[] Flat ID list with each variable parent swapped for one variation.
	 */
	private function substitute_single_variation( array $product_ids, string $mode ): array {
		$result = array();

		foreach ( $product_ids as $id ) {
			$product = wc_get_product( $id );

			// Deleted mid-run, or not a variable product → keep the ID as-is
			// (parity with expand_variations()'s pass-through).
			if ( ! $product || ! $product->is_type( 'variable' ) ) {
				$result[] = (int) $id;
				continue;
			}

			$variation_id = $this->select_single_variation_id( $product, $mode );

			// No variation resolved → fall back to the parent (V5 parity).
			$result[] = $variation_id ? $variation_id : (int) $id;
		}

		return $result;
	}

	/**
	 * Pick a single variation ID from a variable product for the given mode.
	 *
	 * Ports V5 ProductHelper::determine_variable_product():
	 *   - first/last     → first / last of get_visible_children() (menu order).
	 *   - cheap/expensive → min / max of get_variation_prices()['price'].
	 *   - default         → the variation matching the product's default
	 *                       attributes (see {@see find_default_variation_id()}).
	 *
	 * Returns 0 when no variation can be determined so the caller can fall
	 * back to the parent — never returns a 0/empty variation into the feed.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product Variable product (guaranteed by the caller).
	 * @param string      $mode    One of default|cheap|expensive|first|last.
	 *
	 * @return int Chosen variation ID, or 0 for the parent fallback.
	 */
	private function select_single_variation_id( \WC_Product $product, string $mode ): int {
		switch ( $mode ) {
			case 'first':
			case 'last':
				$children = $product->get_visible_children();
				if ( empty( $children ) ) {
					return 0;
				}
				return (int) ( 'first' === $mode ? reset( $children ) : end( $children ) );

			case 'cheap':
			case 'expensive':
				$prices = $product->get_variation_prices();
				// Guard the empty map before min()/max() — an empty array is a
				// fatal ValueError on PHP 8. Falls back to the parent.
				if ( empty( $prices['price'] ) ) {
					return 0;
				}
				$price_map = $prices['price'];
				$target    = ( 'cheap' === $mode ) ? min( $price_map ) : max( $price_map );
				// array_keys() returns the first matching variation on a price
				// tie — matches V5's array_keys( $prices, $target )[0].
				$matches = array_keys( $price_map, $target ); // phpcs:ignore WordPress.PHP.StrictInArray.MissingTrueStrict -- loose match mirrors V5 so '10' and '10.00' are equal.
				return empty( $matches ) ? 0 : (int) $matches[0];

			case 'default':
				return $this->find_default_variation_id( $product );

			default:
				return 0;
		}
	}

	/**
	 * Resolve the variation matching a variable product's default attributes.
	 *
	 * Ports V5 ProductHelper::get_default_product_variation(): read the
	 * product's default attributes, prefix bare keys with `attribute_`, and
	 * resolve a concrete variation via the product-variation data store.
	 * Returns 0 when no default is set or nothing matches, so the caller
	 * falls back to the parent (V5 parity).
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product Variable product.
	 *
	 * @return int Matching variation ID, or 0 when none.
	 */
	private function find_default_variation_id( \WC_Product $product ): int {
		$default_attributes = $product->get_default_attributes();

		if ( empty( $default_attributes ) ) {
			return 0;
		}

		// V5 parity: WC variation matching expects `attribute_`-prefixed keys.
		$formatted = array();
		foreach ( $default_attributes as $key => $value ) {
			$key               = ( 0 === strpos( (string) $key, 'attribute_' ) ) ? $key : 'attribute_' . $key;
			$formatted[ $key ] = $value;
		}

		return (int) $this->match_default_variation( $product, $formatted );
	}

	/**
	 * Match a variation ID from formatted default attributes.
	 *
	 * Isolated seam around the WooCommerce product-variation data store so
	 * unit tests can stub the lookup without a live store. Mirrors V5's
	 * `( new WC_Product_Variation_Data_Store_CPT() )->find_matching_product_variation()`.
	 *
	 * @since 8.0.0
	 *
	 * @param \WC_Product $product             Variable product.
	 * @param array       $formatted_attributes `attribute_*` => value map.
	 *
	 * @return int Matching variation ID, or 0 when none / store unavailable.
	 */
	protected function match_default_variation( \WC_Product $product, array $formatted_attributes ): int {
		if ( ! class_exists( '\WC_Product_Variation_Data_Store_CPT' ) ) {
			return 0;
		}

		$data_store = new \WC_Product_Variation_Data_Store_CPT();

		return (int) $data_store->find_matching_product_variation( $product, $formatted_attributes );
	}

	/**
	 * Resolve the list of post statuses to query based on V5 feedrules.
	 *
	 * Reads `post_status` (array of slugs) and `filter_mode.post_status`
	 * ('include' | 'exclude') — matches V5 Config::get_post_status_to_include().
	 *
	 * Defaults to ['publish'] when nothing is configured.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return array|string Post status list or single status string.
	 */
	private function resolve_post_status( Config $config ) {
		$all_statuses    = array( 'publish', 'draft', 'pending', 'private' );
		$selected        = (array) $config->get( 'post_status', array() );
		$filter_mode_all = (array) $config->get( 'filter_mode', array() );
		$mode            = isset( $filter_mode_all['post_status'] ) ? $filter_mode_all['post_status'] : 'include';

		if ( empty( $selected ) ) {
			return 'publish';
		}

		if ( 'exclude' === $mode ) {
			$result = array_values( array_diff( $all_statuses, $selected ) );
			return empty( $result ) ? 'publish' : $result;
		}

		return $selected;
	}
}
