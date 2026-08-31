<?php
/**
 * FeedScheduler — Background feed generation via WooCommerce Action Scheduler.
 *
 * Uses offset-based pagination (AD-FEED-003) instead of loading all product
 * IDs into memory. Each batch gets its own request with fresh 30-sec PHP
 * execution window. User doesn't need to keep browser open.
 *
 * Batch sizes are auto-calculated by BatchCalculator based on the server's
 * memory_limit and max_execution_time, then adapted after each batch using
 * actual performance measurements. The `ctxfeed_batch_size` filter can
 * override the auto-calculated value for power users.
 *
 * @package    CTXFeed
 * @subpackage V8/Feed
 * @since      8.0.0
 * @implements FEED-FRD-3.1, FEED-FRD-3.2, FEED-FRD-3.3, FEED-FRD-3.4, FEED-FRD-3.5, FEED-FRD-3.6, FEED-FRD-11.1, FEED-FRD-11.3, FEED-FRD-11.6, FEED-FRD-11.7
 */

namespace CTXFeed\V8\Feed;

use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Product\ProductQuery;
use CTXFeed\V8\Utility\FeedLogger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action Scheduler integration for feed generation.
 *
 * @since 8.0.0
 */
class FeedScheduler {

	/**
	 * Action Scheduler action name for batch generation.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const GENERATE_ACTION = 'ctxfeed_generate_batch';

	/**
	 * Action Scheduler action name for feed finalization.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const FINALIZE_ACTION = 'ctxfeed_finalize_feed';

	/**
	 * Action Scheduler action name for recurring (auto-update) trigger.
	 *
	 * When this fires, handle_recurring() loads the feed config and calls
	 * schedule_generation() — which auto-calculates a fresh batch size
	 * for each run because server conditions may have changed.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const RECURRING_ACTION = 'ctxfeed_recurring_generation';

	/**
	 * Action Scheduler group name.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const GROUP = 'ctxfeed';

	/**
	 * Feed generator instance (injected via set_generator).
	 *
	 * @since 8.0.0
	 * @var FeedGenerator|null
	 */
	private $generator;

	/**
	 * Batch calculator instance (injected via set_batch_calculator).
	 *
	 * @since 8.0.0
	 * @var BatchCalculator|null
	 */
	private $batch_calculator;

	/**
	 * Feed manager instance (injected via set_manager).
	 *
	 * Used by handle_recurring() to load feed config for auto-update runs.
	 *
	 * @since 8.0.0
	 * @var FeedManager|null
	 */
	private $manager;

	/**
	 * Per-feed buffered logger (injected via set_feed_logger).
	 *
	 * @since 8.0.0
	 * @var FeedLogger|null
	 */
	private $feed_logger;

	/**
	 * Set the per-feed logger instance.
	 *
	 * @since 8.0.0
	 *
	 * @param FeedLogger $feed_logger Per-feed logger.
	 *
	 * @return void
	 */
	public function set_feed_logger( FeedLogger $feed_logger ): void {
		$this->feed_logger = $feed_logger;
	}

	/**
	 * Set the feed generator instance.
	 *
	 * @since 8.0.0
	 *
	 * @param FeedGenerator $generator Feed generator.
	 *
	 * @return void
	 */
	public function set_generator( FeedGenerator $generator ): void {
		$this->generator = $generator;
	}

	/**
	 * Set the batch calculator instance.
	 *
	 * @since 8.0.0
	 *
	 * @param BatchCalculator $batch_calculator Batch calculator.
	 *
	 * @return void
	 */
	public function set_batch_calculator( BatchCalculator $batch_calculator ): void {
		$this->batch_calculator = $batch_calculator;
	}

	/**
	 * Set the feed manager instance.
	 *
	 * Required for handle_recurring() to load feed config when
	 * the auto-update recurring action fires.
	 *
	 * @since 8.0.0
	 *
	 * @param FeedManager $manager Feed manager.
	 *
	 * @return void
	 */
	public function set_manager( FeedManager $manager ): void {
		$this->manager = $manager;
	}

	/**
	 * Schedule feed generation using offset-based pagination.
	 *
	 * Counts total products via ProductQuery::count() (single integer).
	 * Auto-calculates batch size using BatchCalculator::calculate_initial()
	 * based on the server's memory_limit and max_execution_time.
	 * Schedules only the first batch at offset 0. Subsequent batches are
	 * chained via schedule_next_or_finalize() per AD-FEED-003.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.1, FEED-FRD-3.6, FEED-FRD-11.1
	 * @hook ctxfeed_batch_size Filter to override auto-calculated batch size.
	 *
	 * @param string $feed_name Feed slug identifier.
	 * @param Config $config    Feed configuration object.
	 *
	 * @return true|\WP_Error True on success, WP_Error if generation lock held by another feed.
	 *
	 * @throws \Throwable Re-thrown from the synchronous fast-path so REST callers receive the real generation error.
	 */
	public function schedule_generation( string $feed_name, Config $config ) {
		// Acquire generation lock before doing any work. @implements FEED-FRD-3.6.
		if ( $this->batch_calculator ) {
			if ( ! $this->batch_calculator->acquire_lock( $feed_name ) ) {
				$lock_holder = $this->batch_calculator->get_lock_holder();

				Logger::info( "Generation blocked — lock held by {$lock_holder}: {$feed_name}" );

				return new \WP_Error(
					'ctxfeed_generation_locked',
					sprintf(
						/* translators: %s: feed name that currently holds the generation lock */
						__( 'Another feed (%s) is currently generating. Please wait until it completes and try again.', 'woo-feed' ),
						$lock_holder
					),
					array(
						'status'      => 409, // HTTP Conflict.
						'lock_holder' => $lock_holder,
					)
				);
			}
		}

		// Everything after the lock is acquired runs inside run_generation(), so
		// a throw ANYWHERE — the product-count query, batch sizing, the
		// synchronous generation, or Action Scheduler scheduling — is caught
		// here and ALWAYS releases the site-wide generation lock. Without this,
		// a failure before the sync try-block would leave the lock stuck for its
		// full 600s TTL and reject every other feed as already-locked.
		try {
			return $this->run_generation( $feed_name, $config );
		} catch ( \Throwable $e ) {
			if ( $this->batch_calculator ) {
				// Ownership-scoped: a safe no-op if the lock was already freed.
				$this->batch_calculator->release_lock( $feed_name );
			}
			$manager = $this->manager ? $this->manager : new FeedManager();
			$manager->update_progress( $feed_name, array( 'status' => 'failed' ) );

			// Re-throw so the REST caller still gets the real error message.
			throw $e;
		}
	}

	/**
	 * Resolve the product set, then either run the synchronous fast-path or
	 * schedule the async batches.
	 *
	 * Split out of schedule_generation() so the caller's try/catch releases the
	 * generation lock on ANY failure here — not just a crash inside the
	 * synchronous generation. The generation lock must already be held.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed slug identifier.
	 * @param Config $config    Feed configuration.
	 *
	 * @return bool True once generation has run (sync) or been scheduled (async).
	 * @throws \Throwable On any product-resolution or synchronous-generation
	 *                    failure; the caller releases the lock and re-throws.
	 */
	private function run_generation( string $feed_name, Config $config ): bool {
		$query = new ProductQuery();
		// Bind the query to this feed so get_total_count() persists the
		// product-ID list as a snapshot. Subsequent batches reuse the same
		// snapshot — without this binding, a 200K-product feed re-runs
		// the same WC_Product_Query 400+ times.
		$query->set_feed_name( $feed_name );
		// Drop any stale snapshot from a previous run before resolving.
		$query->clear_snapshot( $feed_name );
		$total = $query->get_total_count( $config );

		// Auto-calculate initial batch size based on server limits. @implements FEED-FRD-11.1.
		$auto_batch_size = 200; // Fallback if no BatchCalculator.
		if ( $this->batch_calculator ) {
			$auto_batch_size = $this->batch_calculator->calculate_initial();
		}

		/**
		 * Filter the batch size for feed generation.
		 *
		 * Default is auto-calculated from server memory_limit and
		 * max_execution_time via BatchCalculator. Return a fixed value
		 * to override adaptive sizing.
		 *
		 * @since 8.0.0
		 *
		 * @param int    $batch_size Auto-calculated batch size.
		 * @param string $feed_name  Feed slug identifier.
		 */
		$batch_size = apply_filters( 'ctxfeed_batch_size', $auto_batch_size, $feed_name );

		$batches_total = ( $batch_size > 0 && $total > 0 ) ? (int) ceil( $total / $batch_size ) : 1;
		$env_profile   = $this->batch_calculator ? $this->batch_calculator->get_environment_profile() : 'unknown';

		Logger::info(
			'Scheduling feed generation',
			array(
				'feed_name'   => $feed_name,
				'total'       => $total,
				'batch_size'  => $batch_size,
				'batches_est' => $batches_total,
				'env_profile' => $env_profile,
			) 
		);

		// Initialize per-feed log file.
		if ( $this->feed_logger ) {
			$this->feed_logger->init(
				$feed_name,
				array(
					'total_products' => $total,
					'batch_size'     => $batch_size,
					'batches'        => $batches_total,
					'env_profile'    => $env_profile,
				) 
			);
		}

		// Set initial enriched progress. @implements FEED-FRD-10.1.
		$manager = $this->manager ? $this->manager : new FeedManager();
		$manager->update_progress(
			$feed_name,
			array(
				'current'       => 0,
				'total'         => $total,
				'status'        => 'generating',
				'batch_size'    => $batch_size,
				'batches_done'  => 0,
				'batches_total' => $batches_total,
			) 
		);

		// Synchronous fast-path: if all products fit in one batch, process
		// immediately in the current request instead of deferring to Action
		// Scheduler. This avoids WP Cron / loopback dependencies and makes
		// small feeds (e.g. 38 products) complete in seconds.
		if ( $total <= $batch_size && $this->generator ) {
			Logger::info( "Synchronous generation — {$total} products fit in one batch: {$feed_name}" );

			try {
				$this->generator->process_batch(
					$feed_name,
					0,
					$batch_size,
					array(
						'total' => $total,
					) 
				);

				// Finalize immediately.
				$manager->update_progress(
					$feed_name,
					array(
						'current' => $total,
						'total'   => $total,
						'status'  => 'finalizing',
					) 
				);
				$this->generator->finalize( $feed_name );

				Logger::info( "Synchronous generation complete: {$feed_name}" );
			} catch ( \Throwable $e ) {
				Logger::error(
					"Synchronous generation failed: {$feed_name}",
					array(
						'error' => $e->getMessage(),
						'file'  => $e->getFile() . ':' . $e->getLine(),
					) 
				);

				if ( $this->feed_logger ) {
					$this->feed_logger->error( $feed_name, 'Generation failed: ' . $e->getMessage() );
					$this->feed_logger->flush( $feed_name );
				}

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate PHP-error-log breadcrumb for fatal generation failures: it must reach the server log even when the plugin's own loggers are broken or unflushed.
				error_log( '[CTXFeed V8] Sync generation error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );

				// Re-throw: schedule_generation()'s wrapping catch marks the feed
				// failed AND releases the site-wide generation lock (whether the
				// crash was here, in the product-count query, or in scheduling),
				// so a failure never leaves the lock stuck for its 600s TTL.
				throw $e;
			}

			return true;
		}

		// Async path: schedule via Action Scheduler for large catalogs.
		// @implements FEED-FRD-3.1.
		as_schedule_single_action(
			time(),
			self::GENERATE_ACTION,
			array(
				'feed_name'  => $feed_name,
				'offset'     => 0,
				'batch_size' => $batch_size,
				'total'      => $total,
			),
			self::GROUP 
		);

		// Nudge Action Scheduler to process the queue immediately.
		// Without this, the batch sits in the queue until WP Cron fires
		// (which depends on a page visit on many hosts).
		$this->dispatch_runner();

		return true;
	}

	/**
	 * Schedule the next batch or finalization.
	 *
	 * Called after each batch completes. If more products remain, schedules
	 * next batch at offset + current_batch_size with the adaptive
	 * next_batch_size from BatchCalculator. Otherwise, schedules finalization.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.2, FEED-FRD-11.3
	 *
	 * @param string $feed_name       Feed slug identifier.
	 * @param int    $offset          Current offset.
	 * @param int    $batch_size      Batch size used for this batch (for offset calculation).
	 * @param int    $total           Total product count.
	 * @param int    $next_batch_size Adaptive batch size for the next batch (from BatchCalculator).
	 *
	 * @return void
	 */
	public function schedule_next_or_finalize( string $feed_name, int $offset, int $batch_size, int $total, int $next_batch_size = 0 ): void {
		$next_offset = $offset + $batch_size;

		// Use adaptive size if provided, otherwise keep current. @implements FEED-FRD-11.3.
		if ( 0 === $next_batch_size ) {
			$next_batch_size = $batch_size;
		}

		if ( $next_offset < $total ) {
			// Schedule next batch with adaptive batch size.
			as_schedule_single_action(
				time(),
				self::GENERATE_ACTION,
				array(
					'feed_name'  => $feed_name,
					'offset'     => $next_offset,
					'batch_size' => $next_batch_size,
					'total'      => $total,
				),
				self::GROUP 
			);

			// Nudge runner so the next batch processes without waiting for cron.
			$this->dispatch_runner();
		} else {
			// Schedule finalization with 5-second delay for batch completion.
			as_schedule_single_action(
				time() + 5,
				self::FINALIZE_ACTION,
				array(
					'feed_name' => $feed_name,
					'total'     => $total,
				),
				self::GROUP 
			);

			// Nudge runner for finalization action.
			$this->dispatch_runner();
		}
	}

	/**
	 * Schedule recurring feed generation (auto-update).
	 *
	 * Schedules a recurring action via `as_schedule_recurring_action()`.
	 * When the action fires, handle_recurring() loads the feed config
	 * and calls schedule_generation() — which auto-calculates a fresh
	 * batch size for each run because server conditions may have changed.
	 *
	 * Applies automatic staggering: each feed gets a unique offset based
	 * on its name hash, so 5 feeds with the same interval don't all fire
	 * at the exact same second. Maximum stagger is 5 minutes.
	 *
	 * Interval is read from feed config `update_interval` key (seconds).
	 * Common values: 3600 (1h), 21600 (6h), 43200 (12h), 86400 (24h).
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.3
	 * @hook ctxfeed_recurring_generation Recurring auto-update trigger.
	 *
	 * @param string $feed_name Feed slug identifier.
	 * @param int    $interval  Interval in seconds between generations.
	 *
	 * @return void
	 */
	public function schedule_recurring( string $feed_name, int $interval ): void {
		// Cancel any existing recurring schedule for this feed first.
		as_unschedule_all_actions( self::RECURRING_ACTION, array( 'feed_name' => $feed_name ), self::GROUP );

		// Stagger: spread feeds across a 5-minute window using name hash.
		// This prevents 5 feeds with the same interval from all firing
		// at the exact same second and overwhelming the server.
		$stagger_window = 300; // 5 minutes max stagger.
		$stagger_offset = absint( crc32( $feed_name ) ) % $stagger_window;

		$first_run = time() + $interval + $stagger_offset;

		as_schedule_recurring_action(
			$first_run,
			$interval,
			self::RECURRING_ACTION,
			array(
				'feed_name' => $feed_name,
			),
			self::GROUP 
		);

		Logger::info(
			'Scheduled recurring feed generation',
			array(
				'feed_name'      => $feed_name,
				'interval'       => $interval,
				'stagger_offset' => $stagger_offset,
			) 
		);
	}

	/**
	 * Cancel all pending batches and recurring schedules for a feed.
	 *
	 * Unschedules batch generation actions, finalization actions, and
	 * recurring auto-update actions for the given feed.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.4
	 *
	 * @param string $feed_name Feed slug identifier.
	 *
	 * @return void
	 */
	public function cancel( string $feed_name ): void {
		as_unschedule_all_actions( self::GENERATE_ACTION, array( 'feed_name' => $feed_name ), self::GROUP );
		as_unschedule_all_actions( self::FINALIZE_ACTION, array( 'feed_name' => $feed_name ), self::GROUP );
		as_unschedule_all_actions( self::RECURRING_ACTION, array( 'feed_name' => $feed_name ), self::GROUP );

		// Release generation lock and clear batch history.
		if ( $this->batch_calculator ) {
			$this->batch_calculator->release_lock( $feed_name );
			$this->batch_calculator->clear_history( $feed_name );
		}

		// Drop the product-ID snapshot transient — next run rebuilds it.
		// We use a fresh ProductQuery binding rather than the injected one
		// because cancel() is called from contexts that may not have run
		// FeedGenerator (e.g. delete_feed before any generation).
		$query = new ProductQuery();
		$query->clear_snapshot( $feed_name );
	}

	/**
	 * Nudge Action Scheduler to process pending actions immediately.
	 *
	 * Default behaviour (matches what users expect from a "Generate" click):
	 *   - Fire `spawn_cron()` to kick the WP-Cron loopback.
	 *
	 * That's it. The synchronous `ActionScheduler_QueueRunner::run()`
	 * fallback used to run on every dispatch, which blocked the HTTP
	 * response while AS chewed through the first 1–3 batches in the
	 * request thread (30+ seconds on shared hosts, occasionally
	 * tripping nginx's 504 timeout on the user's "Generate" click).
	 *
	 * The synchronous run is now opt-in for environments where wp-cron
	 * loopbacks don't work (containers with restricted networking,
	 * sites with `DISABLE_WP_CRON`, etc.). It's selected in two ways:
	 *
	 *   1. `define( 'DISABLE_WP_CRON', true )` in wp-config.php — the
	 *      WP install has explicitly opted out of cron, so spawn_cron()
	 *      is a no-op. Sync fallback is the only way to make progress.
	 *   2. `define( 'CTXFEED_DISPATCH_SYNC_RUN', true )` — escape hatch
	 *      for users on cron-broken hosts who don't want to globally
	 *      disable WP-Cron.
	 *
	 * Either gate ⇒ sync run. Otherwise async only ⇒ HTTP returns fast
	 * and AS picks up the queue on the next cron tick (typically 1–10s
	 * later via the loopback we just spawned).
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function dispatch_runner(): void {
		// Always nudge async — non-blocking loopback request to wp-cron.
		spawn_cron();

		// Decide whether to also run AS synchronously.
		$sync_required = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
			|| ( defined( 'CTXFEED_DISPATCH_SYNC_RUN' ) && CTXFEED_DISPATCH_SYNC_RUN );

		/**
		 * Filter whether to synchronously run Action Scheduler in the
		 * current request after enqueuing a batch.
		 *
		 * Default behaviour relies on the spawned wp-cron loopback. If
		 * a host has reliable cron, leave this alone — sync running adds
		 * 10–30s of latency to the user's "Generate" click for no
		 * perceptible benefit. Enable only when cron is broken.
		 *
		 * @since 8.0.0
		 *
		 * @param bool $sync_required Whether to run AS synchronously.
		 */
		$sync_required = (bool) apply_filters( 'ctxfeed_dispatch_sync_run', $sync_required );

		if ( ! $sync_required ) {
			return;
		}

		if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
			try {
				\ActionScheduler_QueueRunner::instance()->run();
			} catch ( \Exception $e ) {
				// Non-fatal: batch will still process on next cron tick.
				Logger::debug( 'Direct ActionScheduler run failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Cancel only the recurring (auto-update) schedule for a feed.
	 *
	 * Preserves any in-progress batch generation. Use this when the user
	 * disables auto-update but wants to keep a currently running generation.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed slug identifier.
	 *
	 * @return void
	 */
	public function cancel_recurring( string $feed_name ): void {
		as_unschedule_all_actions( self::RECURRING_ACTION, array( 'feed_name' => $feed_name ), self::GROUP );
	}

	/**
	 * Reconcile a feed's recurring schedule with its stored auto-update state.
	 *
	 * Idempotent. Reads `$feed_data['status']` (V5-compat: 1 = auto-update on,
	 * 0 = off) and resolves the interval via {@see resolve_interval()}. When
	 * the feed is enabled with a usable interval the recurring action is
	 * registered (or refreshed); otherwise any existing recurring action is
	 * cancelled. Safe to call repeatedly — each call replaces the prior
	 * recurring entry rather than appending.
	 *
	 * Call this from every code path that writes `wf_feed_{slug}` or flips
	 * `status` so the Action Scheduler queue stays in sync with what the
	 * user sees in the UI.
	 *
	 * Storage compatibility (per the 70K-install constraint): no new schema,
	 * no new wp_options keys. Reads the same `status` flag V5 has always used.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.3
	 *
	 * @param string $feed_name Feed slug identifier.
	 * @param array  $feed_data Stored `wf_feed_{slug}` data (with `status`,
	 *                          `feedrules`, `url`, etc.). Plain array, not Config.
	 *
	 * @return string One of: 'scheduled' (recurring registered), 'cancelled'
	 *                (recurring removed because status=0 or interval=0),
	 *                'unchanged' (no recurring needed and none existed).
	 */
	public function sync_recurring_schedule( string $feed_name, array $feed_data ): string {
		$status = isset( $feed_data['status'] ) ? (int) $feed_data['status'] : 0;

		if ( 1 !== $status ) {
			$this->cancel_recurring( $feed_name );
			return 'cancelled';
		}

		$interval = $this->resolve_interval( $feed_data );

		if ( $interval <= 0 ) {
			// Auto-update is on but no interval is configured — leave the
			// queue empty rather than spam every-second runs.
			$this->cancel_recurring( $feed_name );
			return 'cancelled';
		}

		$this->schedule_recurring( $feed_name, $interval );
		return 'scheduled';
	}

	/**
	 * Resolve the auto-update interval (seconds) for a feed.
	 *
	 * Resolution order matches the 70K-install storage layout — no new keys
	 * required, but a per-feed override is honoured if a future UI sets it:
	 *
	 *   1. `feedrules.update_interval` — per-feed V8 override, seconds (optional).
	 *   1b.`feedrules.cron`            — per-feed "Update interval" the Make-Feed
	 *                                     UI actually writes, in WHOLE HOURS
	 *                                     ('1'..'168'); converted hours→seconds.
	 *   2. `wp_options.wf_schedule`     — global V5-compat option (set to
	 *                                     HOUR_IN_SECONDS by the V5 installer
	 *                                     on activation).
	 *   3. Filtered default              — `ctxfeed_default_update_interval`,
	 *                                     defaults to HOUR_IN_SECONDS to match
	 *                                     V5's `get_feed_cron_interval()` floor.
	 *
	 * @since 8.0.0
	 *
	 * @param array $feed_data Stored feed data (`status`, `feedrules`, ...).
	 *
	 * @return int Interval in seconds, or 0 to disable.
	 */
	private function resolve_interval( array $feed_data ): int {
		$rules = isset( $feed_data['feedrules'] ) && is_array( $feed_data['feedrules'] )
			? $feed_data['feedrules']
			: array();

		// 1. Per-feed override (V8-native, seconds). Written by nothing today,
		// but honoured first if a future UI sets it.
		if ( isset( $rules['update_interval'] ) ) {
			$per_feed = (int) $rules['update_interval'];
			if ( $per_feed > 0 ) {
				return $per_feed;
			}
		}

		// 1b. Per-feed "Update interval" from the Make-Feed UI. That control
		// persists its choice into `feedrules.cron` as a WHOLE-HOURS code
		// ('1','6','12','24','168') — see FeedEndpoint::build_feedrules_from_form()
		// ('cron' <= intervalTime). Since `update_interval` above is never
		// populated, `cron` is what actually carries the per-feed value; without
		// reading it here every feed silently fell back to the single global
		// `wf_schedule` (1h). Convert hours to seconds.
		if ( isset( $rules['cron'] ) && is_numeric( $rules['cron'] ) && (int) $rules['cron'] > 0 ) {
			return (int) $rules['cron'] * HOUR_IN_SECONDS;
		}

		// 2. Global V5-compat option.
		$global = (int) get_option( 'wf_schedule', 0 );
		if ( $global > 0 ) {
			return $global;
		}

		/**
		 * Filter the default auto-update interval (seconds) when nothing
		 * else is configured. Defaults to HOUR_IN_SECONDS — same fallback
		 * V5's CronHelper::get_feed_cron_interval() used.
		 *
		 * @since 8.0.0
		 *
		 * @param int $default_interval Default interval in seconds.
		 */
		return (int) apply_filters( 'ctxfeed_default_update_interval', HOUR_IN_SECONDS );
	}

	/**
	 * Reconcile recurring schedules for every stored feed.
	 *
	 * Iterates every `wf_feed_*` option and calls
	 * {@see sync_recurring_schedule()} per feed. Used by the one-shot
	 * backfill during V8 boot (so existing 70K-install feeds get their
	 * recurring action registered without a manual toggle), and by the
	 * Migrator after a V5→V8 cutover.
	 *
	 * @since 8.0.0
	 *
	 * @param FeedManager|null $manager Optional FeedManager. Defaults to a
	 *                                  fresh instance — this method is
	 *                                  callable from contexts where the DI
	 *                                  container isn't available (Action
	 *                                  Scheduler workers, CLI, migration).
	 *
	 * @return array<string,string[]> Map with keys 'scheduled', 'cancelled' —
	 *                                arrays of feed names per outcome.
	 */
	public function sync_all_recurring_schedules( ?FeedManager $manager = null ): array {
		if ( null === $manager ) {
			$manager = new FeedManager();
		}

		$results = array(
			'scheduled' => array(),
			'cancelled' => array(),
		);

		foreach ( $manager->get_all_feed_names() as $feed_name ) {
			$feed_data = maybe_unserialize( get_option( 'wf_feed_' . $feed_name ) );
			if ( ! is_array( $feed_data ) ) {
				continue;
			}

			$outcome = $this->sync_recurring_schedule( $feed_name, $feed_data );

			if ( 'scheduled' === $outcome ) {
				$results['scheduled'][] = $feed_name;
			} else {
				$results['cancelled'][] = $feed_name;
			}
		}

		return $results;
	}

	/**
	 * Boot: Register Action Scheduler callbacks.
	 *
	 * Registers handlers for three action types:
	 * - GENERATE_ACTION (4 params): Individual batch processing.
	 * - FINALIZE_ACTION (2 params): Feed finalization after all batches.
	 * - RECURRING_ACTION (1 param): Auto-update trigger that kicks off
	 *   a fresh schedule_generation() cycle.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.5
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( self::GENERATE_ACTION, array( $this, 'handle_batch' ), 10, 4 );
		add_action( self::FINALIZE_ACTION, array( $this, 'handle_finalization' ), 10, 2 );
		add_action( self::RECURRING_ACTION, array( $this, 'handle_recurring' ), 10, 1 );

		// Re-home V5 WP-Cron feed schedules onto Action Scheduler after a plugin
		// UPDATE (fired once by CTXFeed_Installer::check_version on the upgrade
		// request). Without this, feeds upgraded from V5 silently stop
		// auto-generating because their old WP-Cron events are never reconciled.
		add_action( 'woo_feed_plugin_updated', array( $this, 'reconcile_after_upgrade' ) );
	}

	/**
	 * Reconcile scheduling after a plugin UPDATE (not a fresh activation).
	 *
	 * A wp.org update loads new code WITHOUT firing the activation hook, so a
	 * site upgrading from V5 keeps its old WP-Cron feed events while V8 has
	 * registered nothing in Action Scheduler — every existing feed silently
	 * stops auto-generating. This clears the stale V5 WP-Cron events and
	 * re-registers the active feeds as Action Scheduler recurring actions.
	 * Idempotent — safe to run on every upgrade.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function reconcile_after_upgrade(): void {
		$this->clear_legacy_wp_cron_events();
		$this->sync_all_recurring_schedules( $this->manager );
	}

	/**
	 * Remove stale V5 WP-Cron feed events left by a pre-8.0 install.
	 *
	 * Clears the fixed legacy hooks plus every dynamic per-feed / sub-batch
	 * event (`woo_feed_update_*`, `wf_store_auto_feed_body_info_*`) so they can
	 * never fire into now-removed V5 callbacks. Action Scheduler is the sole
	 * scheduler under V8.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function clear_legacy_wp_cron_events(): void {
		if ( ! function_exists( 'wp_unschedule_hook' ) || ! function_exists( '_get_cron_array' ) ) {
			return;
		}

		// Fixed legacy hook names, plus any dynamic per-feed hook discovered
		// in the cron array (deduped so wp_unschedule_hook runs once each).
		$hooks = array(
			'woo_feed_update'             => true,
			'woo_feed_update_single_feed' => true,
		);

		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				foreach ( array_keys( $events ) as $hook ) {
					$hook = (string) $hook;
					if ( 0 === strpos( $hook, 'woo_feed_update_' ) || 0 === strpos( $hook, 'wf_store_auto_feed_body_info_' ) ) {
						$hooks[ $hook ] = true;
					}
				}
			}
		}

		foreach ( array_keys( $hooks ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Handle recurring auto-update trigger (called by Action Scheduler).
	 *
	 * Loads the feed config from FeedManager and calls schedule_generation()
	 * which auto-calculates a fresh batch size via BatchCalculator. This
	 * ensures each auto-update run adapts to current server conditions.
	 *
	 * Three guards prevent contention when multiple feeds share an interval:
	 * 1. Self-overlap guard: skips if THIS feed is already generating.
	 * 2. Global generation lock: only one feed generates at a time to prevent
	 *    memory contention. Other feeds re-queue themselves with a 60-sec delay.
	 * 3. Config guard: skips if feed config not found.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.3
	 *
	 * @param string $feed_name Feed slug identifier.
	 *
	 * @return void
	 */
	public function handle_recurring( string $feed_name ): void {
		$manager = $this->manager ? $this->manager : new FeedManager();

		// Guard 1: Skip if THIS feed is already generating (self-overlap).
		$progress = $manager->get_progress( $feed_name );
		if ( in_array( $progress['status'], array( 'generating', 'finalizing' ), true ) ) {
			Logger::info( "Skipping recurring generation — already in progress: {$feed_name}" );
			return;
		}

		// Guard 2: Global lock — only one feed generates at a time.
		// Prevents 5 feeds from running batches simultaneously and
		// competing for the same server memory on shared hosting.
		if ( $this->batch_calculator ) {
			if ( ! $this->batch_calculator->acquire_lock( $feed_name ) ) {
				$lock_holder = $this->batch_calculator->get_lock_holder();
				Logger::info( "Generation queued — lock held by {$lock_holder}: {$feed_name}" );

				// Re-queue: try again in 60 seconds.
				as_schedule_single_action(
					time() + 60,
					self::RECURRING_ACTION,
					array(
						'feed_name' => $feed_name,
					),
					self::GROUP 
				);

				return;
			}
		}

		// Guard 3: Config must exist.
		$config = $manager->get_config( $feed_name );
		if ( ! $config ) {
			Logger::error( "Recurring generation failed — config not found: {$feed_name}" );

			// Release lock since we can't proceed.
			if ( $this->batch_calculator ) {
				$this->batch_calculator->release_lock( $feed_name );
			}
			return;
		}

		Logger::info( "Recurring auto-update triggered: {$feed_name}" );

		// Start a fresh generation cycle with auto-calculated batch size.
		$this->schedule_generation( $feed_name, $config );
	}

	/**
	 * Handle a single batch (called by Action Scheduler).
	 *
	 * Delegates to FeedGenerator::process_batch() which returns the
	 * adaptive next_batch_size from BatchCalculator. Passes this to
	 * schedule_next_or_finalize() for chaining.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.5, FEED-FRD-11.3
	 *
	 * @param string $feed_name  Feed slug identifier.
	 * @param int    $offset     Product offset.
	 * @param int    $batch_size Batch size.
	 * @param int    $total      Total product count.
	 *
	 * @return void
	 */
	public function handle_batch( string $feed_name, int $offset, int $batch_size, int $total ): void {
		if ( ! $this->generator ) {
			Logger::error( 'FeedGenerator not set in FeedScheduler' );
			return;
		}

		// Refresh the generation lock TTL so it doesn't expire mid-generation
		// on large catalogs with many batches (e.g., 100K products / 100 batch = 1000 batches).
		if ( $this->batch_calculator ) {
			$this->batch_calculator->acquire_lock( $feed_name );
		}

		try {
			// process_batch returns adaptive next_batch_size. @implements FEED-FRD-11.3.
			$result = $this->generator->process_batch(
				$feed_name,
				$offset,
				$batch_size,
				array(
					'total' => $total,
				)
			);

			$next_batch_size = isset( $result['next_batch_size'] ) ? (int) $result['next_batch_size'] : $batch_size;

			// Chain next batch or finalize with adaptive batch size. @implements FEED-FRD-3.2.
			$this->schedule_next_or_finalize( $feed_name, $offset, $batch_size, $total, $next_batch_size );
		} catch ( \Throwable $e ) {
			// A FATAL batch-level error (config load, cache warm, file open, or
			// an unexpected engine bug — NOT a single malformed product, which
			// process_batch isolates and skips). Without this catch the throw
			// would skip schedule_next_or_finalize: the chain dies, the next
			// batch is never queued, status stays 'generating', and the
			// generation lock is never released — the feed silently stalls
			// until the lock TTL expires. Surface it instead.
			$this->fail_generation( $feed_name, $offset, $e );
		}
	}

	/**
	 * Record a fatal batch failure and tear down the generation run.
	 *
	 * Mirrors the synchronous fast-path's failure handling
	 * ({@see schedule_generation()}) for the async, multi-batch path: logs the
	 * error, marks the feed 'failed', and releases the generation lock so a
	 * later run (or a second feed) isn't blocked. Does NOT re-throw — this runs
	 * inside an Action Scheduler callback and the failure is already recorded;
	 * re-throwing would only risk an AS retry storm on a deterministic error.
	 *
	 * @since 8.0.0
	 *
	 * @param string     $feed_name Feed slug identifier.
	 * @param int        $offset    Offset of the batch that failed.
	 * @param \Throwable $e         The fatal error.
	 *
	 * @return void
	 */
	private function fail_generation( string $feed_name, int $offset, \Throwable $e ): void {
		Logger::error(
			"Feed batch failed: {$feed_name}",
			array(
				'offset' => $offset,
				'error'  => $e->getMessage(),
				'file'   => $e->getFile() . ':' . $e->getLine(),
			)
		);

		if ( $this->feed_logger ) {
			$this->feed_logger->error( $feed_name, sprintf( 'Batch at offset %d failed: %s', $offset, $e->getMessage() ) );
			$this->feed_logger->flush( $feed_name );
		}

		$manager = $this->manager ? $this->manager : new FeedManager();
		$manager->update_progress( $feed_name, array( 'status' => 'failed' ) );

		if ( $this->batch_calculator ) {
			$this->batch_calculator->release_lock( $feed_name );
		}
	}

	/**
	 * Handle feed finalization (called by Action Scheduler).
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.5
	 *
	 * @param string $feed_name Feed slug identifier.
	 * @param int    $total     Total product count.
	 *
	 * @return void
	 */
	public function handle_finalization( string $feed_name, int $total ): void {
		if ( ! $this->generator ) {
			Logger::error( 'FeedGenerator not set in FeedScheduler' );
			return;
		}

		// Update status to finalizing.
		$manager = new FeedManager();
		$manager->update_progress(
			$feed_name,
			array(
				'current' => $total,
				'total'   => $total,
				'status'  => 'finalizing',
			) 
		);

		try {
			$this->generator->finalize( $feed_name );
		} catch ( \Throwable $e ) {
			// FeedGenerator::finalize() releases the global generation lock only
			// at its very end; a throw before that (open_append / write_footer /
			// promote) would otherwise leave the site-wide lock stuck for its
			// full 600s TTL, blocking every other feed. Route the failure through
			// fail_generation() — the same handler the batch path uses — so the
			// lock is released and the feed marked failed. It deliberately does
			// NOT re-throw (this runs in the Action Scheduler worker, where a
			// re-throw would only trigger a retry storm on a deterministic error).
			$this->fail_generation( $feed_name, 0, $e );
			return;
		}

		Logger::info( "Feed finalized: {$feed_name}" );
	}
}
