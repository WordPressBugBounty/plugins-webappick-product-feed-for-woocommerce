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
use CTXFeed\V8\Utility\Filesystem;

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
	 * How many times a single batch may be retried with a smaller size before
	 * the generation gives up. Halving from the 2000 ceiling reaches the 25
	 * floor in ~7 steps, so the default lets it walk most of the way down.
	 * Filterable via `ctxfeed_batch_max_retries`.
	 *
	 * @since 8.0.7
	 * @var int
	 */
	const MAX_BATCH_RETRIES = 5;

	/**
	 * Feed generator instance (injected via set_generator).
	 *
	 * @since 8.0.0
	 * @var FeedGenerator|null
	 */
	private $generator;

	/**
	 * The batch currently being processed, for the fatal-recovery shutdown
	 * guard. Set at the start of handle_batch(), cleared once the batch
	 * completes or its exception is caught. If it is still set at shutdown, the
	 * request died mid-batch on an UNCATCHABLE fatal (a max-execution-time or
	 * memory-limit "batch too big") — the guard then reschedules the SAME offset
	 * with a halved batch size. Null when no batch is in flight.
	 *
	 * @since 8.0.7
	 * @var array{feed_name:string,offset:int,batch_size:int,total:int,attempt:int}|null
	 */
	private $in_flight_batch = null;

	/**
	 * Whether the fatal-recovery shutdown function has been registered this
	 * request (register once, not per batch).
	 *
	 * @since 8.0.7
	 * @var bool
	 */
	private $shutdown_guard_registered = false;

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
	 * Filesystem helper (legacy temp-file sweep before a run).
	 *
	 * @var Filesystem|null
	 */
	private $filesystem;

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
	 * Inject the Filesystem helper.
	 *
	 * @since 8.0.10
	 *
	 * @param Filesystem $filesystem Filesystem helper.
	 * @return void
	 */
	public function set_filesystem( Filesystem $filesystem ): void {
		$this->filesystem = $filesystem;
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
		// Self-overlap guard: a second "generate" for a feed whose run is still
		// moving must be refused, not started. The site-wide lock below lets the
		// SAME feed re-acquire it (batches refresh the TTL that way), so without
		// this check a second click — another tab, another admin, a manual click
		// during the cron run — starts a parallel batch chain writing into the
		// same working file (interleaved log, counters jumping backwards).
		$manager = $this->manager ? $this->manager : new FeedManager();
		if ( self::is_run_in_progress( $manager->get_progress( $feed_name ) ) ) {
			Logger::info( "Generation refused — already in progress: {$feed_name}" );

			return new \WP_Error(
				'ctxfeed_generation_in_progress',
				__( 'This feed is already generating. Wait for the current run to finish before starting another.', 'woo-feed' ),
				array( 'status' => 409 ) // HTTP Conflict.
			);
		}

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
	 * Whether a progress record describes a run that is still moving.
	 *
	 * "Moving" = status generating / finalizing / scheduled AND the record
	 * was touched within the generation-lock TTL. A run that died without
	 * reaching the failure path (PHP killed mid-batch on a host without the
	 * shutdown guard) stops touching its record, so after LOCK_TTL seconds
	 * it is treated as dead and a new run may start.
	 *
	 * @since 8.0.10
	 *
	 * @param array    $progress Progress record from FeedManager::get_progress().
	 * @param int|null $now      Current WP-local timestamp (tests); defaults to the WP-local clock, the same one update_progress() stamps with.
	 * @return bool
	 */
	public static function is_run_in_progress( array $progress, ?int $now = null ): bool {
		$status = isset( $progress['status'] ) ? (string) $progress['status'] : '';
		if ( ! in_array( $status, array( 'generating', 'finalizing', 'scheduled' ), true ) ) {
			return false;
		}

		$updated_at = isset( $progress['updated_at'] ) ? (string) $progress['updated_at'] : '';
		if ( '' === $updated_at ) {
			return false;
		}

		$touched = strtotime( $updated_at );
		if ( false === $touched ) {
			return false;
		}

		$now = null === $now ? (int) strtotime( current_time( 'mysql' ) ) : $now;

		return ( $now - $touched ) <= BatchCalculator::LOCK_TTL;
	}

	/**
	 * Delete V5-era temporary feed files for one feed.
	 *
	 * Delegates to Filesystem::purge_legacy_temp_files(); a no-op when no
	 * Filesystem has been injected (unit contexts). Never lets a sweep
	 * failure stop the generation.
	 *
	 * @since 8.0.10
	 *
	 * @param string $feed_name Feed slug.
	 * @return int Files deleted.
	 */
	public function purge_legacy_temp_files( string $feed_name ): int {
		if ( ! $this->filesystem ) {
			return 0;
		}
		try {
			$deleted = $this->filesystem->purge_legacy_temp_files( $feed_name );
		} catch ( \Throwable $e ) {
			Logger::warning( 'Legacy temp-file sweep failed (non-fatal): ' . $e->getMessage() );
			return 0;
		}
		if ( $deleted > 0 && $this->feed_logger ) {
			$this->feed_logger->info( $feed_name, sprintf( 'Removed %d leftover temporary file(s) from the previous feed engine.', $deleted ) );
		}

		return $deleted;
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

		// Sweep the previous engine's temp files for this feed before writing
		// anything — a leftover V5 body file can be gigabytes (#68942).
		$this->purge_legacy_temp_files( $feed_name );
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

		// Initialize per-feed log file. The lines that follow are the SAME
		// story the Manage Feeds console tells (same wording, same order), so
		// the downloadable log and the live console never disagree.
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
			$this->feed_logger->info( $feed_name, 'Querying products…' );
			$this->feed_logger->info( $feed_name, sprintf( 'Found %s', FeedLogger::products( $total ) ) );
			$this->feed_logger->info( $feed_name, 'Calculating batches…' );
			$this->feed_logger->info(
				$feed_name,
				sprintf( '%s of up to %s scheduled', FeedLogger::batches( $batches_total ), FeedLogger::products( $batch_size ) )
			);
			$this->feed_logger->flush( $feed_name );
		}

		// Set initial enriched progress. @implements FEED-FRD-10.1.
		$manager = $this->manager ? $this->manager : new FeedManager();
		$manager->update_progress(
			$feed_name,
			array(
				'current'             => 0,
				'total'               => $total,
				'status'              => 'generating',
				'batch_size'          => $batch_size,
				'batches_done'        => 0,
				'batches_total'       => $batches_total,
				// Zeroed here because update_progress() MERGES with the
				// previous run's record — without the reset the live log
				// console would carry last run's skip count forward.
				'skipped_total'       => 0,
				'last_batch_written'  => 0,
				'last_batch_skipped'  => 0,
				'last_batch_excluded' => 0,
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

		// Idempotent: a recurring action already queued with THIS interval is
		// kept as it is. Re-creating it would re-anchor the next run at "now"
		// (every save or completion pushing the schedule back) and, when the
		// sync happens INSIDE the running recurring action (small feeds that
		// generate synchronously), Action Scheduler adds its own next instance
		// on completion too — the duplicate recurring actions seen in the wild.
		if ( $this->existing_recurring_interval( $feed_name ) === $interval ) {
			return 'kept';
		}

		$this->schedule_recurring( $feed_name, $interval );
		return 'scheduled';
	}

	/**
	 * Keep a feed's recurring schedule after a generation run.
	 *
	 * Called on completion (FeedManager::promote_feed()). Unlike a save, a
	 * completed run must NEVER move the schedule: the interval is anchored at
	 * the run's START (Action Scheduler queues the next instance itself when
	 * the recurring action finishes), so a 1-hour feed runs every hour
	 * regardless of how long a run takes. All this does is (a) cancel when
	 * auto-update is off and (b) create the schedule when none exists yet —
	 * the first generation of a new feed, or a schedule lost on a site
	 * without the upgrade reconcile.
	 *
	 * @since 8.0.10
	 *
	 * @param string $feed_name Feed slug.
	 * @param array  $feed_data Stored wf_feed_* row.
	 * @return string 'cancelled' | 'kept' | 'scheduled'.
	 */
	public function ensure_recurring_schedule( string $feed_name, array $feed_data ): string {
		$status = isset( $feed_data['status'] ) ? (int) $feed_data['status'] : 0;
		if ( 1 !== $status ) {
			$this->cancel_recurring( $feed_name );
			return 'cancelled';
		}

		$interval = $this->resolve_interval( $feed_data );
		if ( $interval <= 0 ) {
			$this->cancel_recurring( $feed_name );
			return 'cancelled';
		}

		// Pending OR running right now (as_next_scheduled_action() returns
		// true for an in-progress action) — both mean "a schedule exists".
		if ( function_exists( 'as_next_scheduled_action' )
			&& false !== as_next_scheduled_action( self::RECURRING_ACTION, array( 'feed_name' => $feed_name ), self::GROUP )
		) {
			return 'kept';
		}

		$this->schedule_recurring( $feed_name, $interval );
		return 'scheduled';
	}

	/**
	 * Interval (seconds) of the feed's pending recurring action, or null.
	 *
	 * @since 8.0.10
	 *
	 * @param string $feed_name Feed slug.
	 * @return int|null
	 */
	private function existing_recurring_interval( string $feed_name ): ?int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return null;
		}

		try {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => self::RECURRING_ACTION,
					'args'     => array( 'feed_name' => $feed_name ),
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => 1,
				)
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		$action = is_array( $actions ) ? reset( $actions ) : false;
		if ( ! is_object( $action ) || ! method_exists( $action, 'get_schedule' ) ) {
			return null;
		}

		$schedule = $action->get_schedule();
		if ( is_object( $schedule ) && method_exists( $schedule, 'get_recurrence' ) ) {
			$recurrence = $schedule->get_recurrence();
			return is_numeric( $recurrence ) ? (int) $recurrence : null;
		}
		if ( is_object( $schedule ) && method_exists( $schedule, 'interval_in_seconds' ) ) {
			return (int) $schedule->interval_in_seconds();
		}

		return null;
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

		// 2. Nothing set on the feed → every 24 hours (owner decision
		// 2026-09-02; the V5 global `wf_schedule` option is no longer read).
		/**
		 * Filter the default auto-update interval for feeds without one.
		 *
		 * @since 8.0.0
		 *
		 * @param int $seconds Default interval in seconds (24h).
		 */
		return (int) apply_filters( 'ctxfeed_default_update_interval', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
	}

	/**
	 * The interval a feed WILL run at, in whole hours — for display.
	 *
	 * Same precedence as resolve_interval(), so the Manage Feeds table and
	 * the scheduler can never disagree. The caller decides whether to show it
	 * at all (auto-update off → "Manual").
	 *
	 * @since 8.0.10
	 *
	 * @param array $feed_data Unserialised `wf_feed_{slug}` option.
	 * @return int Hours (minimum 1).
	 */
	public static function effective_interval_hours( array $feed_data ): int {
		$seconds = ( new self() )->resolve_interval( $feed_data );

		return max( 1, (int) round( $seconds / ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ) ) );
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
	 * - GENERATE_ACTION (5 params): Individual batch processing (the 5th,
	 *   `attempt`, is the smaller-batch retry counter; defaults to 0 for
	 *   actions scheduled before 8.0.7).
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
		add_action( self::GENERATE_ACTION, array( $this, 'handle_batch' ), 10, 5 );
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
		if ( self::is_run_in_progress( $progress ) ) {
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
	 * @param int    $attempt    Smaller-batch retry counter (0 = first try). @since 8.0.7.
	 *
	 * @return void
	 */
	public function handle_batch( string $feed_name, int $offset, int $batch_size, int $total, int $attempt = 0 ): void {
		if ( ! $this->generator ) {
			Logger::error( 'FeedGenerator not set in FeedScheduler' );
			return;
		}

		// Refresh the generation lock TTL so it doesn't expire mid-generation
		// on large catalogs with many batches (e.g., 100K products / 100 batch = 1000 batches).
		if ( $this->batch_calculator ) {
			$this->batch_calculator->acquire_lock( $feed_name );
		}

		// Arm the fatal-recovery guard: if this batch dies on an UNCATCHABLE
		// fatal (max-execution-time / memory-limit — a "batch too big"), the
		// shutdown guard reschedules the SAME offset with a halved batch size
		// instead of leaving the feed stalled. @implements 8.0.7.
		$this->in_flight_batch = array(
			'feed_name'  => $feed_name,
			'offset'     => $offset,
			'batch_size' => $batch_size,
			'total'      => $total,
			'attempt'    => $attempt,
		);
		$this->register_fatal_guard();

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

			// Batch completed cleanly — disarm the guard so shutdown is a no-op.
			$this->in_flight_batch = null;

			$next_batch_size = isset( $result['next_batch_size'] ) ? (int) $result['next_batch_size'] : $batch_size;

			// Chain next batch or finalize with adaptive batch size. @implements FEED-FRD-3.2.
			$this->schedule_next_or_finalize( $feed_name, $offset, $batch_size, $total, $next_batch_size );
		} catch ( \Throwable $e ) {
			// Catchable failure handled here — disarm the shutdown guard.
			$this->in_flight_batch = null;

			// A CATCHABLE batch-level error (config load, cache warm, file open,
			// a \TypeError/\Error engine bug — NOT a single malformed product,
			// which process_batch isolates and skips). Try a smaller retry of the
			// SAME offset first; only give up (fail the whole run) once retries
			// are exhausted or the size is already at the floor. @implements 8.0.7.
			if ( $this->maybe_schedule_batch_retry( $feed_name, $offset, $batch_size, $total, $attempt, $e->getMessage() ) ) {
				return;
			}

			// Retries exhausted. Without this the throw would skip
			// schedule_next_or_finalize: the chain dies, status stays
			// 'generating', and the lock never releases — the feed silently
			// stalls until the lock TTL expires. Surface it instead.
			$this->fail_generation( $feed_name, $offset, $e );
		}
	}

	/**
	 * Register the shutdown guard that recovers from an UNCATCHABLE fatal
	 * (max-execution-time / memory-limit) mid-batch. Registered once per request.
	 *
	 * @since 8.0.7
	 * @return void
	 */
	private function register_fatal_guard(): void {
		if ( $this->shutdown_guard_registered ) {
			return;
		}
		$this->shutdown_guard_registered = true;
		register_shutdown_function( array( $this, 'on_batch_shutdown' ) );
	}

	/**
	 * Shutdown handler: if a batch was in flight and the request died on a fatal
	 * error, retry that offset with a halved batch size (or fail the run if
	 * retries are exhausted). A clean batch clears in_flight_batch, so this is a
	 * no-op on every normal request. Public only because it is a shutdown
	 * callback.
	 *
	 * @since 8.0.7
	 *
	 * @param array|null $last_error Injected error for tests; defaults to the
	 *                               request's real error_get_last().
	 * @return void
	 */
	public function on_batch_shutdown( ?array $last_error = null ): void {
		$batch = $this->in_flight_batch;
		if ( null === $batch ) {
			return; // Batch completed (or was caught) — nothing died.
		}
		$this->in_flight_batch = null;

		// Only act on a genuine fatal — not a clean exit that happened to leave
		// the flag set (defensive; in practice an unfinished batch means a fatal).
		$last        = ( null === $last_error ) ? error_get_last() : $last_error;
		$fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
		if ( ! is_array( $last ) || ! isset( $last['type'] ) || ! in_array( $last['type'], $fatal_types, true ) ) {
			return;
		}

		$reason = 'fatal: ' . ( isset( $last['message'] ) ? $last['message'] : 'unknown' );

		try {
			if ( $this->maybe_schedule_batch_retry( $batch['feed_name'], $batch['offset'], $batch['batch_size'], $batch['total'], $batch['attempt'], $reason ) ) {
				return;
			}
			// Retries exhausted — mark the run failed so it doesn't hang.
			$manager = $this->manager ? $this->manager : new FeedManager();
			$manager->update_progress( $batch['feed_name'], array( 'status' => 'failed' ) );
			if ( $this->batch_calculator ) {
				$this->batch_calculator->release_lock( $batch['feed_name'] );
			}
		} catch ( \Throwable $e ) {
			// Never let the shutdown handler itself throw (e.g. an OOM leaving no
			// headroom to schedule) — the feed simply stays where it was.
			return;
		}
	}

	/**
	 * Reschedule a failed batch at the SAME offset with a halved batch size,
	 * giving it a fresh execution window. Returns false (caller should fail the
	 * run) when retries are exhausted or the size is already at the floor — at
	 * the floor a failure is no longer a "too big" problem, so shrinking can't
	 * help.
	 *
	 * @since 8.0.7
	 *
	 * @param string $feed_name  Feed slug identifier.
	 * @param int    $offset     Offset of the batch that failed.
	 * @param int    $batch_size Size that failed.
	 * @param int    $total      Total product count.
	 * @param int    $attempt    Retry attempt number (0 = first try).
	 * @param string $reason     Human-readable failure reason (for the log).
	 *
	 * @return bool True if a smaller retry was scheduled; false to give up.
	 */
	private function maybe_schedule_batch_retry( string $feed_name, int $offset, int $batch_size, int $total, int $attempt, string $reason ): bool {
		/**
		 * Filter the retry ceiling for a failing batch (0 disables retries).
		 *
		 * @since 8.0.7
		 *
		 * @param int    $max       Maximum retry attempts.
		 * @param string $feed_name Feed slug identifier.
		 */
		$max   = (int) apply_filters( 'ctxfeed_batch_max_retries', self::MAX_BATCH_RETRIES, $feed_name );
		$floor = BatchCalculator::BATCH_FLOOR;

		if ( $attempt >= $max || $batch_size <= $floor ) {
			return false;
		}

		$smaller = max( $floor, (int) floor( $batch_size / 2 ) );

		if ( $this->feed_logger ) {
			$this->feed_logger->error(
				$feed_name,
				sprintf(
					'Batch at offset %d failed (%s) — retrying with a smaller batch size %d (attempt %d/%d).',
					$offset,
					$reason,
					$smaller,
					$attempt + 1,
					$max
				)
			);
			$this->feed_logger->flush( $feed_name );
		}

		// Refresh the lock so it survives until the retry runs, then schedule the
		// SAME offset with the smaller size in a fresh request.
		if ( $this->batch_calculator ) {
			$this->batch_calculator->acquire_lock( $feed_name );
		}

		as_schedule_single_action(
			time() + 5,
			self::GENERATE_ACTION,
			array(
				'feed_name'  => $feed_name,
				'offset'     => $offset,
				'batch_size' => $smaller,
				'total'      => $total,
				'attempt'    => $attempt + 1,
			),
			self::GROUP
		);

		return true;
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
	 * Browser-driven fallback: run this feed's PENDING batch/finalize actions
	 * synchronously, up to a wall-clock budget.
	 *
	 * On sites where WP-Cron is disabled — or its loopback to wp-cron.php is
	 * blocked by a firewall/CDN — Action Scheduler never processes the queued
	 * generation actions, so a feed sits 'generating' at 0% forever. This lets
	 * the open admin drive generation itself: it claims + runs each pending
	 * action through Action Scheduler's OWN runner (which fires handle_batch →
	 * processes the batch → chains the next), looping inline until the budget
	 * is spent. The React app calls POST /feeds/{id}/run repeatedly while a feed
	 * generates. Because it runs batches back-to-back in one request, it also
	 * removes the per-batch Action Scheduler dispatch latency.
	 *
	 * Using process_action() (not process_batch() directly) means the action is
	 * claimed + marked complete exactly as AS would, so it can never be
	 * double-run if cron later revives mid-generation.
	 *
	 * @since 8.0.2
	 *
	 * @param string $feed_name      Feed slug identifier.
	 * @param float  $budget_seconds Max wall-clock time to spend this call.
	 * @return int Number of actions processed this call.
	 */
	public function run_pending_batches( string $feed_name, float $budget_seconds = 20.0 ): int {
		if ( ! class_exists( '\ActionScheduler' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		/**
		 * Filter the per-request wall-clock budget (seconds) for the
		 * browser-driven batch runner. Kept well under max_execution_time so
		 * the request always returns cleanly; the app calls again for the rest.
		 *
		 * @since 8.0.2
		 *
		 * @param float  $budget_seconds Budget in seconds.
		 * @param string $feed_name      Feed slug identifier.
		 */
		$budget_seconds = (float) apply_filters( 'ctxfeed_browser_run_budget', $budget_seconds, $feed_name );
		if ( $budget_seconds <= 0 ) {
			$budget_seconds = 20.0;
		}

		$start = microtime( true );
		$ran   = 0;
		$cap   = 500; // Hard safety cap on actions processed per request.

		while ( $ran < $cap && ( microtime( true ) - $start ) < $budget_seconds ) {
			$action_id = $this->next_pending_action_id( $feed_name );
			if ( 0 === $action_id ) {
				break; // Nothing pending for this feed — done, finalized, or failed.
			}
			try {
				\ActionScheduler::runner()->process_action( $action_id, 'ctxfeed-browser-runner' );
			} catch ( \Throwable $e ) {
				// A catastrophic action failure is already recorded by
				// Action Scheduler (and, for a batch, by fail_generation which
				// sets status=failed). Stop driving and return cleanly so the
				// request never 500s; the status poll surfaces the failure.
				break;
			}
			++$ran;
		}

		return $ran;
	}

	/**
	 * Find the next PENDING generate/finalize action id for a feed.
	 *
	 * Generate actions are drained before the finalize action. Only actions
	 * whose `feed_name` arg matches are returned — Action Scheduler's own args
	 * filter can't match a single arg out of the four, so this fetches + checks.
	 *
	 * @since 8.0.2
	 *
	 * @param string $feed_name Feed slug identifier.
	 * @return int Action id, or 0 when none pending for this feed.
	 */
	private function next_pending_action_id( string $feed_name ): int {
		foreach ( array( self::GENERATE_ACTION, self::FINALIZE_ACTION ) as $hook ) {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 50,
					'orderby'  => 'date',
					'order'    => 'ASC',
				),
				'ids'
			);
			foreach ( (array) $ids as $id ) {
				$action = \ActionScheduler::store()->fetch_action( (int) $id );
				if ( ! $action ) {
					continue;
				}
				$args = $action->get_args();
				if ( isset( $args['feed_name'] ) && (string) $args['feed_name'] === $feed_name ) {
					return (int) $id;
				}
			}
		}
		return 0;
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
