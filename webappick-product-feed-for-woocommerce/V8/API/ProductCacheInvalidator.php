<?php
/**
 * ProductCacheInvalidator — drops the product-derived admin caches OFF the
 * product-save / checkout hot path.
 *
 * Two admin-only caches are derived from the catalog: the Filters-tab counts
 * (`ctxfeed_v8_filter_counts`) and the Make-Feed mapping-attribute dropdown
 * (ProductEndpoint::MAPPING_ATTR_CACHE_PREFIX). They must drop when a product
 * changes, but the change events fire inside requests a merchant or shopper
 * is waiting on: Quick Edit, product save, stock reduction at checkout. A
 * transient delete is cheap on its own, yet every wp_options delete also
 * fires `deleted_option` for every other plugin listening (page-cache and CDN
 * purgers among them), and stock hooks fire once per line item — which is
 * how "CTX Feed makes product save slow" reports arise (support #68946).
 *
 * So the product hooks no longer touch wp_options at all. They enqueue ONE
 * async Action Scheduler job (deduplicated: one pending flush covers every
 * save until it runs, and at most one scheduler query per request) that does
 * the deletes on the next queue run. When Action Scheduler is not ready the
 * flush falls back to the old inline delete, so nothing is ever left stale.
 *
 * Admin-only edits that must be visible immediately in the Make-Feed picker
 * (Attribute Mapping / Dynamic Attribute / Category Mapping option writes,
 * global attribute taxonomies, ACF field groups) keep flushing inline — they
 * happen in the admin, rarely, and never inside a shopper's request.
 *
 * @package    CTXFeed
 * @subpackage V8/API
 * @since      8.0.10
 */

namespace CTXFeed\V8\API;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deferred invalidation of the product-derived admin caches.
 *
 * @since 8.0.10
 */
final class ProductCacheInvalidator {

	/**
	 * Async Action Scheduler hook that performs the flush.
	 */
	const ACTION = 'ctxfeed_flush_product_caches';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'ctxfeed';

	/**
	 * Filters-tab product counts transient.
	 */
	const FILTER_COUNTS_TRANSIENT = 'ctxfeed_v8_filter_counts';

	/**
	 * Product change hooks that schedule a deferred flush.
	 *
	 * @var string[]
	 */
	const PRODUCT_HOOKS = array(
		'save_post_product',
		'woocommerce_update_product',
		'woocommerce_new_product',
		'woocommerce_product_set_stock_status',
		'woocommerce_variation_set_stock_status',
	);

	/**
	 * Whether this request already queued (or found) a pending flush.
	 *
	 * @var bool
	 */
	private static $queued_this_request = false;

	/**
	 * Register the product hooks and the async flush handler.
	 *
	 * @since 8.0.10
	 * @return void
	 */
	public static function register(): void {
		add_action( self::ACTION, array( __CLASS__, 'flush_now' ) );

		foreach ( self::PRODUCT_HOOKS as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_product_change' ), 10, 1 );
		}

		// Any post type fires these — gate to products inside the handler.
		add_action( 'deleted_post', array( __CLASS__, 'on_post_removed' ), 10, 1 );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_removed' ), 10, 1 );
	}

	/**
	 * A product was created, saved, or changed stock status.
	 *
	 * @since 8.0.10
	 *
	 * Takes no parameters on purpose: the five hooks pass different first
	 * arguments (post ID, product object, …) and none of them is needed.
	 *
	 * @return void
	 */
	public static function on_product_change(): void {
		// A bulk import fires these on every one of thousands of products;
		// the recompute on the next admin read (or the TTL) picks up the
		// final state, so don't even queue.
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return;
		}

		self::schedule_flush();
	}

	/**
	 * A post was deleted or trashed — only products matter.
	 *
	 * @since 8.0.10
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_post_removed( $post_id ): void {
		$type = get_post_type( $post_id );
		if ( 'product' !== $type && 'product_variation' !== $type ) {
			return;
		}

		self::on_product_change();
	}

	/**
	 * Queue one async flush, or flush inline when the scheduler is unavailable.
	 *
	 * @since 8.0.10
	 *
	 * @return bool True when the flush was deferred, false when done inline.
	 */
	public static function schedule_flush(): bool {
		if ( ! self::scheduler_ready() ) {
			self::flush_now();
			return false;
		}

		// One scheduler round-trip per request at most.
		if ( self::$queued_this_request ) {
			return true;
		}
		self::$queued_this_request = true;

		// A pending flush already covers this change.
		if ( as_has_scheduled_action( self::ACTION, array(), self::GROUP ) ) {
			return true;
		}

		as_enqueue_async_action( self::ACTION, array(), self::GROUP );

		return true;
	}

	/**
	 * Drop both product-derived caches immediately.
	 *
	 * @since 8.0.10
	 * @return void
	 */
	public static function flush_now(): void {
		delete_transient( self::FILTER_COUNTS_TRANSIENT );
		ProductEndpoint::flush_mapping_attributes_cache();
	}

	/**
	 * Action Scheduler present AND its data store initialised.
	 *
	 * `action_scheduler_init` fires once the store is ready; calling as_*()
	 * before that logs a notice and triggers the just-in-time textdomain
	 * warning. Filterable so a host that cannot run the queue can force the
	 * inline path.
	 *
	 * @since 8.0.10
	 * @return bool
	 */
	private static function scheduler_ready(): bool {
		$ready = function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_has_scheduled_action' )
			&& did_action( 'action_scheduler_init' ) > 0;

		/**
		 * Filter whether product-cache flushes are deferred to Action Scheduler.
		 *
		 * @since 8.0.10
		 *
		 * @param bool $ready True to defer, false to flush inline on the hook.
		 */
		return (bool) apply_filters( 'ctxfeed_defer_product_cache_flush', $ready );
	}

	/**
	 * Reset the per-request memo (tests / long-running CLI loops).
	 *
	 * @since 8.0.10
	 * @return void
	 */
	public static function reset_request_state(): void {
		self::$queued_this_request = false;
	}
}
