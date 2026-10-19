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

use CTXFeed\V8\Bootstrap;
use CTXFeed\V8\Core\Config;
use CTXFeed\V8\Core\FeatureGate;
use CTXFeed\V8\Core\Logger;
use CTXFeed\V8\Product\ProductQuery;
use CTXFeed\V8\Status\ProState;
use CTXFeed\V8\Status\UpdateWaitNotice;
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
	 * Daily self-heal heartbeat (CBT-569): re-registers any enabled feed
	 * whose recurring action was silently dropped.
	 *
	 * @since 8.0.18
	 */
	const RECONCILE_ACTION = 'ctxfeed_schedule_reconcile';

	/**
	 * Progress silence (seconds) after which a generating feed with NO live
	 * batch/finalize action counts as a dead chain (a batch killed with
	 * SIGKILL runs no shutdown handler, so the 8.0.7 retry never fires).
	 * Batches heartbeat every 3 s — 90 silent seconds is 30 missed beats.
	 *
	 * @since 8.0.10
	 * @var int
	 */
	const REVIVE_SILENCE_SECONDS = 90;

	/**
	 * Revives allowed per run before the feed is marked failed (each revive
	 * halves the batch, so 5 narrow a killer product down toward the floor).
	 *
	 * @since 8.0.10
	 * @var int
	 */
	const MAX_CHAIN_REVIVES = 5;

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
	 * Seconds between checks while a run waits for the plugin stack to load
	 * (CBT-807). Filter `ctxfeed_update_wait_delay`, clamped to 30–300: at 0
	 * the re-queued copy is due at once and Action Scheduler claims it again
	 * in the same queue run; at 600+ the run stops looking alive (LOCK_TTL).
	 *
	 * @since 8.0.38
	 * @var int
	 */
	const UPDATE_WAIT_DELAY = 60;

	/**
	 * Longest a run waits (seconds) before it stops: status failed, the
	 * previous feed file kept, the next run starts again (owner decision
	 * 2026-10-16). Filter `ctxfeed_update_wait_max_seconds`, never less than
	 * twice the delay and never more than UPDATE_WAIT_MAX_CEILING.
	 *
	 * @since 8.0.38
	 * @var int
	 */
	const UPDATE_WAIT_MAX = 600;

	/**
	 * Upper bound of the wait maximum (seconds): well inside the 1-hour
	 * batch marker a redone batch rolls the file back with, and the 3-hour
	 * product-ID snapshot.
	 *
	 * @since 8.0.38
	 * @var int
	 */
	const UPDATE_WAIT_MAX_CEILING = 1800;

	/**
	 * Option prefix of a run's wait clock `{run, since, reason, last}`
	 * (CBT-807). Not autoloaded, read only on the hold path; it outlives the
	 * 1-hour progress transient. Never a prefix of another option this
	 * plugin writes (a feed slugged `notice` once collided with the notice
	 * record).
	 *
	 * @since 8.0.38
	 * @var string
	 */
	const WAIT_OPTION_PREFIX = 'ctxfeed_update_wait_clock_';

	/**
	 * Test seam for wait_reason(): null = detect, '' / 'pro' / 'free' = pinned.
	 *
	 * @since 8.0.38
	 * @var string|null
	 */
	private static $forced_wait_reason = null;

	/**
	 * Test pin for wait_is_this_request_only() (null = ask ProState).
	 *
	 * @var bool|null
	 */
	private static $forced_request_only = null;

	/**
	 * Test seam for the after-batch file check: null = stat, '' / 'pro' /
	 * 'free' = pinned.
	 *
	 * @since 8.0.38
	 * @var string|null
	 */
	private static $forced_files_in_flux = null;

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
	 * @var array{feed_name:string,offset:int,batch_size:int,total:int,attempt:int,run:string}|null
	 */
	private $in_flight_batch = null;

	/**
	 * Per-run batch cursor: only one process may start each batch (CBT-735).
	 *
	 * @since 8.0.34
	 * @var BatchCursor|null
	 */
	private $batch_cursor;

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
	 * Inject the batch cursor (tests). Lazily created otherwise.
	 *
	 * @since 8.0.34
	 *
	 * @param BatchCursor $batch_cursor Cursor.
	 * @return void
	 */
	public function set_batch_cursor( BatchCursor $batch_cursor ): void {
		$this->batch_cursor = $batch_cursor;
	}

	/**
	 * The batch cursor.
	 *
	 * @since 8.0.34
	 * @return BatchCursor
	 */
	private function cursor(): BatchCursor {
		if ( ! $this->batch_cursor ) {
			$this->batch_cursor = new BatchCursor();
		}
		return $this->batch_cursor;
	}

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
	 * Pin wait_reason() for tests (CBT-807). PureUnitTestCase pins '' around
	 * every test so the order of the suite never decides whether a run
	 * waits; feature tests pin 'pro' / 'free' explicitly.
	 *
	 * @since 8.0.38
	 *
	 * @param string|null $reason '' / 'pro' / 'free', or null to detect for real.
	 * @return void
	 */
	public static function force_wait_reason( ?string $reason ): void {
		self::$forced_wait_reason = $reason;
	}

	/**
	 * Pin wait_is_this_request_only() for tests (CBT-807).
	 *
	 * @since 8.0.38
	 *
	 * @param bool|null $only True / false, or null to ask ProState.
	 * @return void
	 */
	public static function force_request_only_wait( ?bool $only ): void {
		self::$forced_request_only = $only;
	}

	/**
	 * Whether only THIS request sees Pro as not ready — WordPress recovery
	 * mode, or Pro not loaded on this request although it is installed —
	 * while cron requests load it normally (CBT-807).
	 *
	 * @since 8.0.38
	 *
	 * @return bool
	 */
	private static function wait_is_this_request_only(): bool {
		if ( null !== self::$forced_request_only ) {
			return self::$forced_request_only;
		}
		try {
			return class_exists( ProState::class )
				&& ( ProState::recovery_mode() || ( method_exists( ProState::class, 'not_loaded' ) && ProState::not_loaded() ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Pin the after-batch file check for tests (CBT-807), like
	 * force_wait_reason().
	 *
	 * @since 8.0.38
	 *
	 * @param string|null $reason '' / 'pro' / 'free', or null to stat for real.
	 * @return void
	 */
	public static function force_files_in_flux( ?string $reason ): void {
		self::$forced_files_in_flux = $reason;
	}

	/**
	 * Why a feed run must wait right now, or '' when it may write (CBT-807).
	 *
	 * A run may only write or publish in a request where every part of the
	 * stack that shapes the feed has loaded:
	 * - 'pro'  — CTX Feed Pro is listed as active and licensed but is being
	 *            updated or did not finish its boot (ProState::paused()), or a
	 *            Pro part free resolves at boot failed (Bootstrap::incomplete());
	 * - 'free' — free's own compatibility layer did not load, or ProState
	 *            itself cannot load (free's files are being replaced);
	 * - ''     — ready.
	 * O(1) — a filter and a few constant/static checks, once per action or
	 * REST call, never per product. Never throws.
	 *
	 * @since 8.0.38
	 *
	 * @return string '', 'pro' or 'free'.
	 */
	public static function wait_reason(): string {
		if ( null !== self::$forced_wait_reason ) {
			return self::$forced_wait_reason;
		}

		try {
			if ( is_callable( array( Bootstrap::class, 'incomplete' ) ) ) {
				$parts = (array) Bootstrap::incomplete();
				if ( in_array( 'pro', $parts, true ) ) {
					return 'pro';
				}
				if ( array() !== $parts ) {
					return 'free';
				}
			}
			if ( ! class_exists( ProState::class ) ) {
				return 'free';
			}

			return ProState::paused() ? 'pro' : '';
		} catch ( \Throwable $e ) {
			return 'free';
		}
	}

	/**
	 * Seconds between checks while a run waits (filtered, 30–300).
	 *
	 * @since 8.0.38
	 *
	 * @return int
	 */
	public static function wait_delay(): int {
		/**
		 * Filter the seconds between checks while a feed run waits for CTX
		 * Feed Pro (or CTX Feed itself) to finish loading. Clamped to 30–300.
		 *
		 * @since 8.0.38
		 *
		 * @param int $seconds Default 60.
		 */
		$delay = (int) apply_filters( 'ctxfeed_update_wait_delay', self::UPDATE_WAIT_DELAY );

		return max( 30, min( 300, $delay ) );
	}

	/**
	 * Longest a run waits before it stops (filtered, 2 × delay – 30 minutes).
	 *
	 * @since 8.0.38
	 *
	 * @return int Seconds.
	 */
	public static function wait_max(): int {
		/**
		 * Filter the longest a feed run waits for CTX Feed Pro (or CTX Feed
		 * itself) before it stops. Never less than twice the check delay,
		 * never more than 30 minutes.
		 *
		 * @since 8.0.38
		 *
		 * @param int $seconds Default 600 (10 minutes).
		 */
		$max = (int) apply_filters( 'ctxfeed_update_wait_max_seconds', self::UPDATE_WAIT_MAX );

		// Never past the batch marker's hour: a redo after it expired would
		// append the batch a second time.
		return min( self::UPDATE_WAIT_MAX_CEILING, max( 2 * self::wait_delay(), $max ) );
	}

	/**
	 * Seconds without a hold after which a wait clock belongs to an episode
	 * that ended (two missed checks plus slack): the next hold starts anew.
	 *
	 * @since 8.0.38
	 *
	 * @return int
	 */
	private static function wait_episode_gap(): int {
		return 2 * self::wait_delay() + 30;
	}

	/**
	 * The message for a run that cannot start or must wait: the plugin that
	 * is not ready and what happens next.
	 *
	 * @since 8.0.38
	 *
	 * @param string $reason 'pro' or 'free'.
	 * @return string
	 */
	public static function wait_message( string $reason ): string {
		try {
			$message = class_exists( ProState::class ) ? ProState::reason_message( $reason ) : '';
		} catch ( \Throwable $e ) {
			$message = '';
		}

		return '' !== $message
			? $message
			: __( 'CTX Feed is being updated or part of it could not start. Feeds are not generated until it has loaded completely. Try again in a minute.', 'woo-feed' );
	}

	/**
	 * The refusal for a manual start while a run would have to wait (owner
	 * decision 2026-10-16): HTTP 409 — never 503, which the admin app treats
	 * as a firewall challenge and retries.
	 *
	 * @since 8.0.38
	 *
	 * @param string $reason 'pro' or 'free'.
	 * @return \WP_Error Code `ctxfeed_plugin_updating`, data {status, reason, retry_after}.
	 */
	public static function waiting_error( string $reason ): \WP_Error {
		return new \WP_Error(
			'ctxfeed_plugin_updating',
			self::wait_message( $reason ),
			array(
				'status'      => 409, // HTTP Conflict.
				'reason'      => $reason,
				'retry_after' => self::wait_delay(),
			)
		);
	}

	/**
	 * Name of a feed's wait-clock option.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return string
	 */
	public static function wait_option( string $feed_name ): string {
		return self::WAIT_OPTION_PREFIX . $feed_name;
	}

	/**
	 * Drop a feed's wait clock (a run starts, is cancelled or the feed is
	 * deleted). One DELETE, no read.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return void
	 */
	public static function forget_wait( string $feed_name ): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::wait_option( $feed_name ) );
		}
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
	 * @param string $trigger   'manual' (default) or 'scheduled' — scheduled runs use the lower batch ceiling.
	 *
	 * @return true|\WP_Error True on success, WP_Error if generation lock held by another feed.
	 *
	 * @throws \Throwable Re-thrown from the synchronous fast-path so REST callers receive the real generation error.
	 */
	public function schedule_generation( string $feed_name, Config $config, string $trigger = 'manual' ) {
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

		// CTX Feed Pro (or CTX Feed's own compatibility layer) is being
		// updated or could not start: a run now would write rows without it
		// — wrong currency, language, mappings. Refuse before the lock, the
		// cursor, the progress record or the snapshot are touched (CBT-807).
		$wait = self::wait_reason();
		if ( '' !== $wait ) {
			Logger::info( "Generation refused — waiting for the plugin stack ({$wait}): {$feed_name}" );

			return self::waiting_error( $wait );
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
			return $this->run_generation( $feed_name, $config, $trigger );
		} catch ( \Throwable $e ) {
			if ( $this->batch_calculator ) {
				// Ownership-scoped: a safe no-op if the lock was already freed.
				$this->batch_calculator->release_lock( $feed_name );
			}
			$manager = $this->manager ? $this->manager : new FeedManager();
			// Carry the trigger: a throw before the run's first progress write
			// would otherwise leave it unknown (failure emails, CBT-730).
			$manager->update_progress(
				$feed_name,
				array(
					'status'  => 'failed',
					'trigger' => $trigger,
				)
			);

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
	 * @param int|null $now      Current timestamp (tests); see seconds_since_touched().
	 * @return bool
	 */
	public static function is_run_in_progress( array $progress, ?int $now = null ): bool {
		$status = isset( $progress['status'] ) ? (string) $progress['status'] : '';
		if ( ! in_array( $status, array( 'generating', 'finalizing', 'scheduled' ), true ) ) {
			return false;
		}

		$age = self::seconds_since_touched( $progress, $now );

		return null !== $age && $age <= BatchCalculator::LOCK_TTL;
	}

	/**
	 * Seconds since a run's progress record was last written, or null when
	 * it never was.
	 *
	 * Prefers the UTC epoch `updated_ts` (8.0.32+): the WP-local
	 * `updated_at` wall clock jumps an hour at a DST change, which made a
	 * live run look dead (a second chain started) or a dead one look alive.
	 * Records written by older builds fall back to `updated_at` (CBT-720).
	 *
	 * @since 8.0.32
	 *
	 * @param array    $progress Progress record.
	 * @param int|null $now      Current timestamp (tests): UTC epoch when the record has updated_ts, else WP-local.
	 * @return int|null
	 */
	public static function seconds_since_touched( array $progress, ?int $now = null ): ?int {
		$stamp = isset( $progress['updated_ts'] ) ? (int) $progress['updated_ts'] : 0;
		if ( $stamp > 0 ) {
			return ( null === $now ? time() : $now ) - $stamp;
		}

		$updated_at = isset( $progress['updated_at'] ) ? (string) $progress['updated_at'] : '';
		$touched    = '' === $updated_at ? false : strtotime( $updated_at ); // tz-safe: an age — subtracted from current_time() read in the same zone.
		if ( false === $touched ) {
			return null;
		}

		return ( null === $now ? (int) strtotime( current_time( 'mysql' ) ) : $now ) - $touched; // tz-safe: an age — both wall clocks are read in the same zone.
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
	 * @param string $trigger 'manual' or 'scheduled' (lower batch ceiling).
	 * @return bool True once generation has run (sync) or been scheduled (async).
	 * @throws \Throwable On any product-resolution or synchronous-generation
	 *                    failure; the caller releases the lock and re-throws.
	 * @throws \RuntimeException When the Action Scheduler API is not loaded (CBT-699).
	 */
	private function run_generation( string $feed_name, Config $config, string $trigger = 'manual' ): bool {
		$query = new ProductQuery();
		// Bind the query to this feed so get_total_count() persists the
		// product-ID list as a snapshot. Subsequent batches reuse the same
		// snapshot — without this binding, a 200K-product feed re-runs
		// the same WC_Product_Query 400+ times.
		$query->set_feed_name( $feed_name );
		// Drop any stale snapshot from a previous run before resolving.
		$query->clear_snapshot( $feed_name );

		// A new run starts its own wait clock (CBT-807).
		self::forget_wait( $feed_name );

		// Sweep the previous engine's temp files for this feed before writing
		// anything — a leftover V5 body file can be gigabytes (#68942).
		$this->purge_legacy_temp_files( $feed_name );
		$total = $query->get_total_count( $config );

		// Auto-calculate initial batch size based on server limits. @implements FEED-FRD-11.1.
		$auto_batch_size = 200; // Fallback if no BatchCalculator.
		if ( $this->batch_calculator ) {
			// Bind the feed so calculate_initial() can seed from the
			// previous run's persisted per-product measurement.
			$this->batch_calculator->set_feed_name( $feed_name );
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

		// Scheduled (auto-update) runs are unattended — cap them lower so each
		// action stays small and progress commits often (see the constant).
		if ( 'scheduled' === $trigger ) {
			$batch_size = min( $batch_size, BatchCalculator::SCHEDULED_BATCH_CEILING );
		}

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
				'trigger'             => $trigger,
				'batch_size'          => $batch_size,
				'batches_done'        => 0,
				'batches_total'       => $batches_total,
				// Zeroed here because update_progress() MERGES with the
				// previous run's record — without the reset the live log
				// console would carry last run's skip count forward.
				'skipped_total'       => 0,
				'written_total'       => 0,
				'last_batch_written'  => 0,
				'last_batch_skipped'  => 0,
				'last_batch_excluded' => 0,
			) + FeedManager::UPLOAD_DEFAULTS + FeedManager::WAIT_DEFAULTS
		);

		// One token per run; every batch/finalize action carries it and must
		// claim its position on the cursor before writing (CBT-735). '' when
		// the cursor could not be written — the run then behaves as before.
		$run = $this->cursor()->begin( $feed_name );

		// Synchronous fast-path: if all products fit in one batch, process
		// immediately in the current request instead of deferring to Action
		// Scheduler. This avoids WP Cron / loopback dependencies and makes
		// small feeds (e.g. 38 products) complete in seconds.
		if ( $total <= $batch_size && $this->generator ) {
			Logger::info( "Synchronous generation — {$total} products fit in one batch: {$feed_name}" );

			// This request owns offset 0; a paused remainder hands it on.
			if ( '' !== $run && ! $this->cursor()->claim( $feed_name, $run, 0 ) ) {
				$run = '';
			}

			$finalizing = false;
			$result     = array();
			try {
				$result = $this->generator->process_batch(
					$feed_name,
					0,
					$batch_size,
					array(
						'total' => $total,
					) 
				);

				// Pro's (or free's) files vanished while the batch ran: wait and
				// redo offset 0 in the background instead of publishing rows an
				// inner catch may have defaulted (CBT-807).
				$flux = self::files_in_flux();
				if ( '' !== $flux ) {
					$this->hold_batch( $feed_name, 0, $batch_size, $total, 0, $run, $flux, is_array( $result ) ? $result : array() );
					return true;
				}

				// The batch may have paused itself: the time box keeps it
				// inside the scheduler's period, the memory box keeps it
				// inside PHP's memory limit (CBT-629). Either way it consumed
				// fewer ids than the set holds, and finalizing here would
				// publish a truncated feed — so the remainder goes to the
				// queue exactly as it does on the async path.
				$step = isset( $result['step'] ) && (int) $result['step'] > 0 ? (int) $result['step'] : $total;

				if ( $step < $total ) {
					Logger::info( "Synchronous generation paused at {$step}/{$total} products — continuing in the background: {$feed_name}" );

					$next_size = isset( $result['next_batch_size'] ) && (int) $result['next_batch_size'] > 0
						? (int) $result['next_batch_size']
						: $batch_size;

					$this->schedule_next_or_finalize( $feed_name, 0, $step, $total, $next_size, $run );
					$this->dispatch_runner();

					return true;
				}

				// Finalize immediately.
				$manager->update_progress(
					$feed_name,
					array(
						'current' => $total,
						'total'   => $total,
						'status'  => 'finalizing',
					) 
				);
				$finalizing = true;
				$this->generator->finalize( $feed_name );

				Logger::info( "Synchronous generation complete: {$feed_name}" );
			} catch ( \Throwable $e ) {
				// A plugin's files are being replaced (CBT-807): before the
				// footer nothing is published yet, so wait and redo offset 0 in
				// the background — this request still owns its cursor position
				// and the lock. After the footer: fail as before. When the
				// batch itself returned (the error came later, e.g. from
				// finalize()), it already counted its rows: hand them over so
				// the redo does not count them twice.
				$flux = self::in_flux_reason( $e );
				if ( '' !== $flux && ! ( $finalizing && method_exists( $this->generator, 'wrote_footer' ) && $this->generator->wrote_footer() ) ) {
					Logger::info( "Synchronous generation waits — plugin files are being replaced ({$flux}): {$feed_name}" );
					$this->hold_batch( $feed_name, 0, $batch_size, $total, 0, $run, $flux, is_array( $result ) ? $result : array() );
					return true;
				}

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
		if ( ! $this->scheduler_has( 'as_schedule_single_action' ) ) {
			// The wrapping catch marks the feed failed and releases the lock.
			throw new \RuntimeException( 'Action Scheduler is not available — is WooCommerce active?' );
		}
		as_schedule_single_action(
			time(),
			self::GENERATE_ACTION,
			self::batch_args( $feed_name, 0, $batch_size, $total, 0, $run ),
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
	 * @param string $run             Run token ('' = action queued before 8.0.34, no cursor). @since 8.0.34.
	 *
	 * @return void
	 */
	public function schedule_next_or_finalize( string $feed_name, int $offset, int $batch_size, int $total, int $next_batch_size = 0, string $run = '' ): void {
		if ( ! $this->scheduler_has( 'as_schedule_single_action' ) ) {
			return;
		}

		$next_offset = $offset + $batch_size;

		// Use adaptive size if provided, otherwise keep current. @implements FEED-FRD-11.3.
		if ( 0 === $next_batch_size ) {
			$next_batch_size = $batch_size;
		}

		// Move the cursor BEFORE queueing: a synchronous runner may execute
		// the new action before this call returns. Losing the swap means a
		// newer run (or a cancel) owns the feed now — this chain ends here.
		if ( '' !== $run ) {
			$to = $next_offset < $total ? $next_offset : BatchCursor::FINALIZE;
			if ( ! $this->cursor()->hand_off( $feed_name, $run, $offset, $to ) ) {
				Logger::info( "Batch chain ended — the run was superseded or cancelled: {$feed_name} offset {$offset}" );
				return;
			}
		}

		if ( $next_offset < $total ) {
			// Schedule next batch with adaptive batch size.
			as_schedule_single_action(
				time(),
				self::GENERATE_ACTION,
				self::batch_args( $feed_name, $next_offset, $next_batch_size, $total, 0, $run ),
				self::GROUP 
			);

			// Nudge runner so the next batch processes without waiting for cron.
			$this->dispatch_runner();
		} else {
			// Schedule finalization with 5-second delay for batch completion.
			as_schedule_single_action(
				time() + 5,
				self::FINALIZE_ACTION,
				self::finalize_args( $feed_name, $total, $run ),
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
		if ( ! $this->scheduler_has( 'as_unschedule_all_actions', 'as_schedule_recurring_action' ) ) {
			return;
		}

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
		if ( $this->scheduler_has( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::GENERATE_ACTION, array( 'feed_name' => $feed_name ), self::GROUP );
			as_unschedule_all_actions( self::FINALIZE_ACTION, array( 'feed_name' => $feed_name ), self::GROUP );
			as_unschedule_all_actions( self::RECURRING_ACTION, array( 'feed_name' => $feed_name ), self::GROUP );
		}

		// Release generation lock and clear batch history.
		if ( $this->batch_calculator ) {
			$this->batch_calculator->release_lock( $feed_name );
			$this->batch_calculator->clear_history( $feed_name );
		}

		// Every still-queued action of the run now loses its claim (CBT-735).
		$this->cursor()->clear( $feed_name );

		// A cancelled run's wait clock must not shorten the next run's wait (CBT-807).
		self::forget_wait( $feed_name );

		// Drop the product-ID snapshot transient — next run rebuilds it.
		// We use a fresh ProductQuery binding rather than the injected one
		// because cancel() is called from contexts that may not have run
		// FeedGenerator (e.g. delete_feed before any generation).
		$query = new ProductQuery();
		$query->clear_snapshot( $feed_name );
	}

	/**
	 * Action args for a batch. Action Scheduler passes the VALUES positionally
	 * to handle_batch(), so the order is the signature's order; the run token
	 * is only added when there is one, keeping pre-8.0.34 shapes intact.
	 *
	 * @since 8.0.34
	 *
	 * @param string $feed_name  Feed slug.
	 * @param int    $offset     Offset.
	 * @param int    $batch_size Batch size.
	 * @param int    $total      Total products.
	 * @param int    $attempt    Retry attempt.
	 * @param string $run        Run token.
	 * @return array<string,mixed>
	 */
	private static function batch_args( string $feed_name, int $offset, int $batch_size, int $total, int $attempt, string $run ): array {
		$args = array(
			'feed_name'  => $feed_name,
			'offset'     => $offset,
			'batch_size' => $batch_size,
			'total'      => $total,
		);
		if ( $attempt > 0 || '' !== $run ) {
			$args['attempt'] = $attempt;
		}
		if ( '' !== $run ) {
			$args['run'] = $run;
		}
		return $args;
	}

	/**
	 * Action args for the finalize step (positional, see batch_args()).
	 *
	 * @since 8.0.34
	 *
	 * @param string $feed_name Feed slug.
	 * @param int    $total     Total products.
	 * @param string $run       Run token.
	 * @return array<string,mixed>
	 */
	private static function finalize_args( string $feed_name, int $total, string $run ): array {
		$args = array(
			'feed_name' => $feed_name,
			'total'     => $total,
		);
		if ( '' !== $run ) {
			$args['run'] = $run;
		}
		return $args;
	}

	/**
	 * Whether the given Action Scheduler functions are loaded (CBT-699).
	 *
	 * Action Scheduler ships with WooCommerce; with no provider loaded an
	 * unguarded as_*() call is a site fatal. Owner rule: every third-party
	 * call is existence-checked in its own code path.
	 *
	 * @since 8.0.31
	 *
	 * @param string ...$functions Function names the caller is about to use.
	 * @return bool
	 */
	private function scheduler_has( string ...$functions ): bool {
		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) {
				Logger::error( "Action Scheduler is not available ({$function} missing) — is WooCommerce active? Feed scheduling skipped." );
				return false;
			}
		}
		return true;
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
		if ( ! $this->scheduler_has( 'as_unschedule_all_actions' ) ) {
			return;
		}
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

		// 1. Per-feed override (V8-native, seconds). Written by the Make Feed
		// minute-interval picker (Pro, 8.0.19) — FeedEndpoint persists the
		// m5/m15/m30/m45 codes here as seconds through the Pro Scheduling
		// engine (ctxfeed_update_interval_seconds, CBT-653); hour codes and
		// free clear it.
		if ( isset( $rules['update_interval'] ) ) {
			$per_feed = (int) $rules['update_interval'];
			// A sub-hour value can only have come from the Pro minute picker.
			// Free clears it on the next SAVE, but a licence lapse / Pro
			// deactivation never re-saves the feed, so without this gate the
			// 5/15/30/45-minute cadence — and the "Every 15 Minutes" label —
			// survived Pro (CBT-657). Gate closed → fall through to the hourly
			// `cron` code / the 24h default, exactly as if the feed were saved
			// on free. Hour-or-longer values are not a Pro feature and stand.
			// While CTX Feed Pro is being updated (or could not start) its gate
			// reads closed for a few requests; a schedule sync then (the
			// one-shot woo_feed_plugin_updated reconcile, a save) must keep
			// the minute cadence, not lower it for good (CBT-807).
			if ( $per_feed > 0 && ( $per_feed >= HOUR_IN_SECONDS || FeatureGate::has( 'short_update_intervals' ) || 'pro' === self::wait_reason() ) ) {
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
		$seconds = self::effective_interval_seconds( $feed_data );

		return max( 1, (int) round( $seconds / ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ) ) );
	}

	/**
	 * The interval a feed WILL run at, in seconds — for display.
	 *
	 * Same precedence as resolve_interval(), so the Manage Feeds table and
	 * the scheduler can never disagree. Sub-hour values come from the
	 * minute-interval picker (Pro, `feedrules.update_interval`).
	 *
	 * @since 8.0.19
	 *
	 * @param array $feed_data Unserialised `wf_feed_{slug}` option.
	 * @return int Seconds (minimum 60).
	 */
	public static function effective_interval_seconds( array $feed_data ): int {
		return max( 60, ( new self() )->resolve_interval( $feed_data ) );
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
	 * Keep the daily self-heal heartbeat registered (idempotent).
	 *
	 * Runs on `init` (Action Scheduler is loaded by then); one lookup per
	 * request, one recurring action per site.
	 *
	 * @since 8.0.18
	 * @return void
	 */
	public function ensure_reconcile_heartbeat(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::RECONCILE_ACTION, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::RECONCILE_ACTION, array(), self::GROUP );
	}

	/**
	 * Daily heartbeat callback — force a heal pass.
	 *
	 * @since 8.0.18
	 * @return void
	 */
	public function handle_reconcile(): void {
		$this->heal_recurring_schedules( true );
	}

	/**
	 * Re-register every enabled feed whose recurring action has silently
	 * disappeared (CBT-569).
	 *
	 * A feed with auto-update ON and a valid interval but NO pending/running
	 * recurring action is a feed that has silently stopped — the exact
	 * customer symptom (2 of 3 identically-configured feeds stale for 5+
	 * days, no error anywhere). Each heal is logged so the system trace
	 * records that the schedule WAS lost, not just quietly restored.
	 *
	 * Throttled to once per hour (transient) so callers can invoke it
	 * opportunistically — the Manage Feeds list does — without cost;
	 * the daily heartbeat passes $force.
	 *
	 * @since 8.0.18
	 *
	 * @param bool $force Skip the hourly throttle.
	 * @return string[] Feed slugs whose registration was re-created.
	 */
	public function heal_recurring_schedules( bool $force = false ): array {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return array();
		}

		if ( ! $force && false !== get_transient( 'ctxfeed_schedule_heal_at' ) ) {
			return array();
		}
		set_transient( 'ctxfeed_schedule_heal_at', time(), HOUR_IN_SECONDS );

		$manager = $this->manager ? $this->manager : new FeedManager();
		$healed  = array();

		foreach ( $manager->get_all_feed_names() as $feed_name ) {
			$feed_data = maybe_unserialize( get_option( 'wf_feed_' . $feed_name ) );
			if ( ! is_array( $feed_data ) ) {
				continue;
			}

			// Only enabled feeds with a real interval can be "silently
			// stopped"; everything else is legitimately unscheduled.
			if ( 1 !== (int) ( $feed_data['status'] ?? 0 ) || $this->resolve_interval( $feed_data ) <= 0 ) {
				continue;
			}

			if ( false !== as_next_scheduled_action( self::RECURRING_ACTION, array( 'feed_name' => $feed_name ), self::GROUP ) ) {
				continue;
			}

			if ( 'scheduled' === $this->ensure_recurring_schedule( $feed_name, $feed_data ) ) {
				$healed[] = $feed_name;
				Logger::info( sprintf( 'Self-heal: recurring schedule for feed "%s" was missing and has been re-registered (auto-update ON, interval configured).', $feed_name ) );
			}
		}

		return $healed;
	}

	/**
	 * The feed's next auto-update run as a UNIX timestamp, or null.
	 *
	 * Truth from Action Scheduler — NOT from stored settings, which is
	 * exactly the distinction the Manage Feeds UI was missing (CBT-569).
	 *
	 * @since 8.0.18
	 *
	 * @param string $feed_name Feed slug.
	 * @return int|null
	 */
	public function next_scheduled_run( string $feed_name ): ?int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return null;
		}

		return self::normalize_next_run(
			as_next_scheduled_action( self::RECURRING_ACTION, array( 'feed_name' => $feed_name ), self::GROUP )
		);
	}

	/**
	 * Map as_next_scheduled_action()'s tri-state to a timestamp-or-null.
	 *
	 * `true` means the action is running RIGHT NOW — surfaced as "now"
	 * rather than dropped, so a long-running feed doesn't flash as
	 * unscheduled mid-run.
	 *
	 * @since 8.0.18
	 *
	 * @param int|bool $raw as_next_scheduled_action() return value.
	 * @return int|null
	 */
	public static function normalize_next_run( $raw ): ?int {
		if ( true === $raw ) {
			return time();
		}

		return ( is_int( $raw ) && $raw > 0 ) ? $raw : null;
	}

	/**
	 * Boot: Register Action Scheduler callbacks.
	 *
	 * Registers handlers for three action types:
	 * - GENERATE_ACTION (5 params): Individual batch processing (the 5th,
	 *   `attempt`, is the smaller-batch retry counter; defaults to 0 for
	 *   actions scheduled before 8.0.7).
	 * - FINALIZE_ACTION (2 params): Feed finalization after all batches.
	 * - RECURRING_ACTION (2 params): Auto-update trigger that kicks off
	 *   a fresh schedule_generation() cycle; the 2nd, `held_at`, is only
	 *   set on a trigger held while the plugin stack loads (CBT-807).
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.5
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( self::GENERATE_ACTION, array( $this, 'handle_batch' ), 10, 6 );
		add_action( self::FINALIZE_ACTION, array( $this, 'handle_finalization' ), 10, 3 );
		add_action( self::RECURRING_ACTION, array( $this, 'handle_recurring' ), 10, 2 );

		// Self-heal for silently-dropped recurring registrations (CBT-569): a
		// daily heartbeat action plus its own idempotent registration. Before
		// this, a lost registration was only repaired by a feed save, a
		// completed run, or a plugin update — a feed could sit dead for days
		// with the UI still showing its configured schedule.
		add_action( self::RECONCILE_ACTION, array( $this, 'handle_reconcile' ) );
		add_action( 'init', array( $this, 'ensure_reconcile_heartbeat' ), 30 );

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
		$this->purge_disabled_feed_queues();
	}

	/**
	 * Cancel every queued/in-flight run belonging to feeds whose auto-update
	 * is OFF — the plugin-side cleanup for a wedged queue (#68345).
	 *
	 * Runs once per plugin update (from {@see reconcile_after_upgrade()}):
	 * a disabled feed with pending work is by definition unwanted work — the
	 * store owner turned the toggle off precisely to stop it, but queued
	 * batch/finalize/recurring actions used to survive both the toggle and
	 * the update, resuming for hours on large catalogs. Enabled feeds are
	 * never touched.
	 *
	 * @since 8.0.13
	 *
	 * @return void
	 */
	public function purge_disabled_feed_queues(): void {
		$manager = $this->manager ? $this->manager : new FeedManager();

		foreach ( $manager->get_all_feed_names() as $feed_name ) {
			$feed_data = get_option( 'wf_feed_' . $feed_name );
			if ( is_string( $feed_data ) && function_exists( 'maybe_unserialize' ) ) {
				$feed_data = maybe_unserialize( $feed_data );
			}
			if ( ! is_array( $feed_data ) || 1 === (int) ( $feed_data['status'] ?? 0 ) ) {
				continue;
			}

			$this->cancel_feed_runs( $feed_name );
		}
	}

	/**
	 * Stop a feed's generation completely: cancel its queued Action Scheduler
	 * work, clear its run state, release the lock, and discard the partial
	 * working file. The LIVE feed file is never touched.
	 *
	 * The user-facing "make it stop" primitive (#68345): called when
	 * auto-update is toggled OFF and for disabled feeds after a plugin
	 * update; a dedicated Stop control can reuse it as is. Cancels the
	 * recurring instance AND the lock-busy re-queue singles (both carry the
	 * feed_name arg), so nothing respawns the run afterwards.
	 *
	 * @since 8.0.13
	 *
	 * @param string $feed_name Feed slug.
	 * @return void
	 */
	public function cancel_feed_runs( string $feed_name ): void {
		$cancelled = $this->cancel_pending_actions_for( $feed_name );

		if ( function_exists( 'delete_transient' ) ) {
			// The in-flight run state: the progress record (a stale
			// 'generating' blocks editing and re-triggers the page-visit
			// runner) and the dead-chain revive budget.
			delete_transient( 'ctxfeed_progress_' . $feed_name );
			delete_transient( 'ctxfeed_chain_revives_' . $feed_name );
		}

		// The wait clock (CBT-807): after a cancel and a re-enable, the next
		// trigger must not inherit the old start time and give up early. The
		// held copies themselves were cancelled above (same hooks, feed_name).
		self::forget_wait( $feed_name );

		if ( $this->batch_calculator ) {
			$this->batch_calculator->release_lock( $feed_name );
		}

		if ( $this->generator && method_exists( $this->generator, 'discard_working_file' ) ) {
			$this->generator->discard_working_file( $feed_name );
		}

		// A batch already running when the user stopped the feed cannot hand
		// the run on, so it does not queue a successor (CBT-735).
		$this->cursor()->clear( $feed_name );

		if ( $cancelled > 0 ) {
			Logger::info( "Cancelled {$cancelled} queued generation action(s): {$feed_name}" );
		}

		if ( $this->feed_logger ) {
			$this->feed_logger->info( $feed_name, 'Generation stopped — queued batches cancelled and run state cleared.' );
			$this->feed_logger->flush( $feed_name );
		}
	}

	/**
	 * Cancel every PENDING Action Scheduler action carrying this feed's name
	 * (batches, finalizes, recurring instances and re-queue singles).
	 *
	 * `as_unschedule_all_actions()` needs an EXACT args match and the batch
	 * hooks carry offset/size/total args, so this walks pending ids and
	 * inspects each action's args instead — same approach as
	 * {@see next_pending_action_id()}. Paged with a hard cap so a
	 * pathological backlog (hundreds of queued batches on a 497K-product
	 * store) still clears in one call without looping forever.
	 *
	 * @since 8.0.13
	 *
	 * @param string $feed_name Feed slug.
	 * @return int Number of actions cancelled.
	 */
	private function cancel_pending_actions_for( string $feed_name ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler' ) ) {
			return 0;
		}

		$cancelled = 0;

		foreach ( array( self::GENERATE_ACTION, self::FINALIZE_ACTION, self::RECURRING_ACTION ) as $hook ) {
			// Offset paging over the pending set. Cancelling REMOVES rows from
			// that set, so the offset only advances past hit-free pages (all
			// other feeds' actions); a page with hits is re-read at the same
			// offset because everything cancelled just shifted out of it. The
			// guard caps the walk at ~5,000 pending actions per hook.
			$offset = 0;
			for ( $guard = 0; $guard < 50; $guard++ ) {
				try {
					$ids = as_get_scheduled_actions(
						array(
							'hook'     => $hook,
							'group'    => self::GROUP,
							'status'   => \ActionScheduler_Store::STATUS_PENDING,
							'per_page' => 100,
							'offset'   => $offset,
						),
						'ids'
					);
				} catch ( \Throwable $e ) {
					break;
				}

				$ids = (array) $ids;
				if ( empty( $ids ) ) {
					break;
				}

				$hit = 0;
				foreach ( $ids as $id ) {
					$action = \ActionScheduler::store()->fetch_action( (int) $id );
					if ( ! $action ) {
						continue;
					}
					$args = $action->get_args();
					$name = isset( $args['feed_name'] ) ? (string) $args['feed_name'] : (string) ( $args[0] ?? '' );
					if ( $name === $feed_name ) {
						\ActionScheduler::store()->cancel_action( (int) $id );
						++$cancelled;
						++$hit;
					}
				}

				if ( 0 === $hit ) {
					if ( count( $ids ) < 100 ) {
						break;
					}
					$offset += count( $ids );
				}
			}
		}

		return $cancelled;
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
	 * While CTX Feed Pro (or free's compatibility layer) is not ready the
	 * trigger is held, not started: a single with `held_at` (the UTC epoch
	 * the occurrence was first held — the episode's own clock) re-checks
	 * every delay up to the cap (CBT-807). A held single is dropped once a
	 * run of the feed COMPLETED after it was held (a manual run finished
	 * first) — never on a save, which stamps `last_updated` too.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-3.3
	 *
	 * @param string $feed_name Feed slug identifier.
	 * @param string $held_at   UTC epoch the occurrence was first held; '' for the series. @since 8.0.38.
	 *
	 * @return void
	 */
	public function handle_recurring( string $feed_name, string $held_at = '' ): void {
		$manager = $this->manager ? $this->manager : new FeedManager();

		// Guard 0: honour the CURRENT auto-update toggle, not the one from
		// when this action was queued. Recurring triggers reach here from two
		// stale paths — the lock-busy 60-second re-queue singles below, and
		// recurring instances already claimed when the user flipped the toggle
		// — and neither is cancelled by turning auto-update off. Without this
		// re-check a user who disables auto-update mid-backlog watches feeds
		// keep starting fresh runs "on their own" (#68345, a 497K-product
		// store where each queued single respawned an hours-long run).
		$feed_data = get_option( 'wf_feed_' . $feed_name );
		if ( is_string( $feed_data ) && function_exists( 'maybe_unserialize' ) ) {
			$feed_data = maybe_unserialize( $feed_data );
		}
		if ( is_array( $feed_data ) && 1 !== (int) ( $feed_data['status'] ?? 0 ) ) {
			Logger::info( "Skipping recurring generation — auto-update is off: {$feed_name}" );
			return;
		}

		$held     = self::held_epoch( $held_at );
		$progress = $manager->get_progress( $feed_name );

		// A trigger held while the plugin stack loaded (CBT-807): when a run
		// of this feed completed after it was held — a manual run finished
		// first — this occurrence is done; running it would start a second
		// full run. `completed_ts` is written only when a run turns completed.
		if ( $held > 0 && 'completed' === ( $progress['status'] ?? '' ) && (int) ( $progress['completed_ts'] ?? 0 ) >= $held ) {
			Logger::info( "Held auto-update dropped — the feed was generated since it was held: {$feed_name}" );
			return;
		}

		// Guard 1: Skip if THIS feed is already generating (self-overlap).
		if ( self::is_run_in_progress( $progress ) ) {
			Logger::info( "Skipping recurring generation — already in progress: {$feed_name}" );
			return;
		}

		// CTX Feed Pro (or CTX Feed's compatibility layer) is not ready: hold
		// the trigger — no lock, no progress, no run token (CBT-807). A feed
		// whose row is gone falls through to Guard 3 as before.
		$wait = self::wait_reason();
		if ( '' !== $wait && is_array( $feed_data ) ) {
			$this->hold_trigger( $feed_name, $wait, $held );
			return;
		}

		// Guard 2: Global lock — only one feed generates at a time.
		// Prevents 5 feeds from running batches simultaneously and
		// competing for the same server memory on shared hosting.
		if ( $this->batch_calculator ) {
			if ( ! $this->batch_calculator->acquire_lock( $feed_name ) ) {
				$lock_holder = $this->batch_calculator->get_lock_holder();
				Logger::info( "Generation queued — lock held by {$lock_holder}: {$feed_name}" );

				if ( ! $this->scheduler_has( 'as_schedule_single_action' ) ) {
					return;
				}

				// Re-queue: try again in 60 seconds. A held occurrence keeps
				// its episode start, so its wait still ends at the cap.
				$args = array(
					'feed_name' => $feed_name,
				);
				if ( $held > 0 ) {
					$args['held_at'] = (string) $held;
				}
				as_schedule_single_action(
					time() + 60,
					self::RECURRING_ACTION,
					$args,
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

		// Start a fresh generation cycle with auto-calculated batch size,
		// marked as a scheduled run (lower batch ceiling, see BatchCalculator).
		$result = $this->schedule_generation( $feed_name, $config, 'scheduled' );

		// The stack stopped being ready between the check above and the
		// start: the lock taken by Guard 2 is ours and nothing ran — release
		// it and hold the trigger. Only for this code: the other refusals
		// mean a live run of this feed owns the lock (CBT-807).
		if ( is_wp_error( $result ) && 'ctxfeed_plugin_updating' === $result->get_error_code() ) {
			if ( $this->batch_calculator ) {
				$this->batch_calculator->release_lock( $feed_name );
			}
			$data = $result->get_error_data();
			$this->hold_trigger( $feed_name, is_array( $data ) && ! empty( $data['reason'] ) ? (string) $data['reason'] : 'pro', $held );
		}
	}

	/**
	 * A held trigger's episode start from its `held_at` arg: a UTC epoch, or
	 * 0 for the series instance (and anything that is not one).
	 *
	 * @since 8.0.38
	 *
	 * @param string $held_at The action's `held_at` arg.
	 * @return int
	 */
	private static function held_epoch( string $held_at ): int {
		return '' !== $held_at && ctype_digit( $held_at ) ? (int) $held_at : 0;
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
	 * @param string $run        Run token; '' for actions queued before 8.0.34. @since 8.0.34.
	 *
	 * @return void
	 */
	public function handle_batch( string $feed_name, int $offset, int $batch_size, int $total, int $attempt = 0, string $run = '' ): void {
		if ( ! $this->generator ) {
			Logger::error( 'FeedGenerator not set in FeedScheduler' );
			return;
		}

		// Stale-chain guard (mirror of handle_finalization's 8.0.13 guard):
		// for any batch past offset 0, a MISSING working file means the run
		// this action belonged to is already over — finalized (the promote
		// renamed the file away) or cancelled (discard deleted it). Running
		// it anyway is what a duplicated chain does: open_append() would
		// RECREATE an empty working file (fopen 'a' creates), its rows could
		// later be promoted over the good feed, and its progress write flips
		// a completed run back to "generating", feeding the dead-chain
		// watchdog a ghost to revive (#68990 log: "products 1608–1607 died
		// 5 times" on a feed that had published fine). Drop it without
		// touching progress, the lock, or the revive budget. Offset 0 is
		// exempt — it legitimately CREATES the working file — and
		// has_working_file() fails OPEN, so a torn-down container behaves
		// exactly as before.
		if ( $offset > 0 && ! $this->generator->has_working_file( $feed_name ) ) {
			Logger::debug( "Stale batch dropped (run already finalized/cancelled): {$feed_name} offset {$offset}" );
			return;
		}

		// One owner per batch (CBT-735). Action Scheduler can hand the same
		// pending action to two runners at once; both copies used to run and
		// each chained its own next batch, so the whole catalogue was written
		// twice into one file (#69480). The claim is a single conditional
		// UPDATE: the second copy — or an action left over from an older or
		// cancelled run — loses it and stops before touching the file, the
		// progress record or the lock.
		if ( '' !== $run && ! $this->cursor()->claim( $feed_name, $run, $offset ) ) {
			Logger::info( "Duplicate batch dropped — another process owns it or the run moved on: {$feed_name} offset {$offset}" );
			return;
		}

		// CTX Feed Pro (or CTX Feed's compatibility layer) is not ready in
		// this request: wait at the SAME offset instead of writing rows
		// without it (CBT-807). Nothing below runs — no file, no marker, no
		// retry counter, no revive budget.
		$wait = self::wait_reason();
		if ( '' !== $wait ) {
			$this->hold_batch( $feed_name, $offset, $batch_size, $total, $attempt, $run, $wait );
			return;
		}
		// After a wait: the wait ends only once this batch ran without being
		// held again (end_wait() below) — a batch held again after it ran
		// keeps the same clock and reaches the cap.
		$waited = $this->note_resume( $feed_name, $offset, true );

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
			'run'        => $run,
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

			// Pro's (or free's) files vanished while the batch ran: an inner
			// catch may have turned a missing class into a default value, so
			// these rows are not trusted — wait and redo the SAME offset (the
			// batch marker rolls the file back). Stat calls only (CBT-807).
			$flux = self::files_in_flux();
			if ( '' !== $flux ) {
				$this->hold_batch( $feed_name, $offset, $batch_size, $total, $attempt, $run, $flux, is_array( $result ) ? $result : array() );
				return;
			}

			$next_batch_size = isset( $result['next_batch_size'] ) ? (int) $result['next_batch_size'] : $batch_size;
			// A time-boxed batch consumed fewer ids than its nominal size: the
			// next offset must follow the ids actually processed.
			$step = isset( $result['step'] ) && (int) $result['step'] > 0 ? (int) $result['step'] : $batch_size;

			// Forward progress resets the dead-chain revive budget.
			delete_transient( 'ctxfeed_chain_revives_' . $feed_name );

			// The batch ran and was not held again: the wait is over (CBT-807).
			$this->end_wait( $feed_name, $waited );

			// Chain next batch or finalize with adaptive batch size. @implements FEED-FRD-3.2.
			$this->schedule_next_or_finalize( $feed_name, $offset, $step, $total, $next_batch_size, $run );
		} catch ( \Throwable $e ) {
			// Catchable failure handled here — disarm the shutdown guard.
			$this->in_flight_batch = null;

			// A class or file missing because a plugin is being replaced right
			// now (a product's FilesInFlux, the Custom Template 2 renderer, a
			// listener before the product loop): wait at the SAME offset
			// instead of halving the batch or failing the run (CBT-807).
			// Checked on EVERY error, before the permanent-failure verdict.
			$flux = self::in_flux_reason( $e );
			if ( '' !== $flux ) {
				$this->hold_batch( $feed_name, $offset, $batch_size, $total, $attempt, $run, $flux );
				return;
			}

			// A CATCHABLE batch-level error (config load, cache warm, file open,
			// a \TypeError/\Error engine bug — NOT a single malformed product,
			// which process_batch isolates and skips). Try a smaller retry of the
			// SAME offset first; only give up (fail the whole run) once retries
			// are exhausted or the size is already at the floor. @implements 8.0.7.
			// A PermanentFailure (Custom Template 2 without Pro, an unsupported
			// format) is deterministic for the feed as configured — halving the
			// batch five times only delays the same failure (CBT-659), so it
			// fails on the first attempt with one log line.
			if ( ! $this->is_permanent_failure( $e, $feed_name )
				&& $this->maybe_schedule_batch_retry( $feed_name, $offset, $batch_size, $total, $attempt, $e->getMessage(), $run ) ) {
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
			if ( $this->maybe_schedule_batch_retry( $batch['feed_name'], $batch['offset'], $batch['batch_size'], $batch['total'], $batch['attempt'], $reason, (string) ( $batch['run'] ?? '' ) ) ) {
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
	 * Whether a batch error is deterministic for the feed as configured, so a
	 * smaller retry cannot help (CBT-659).
	 *
	 * True for any exception implementing {@see PermanentFailure}; extensions
	 * can widen (or narrow) the verdict through the filter — e.g. a Pro engine
	 * whose own guard throws a plain \RuntimeException.
	 *
	 * @since 8.0.27
	 *
	 * @param \Throwable $e         The caught batch error.
	 * @param string     $feed_name Feed slug identifier.
	 * @return bool
	 */
	private function is_permanent_failure( \Throwable $e, string $feed_name ): bool {
		$permanent = $e instanceof PermanentFailure;

		/**
		 * Filter whether a batch failure is permanent (no smaller-batch retry).
		 *
		 * @since 8.0.27
		 *
		 * @param bool       $permanent True to fail the run on the first attempt.
		 * @param \Throwable $e         The caught batch error.
		 * @param string     $feed_name Feed slug identifier.
		 */
		return (bool) apply_filters( 'ctxfeed_batch_failure_is_permanent', $permanent, $e, $feed_name );
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
	 * @param string $run        Run token ('' = no cursor). @since 8.0.34.
	 *
	 * @return bool True if a smaller retry was scheduled (or the run is no
	 *              longer this chain's to fail); false to give up.
	 */
	private function maybe_schedule_batch_retry( string $feed_name, int $offset, int $batch_size, int $total, int $attempt, string $reason, string $run = '' ): bool {
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

		if ( ! $this->scheduler_has( 'as_schedule_single_action' ) ) {
			return false; // No retry possible — the caller fails the run and releases the lock.
		}

		// Re-open the SAME offset on the cursor for the retry. Losing the swap
		// means a newer run or a cancel owns the feed: nothing of ours to
		// retry or to fail (CBT-735).
		if ( '' !== $run && ! $this->cursor()->hand_off( $feed_name, $run, $offset, $offset ) ) {
			return true;
		}

		// Refresh the lock so it survives until the retry runs, then schedule the
		// SAME offset with the smaller size in a fresh request.
		if ( $this->batch_calculator ) {
			$this->batch_calculator->acquire_lock( $feed_name );
		}

		as_schedule_single_action(
			time() + 5,
			self::GENERATE_ACTION,
			self::batch_args( $feed_name, $offset, $smaller, $total, $attempt + 1, $run ),
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
	 * Revive a feed whose batch chain died without a trace.
	 *
	 * A batch killed with SIGKILL (host process limits, kernel OOM, a
	 * segfault inside one product) runs no shutdown handler: the 8.0.7
	 * halved-batch retry never fires, Action Scheduler stamps the action
	 * failed, and the run sits at status=generating forever. When the
	 * progress record has been silent past REVIVE_SILENCE_SECONDS and no
	 * batch/finalize action is pending or in-progress, this re-queues a
	 * batch at the last heartbeat position with HALF the batch size (the
	 * retry-idempotency marker keeps the file consistent). Repeated deaths
	 * halve toward BATCH_FLOOR; after MAX_CHAIN_REVIVES the run is marked
	 * failed with the product window named in the feed log.
	 *
	 * Called from the browser runner (POST /feeds/{id}/run — the open admin
	 * tab polls it, so a watched run self-heals within seconds).
	 *
	 * @since 8.0.10
	 *
	 * @param string $feed_name Feed slug.
	 * @return bool True when a revive (or the give-up) was performed.
	 */
	public function maybe_revive_dead_chain( string $feed_name ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$manager  = $this->manager ? $this->manager : new FeedManager();
		$progress = $manager->get_progress( $feed_name );
		if ( 'generating' !== ( $progress['status'] ?? '' ) ) {
			return false;
		}

		$age = self::seconds_since_touched( $progress );
		if ( null === $age || $age < self::REVIVE_SILENCE_SECONDS ) {
			return false;
		}

		if ( $this->feed_has_live_actions( $feed_name ) ) {
			return false;
		}

		$total   = (int) ( $progress['total'] ?? 0 );
		$current = (int) ( $progress['current'] ?? 0 );

		$attempts = (int) get_transient( 'ctxfeed_chain_revives_' . $feed_name );
		if ( $attempts >= self::MAX_CHAIN_REVIVES ) {
			if ( $this->feed_logger ) {
				// Two different give-ups: mid-catalog means a product window
				// keeps killing PHP; current >= total means every product was
				// written and only the final write step keeps dying — blaming
				// "products 1608–1607" there sent customers hunting a crash
				// that never existed (#68990).
				$message = ( $total > 0 && $current >= $total )
					? sprintf(
						'Generation failed — all %d products were processed but the final write step died %d times in a row. Check that the feed directory is writable and see the server error log.',
						$total,
						$attempts
					)
					: sprintf(
						'Generation failed — the batch at products %d–%d died %d times in a row without an error to catch. One of these products is likely crashing PHP; check the server error log for this window.',
						$current + 1,
						min( $total, $current + BatchCalculator::BATCH_FLOOR ),
						$attempts
					);
				$this->feed_logger->error( $feed_name, $message );
				$this->feed_logger->flush( $feed_name );
			}
			$manager->update_progress( $feed_name, array( 'status' => 'failed' ) );
			if ( $this->batch_calculator ) {
				$this->batch_calculator->release_lock( $feed_name );
			}
			delete_transient( 'ctxfeed_chain_revives_' . $feed_name );
			Logger::error( "Dead batch chain gave up after {$attempts} revives: {$feed_name}" );
			return true;
		}
		// Point the run's cursor at the resume position, conditional on the
		// value just read: a live batch handing off at this instant wins and
		// the revive backs off instead of forking the chain (CBT-735).
		$run    = '';
		$cursor = $this->cursor()->read( $feed_name );
		if ( null !== $cursor && '' !== $cursor['run'] ) {
			$to = ( $total > 0 && $current >= $total ) ? BatchCursor::FINALIZE : $current;
			if ( ! $this->cursor()->reopen( $feed_name, $cursor['raw'], $cursor['run'], $to ) ) {
				return false;
			}
			$run = $cursor['run'];
		}

		set_transient( 'ctxfeed_chain_revives_' . $feed_name, $attempts + 1, HOUR_IN_SECONDS );

		if ( $this->batch_calculator ) {
			$this->batch_calculator->acquire_lock( $feed_name );
		}

		if ( $total > 0 && $current >= $total ) {
			// Everything was written; only the finalize died.
			as_schedule_single_action(
				time(),
				self::FINALIZE_ACTION,
				self::finalize_args( $feed_name, $total, $run ),
				self::GROUP 
			);
			Logger::warning( "Dead chain revived at finalize: {$feed_name}" );
			return true;
		}

		$size = max( BatchCalculator::BATCH_FLOOR, (int) floor( max( 1, (int) ( $progress['batch_size'] ?? 200 ) ) / 2 ) );

		if ( $this->feed_logger ) {
			$this->feed_logger->info(
				$feed_name,
				sprintf(
					'A batch died without a trace (process killed) — resuming at product %s with %s per batch (revive %d/%d)',
					FeedLogger::products( $current ),
					FeedLogger::products( $size ),
					$attempts + 1,
					self::MAX_CHAIN_REVIVES
				)
			);
			$this->feed_logger->flush( $feed_name );
		}
		// Touch the progress record so the silence window restarts and the
		// admin console shows movement again.
		$manager->update_progress( $feed_name, array( 'batch_size' => $size ) );

		as_schedule_single_action(
			time(),
			self::GENERATE_ACTION,
			self::batch_args( $feed_name, $current, $size, $total, 0, $run ),
			self::GROUP
		);
		Logger::warning(
			"Dead batch chain revived: {$feed_name}",
			array(
				'offset'     => $current,
				'batch_size' => $size,
				'revive'     => $attempts + 1,
			) 
		);

		return true;
	}

	/**
	 * Whether any batch or finalize action for this feed is pending or
	 * in-progress (a live chain).
	 *
	 * @since 8.0.10
	 *
	 * @param string $feed_name Feed slug.
	 * @return bool
	 */
	protected function feed_has_live_actions( string $feed_name ): bool {
		if ( ! $this->scheduler_has( 'as_get_scheduled_actions' ) ) {
			return true; // Cannot tell — assume alive rather than double-schedule.
		}
		foreach ( array( self::GENERATE_ACTION, self::FINALIZE_ACTION ) as $hook ) {
			foreach ( array( 'pending', 'in-progress' ) as $status ) {
				try {
					$actions = as_get_scheduled_actions(
						array(
							'hook'     => $hook,
							'group'    => self::GROUP,
							'status'   => $status,
							'per_page' => 50,
						)
					);
				} catch ( \Throwable $e ) {
					return true; // Cannot tell — assume alive rather than double-schedule.
				}
				foreach ( (array) $actions as $action ) {
					if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
						continue;
					}
					$args = (array) $action->get_args();
					if ( ( $args['feed_name'] ?? '' ) === $feed_name ) {
						return true;
					}
				}
			}
		}

		return false;
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

		// The run waits for CTX Feed Pro (or CTX Feed's compatibility layer,
		// CBT-807): never revive, never drive the chain. Run at most ONE
		// action of a held run that is already due — or of any run when it
		// is overdue by more than a check interval — so the hold re-check
		// and the cap still happen from an open tab on a site whose WP-Cron
		// is dead, and report 0 so the app's drive loop stops (its status
		// poll continues).
		if ( '' !== self::wait_reason() ) {
			$this->run_one_due_action( $feed_name );
			return 0;
		}

		// Revive a dead chain first: a batch killed too hard for any handler
		// (SIGKILL, segfault) leaves status=generating with no queued action —
		// nothing would ever run again without this.
		$this->maybe_revive_dead_chain( $feed_name );

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
	 * @param int    $due_by    Only actions due at or before this UTC epoch; 0 = any. @since 8.0.38.
	 * @return int Action id, or 0 when none pending for this feed.
	 */
	private function next_pending_action_id( string $feed_name, int $due_by = 0 ): int {
		if ( ! $this->scheduler_has( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return 0;
		}
		foreach ( array( self::GENERATE_ACTION, self::FINALIZE_ACTION ) as $hook ) {
			$query = array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				// An action CLAIMED by the WP-Cron queue runner still reads
				// as status=pending until it executes. Processing it here
				// too ran the whole chain TWICE in parallel (~3s apart —
				// #68990's interleaved log). Only unclaimed actions are
				// ours to drive; the claim holder will run the rest.
				'claimed'  => false,
				'per_page' => 50,
				'orderby'  => 'date',
				'order'    => 'ASC',
			);
			if ( $due_by > 0 ) {
				$query['date']         = $due_by;
				$query['date_compare'] = '<=';
			}
			$ids = as_get_scheduled_actions( $query, 'ids' );
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
	 * @param string $run       Run token; '' for actions queued before 8.0.34. @since 8.0.34.
	 *
	 * @return void
	 */
	public function handle_finalization( string $feed_name, int $total, string $run = '' ): void {
		if ( ! $this->generator ) {
			Logger::error( 'FeedGenerator not set in FeedScheduler' );
			return;
		}

		// Duplicate-finalize guard: promotion RENAMES the working file away,
		// so a second FINALIZE_ACTION for the same run (a dead-chain revive
		// racing a slow-but-alive finalize, an Action Scheduler re-run) finds
		// no working file. Without this check it would recreate an EMPTY one
		// via open_append, write just the footer newline, and — because
		// progress still reports the full product total — rename a 1-byte
		// file over the feed the first finalize just published (#68345).
		// Checked BEFORE touching progress so the completed status survives.
		if ( method_exists( $this->generator, 'has_working_file' ) && ! $this->generator->has_working_file( $feed_name ) ) {
			Logger::info( "Finalize skipped — no working file, the run was already finalized: {$feed_name}" );
			return;
		}

		// One finalize per run: two copies racing would both write the footer
		// and promote (CBT-735).
		if ( '' !== $run && ! $this->cursor()->claim( $feed_name, $run, BatchCursor::FINALIZE ) ) {
			Logger::info( "Duplicate finalize dropped — another process owns it or the run moved on: {$feed_name}" );
			return;
		}

		// CTX Feed Pro (or CTX Feed's compatibility layer) is not ready: the
		// footer (Custom Template 2 is a Pro engine), the promote, the Pro
		// listeners, the upload and the outcome events all wait (CBT-807).
		$wait = self::wait_reason();
		if ( '' !== $wait ) {
			$this->hold_finalize( $feed_name, $total, $run, $wait );
			return;
		}
		// After a wait the clock lives until the footer is out (end_wait()
		// below): finalize held again keeps it and reaches the cap.
		$waited = $this->note_resume( $feed_name, $total );

		// Update status to finalizing.
		$manager = new FeedManager();
		$manager->update_progress(
			$feed_name,
			array(
				'current' => $total,
				'total'   => $total,
				'status'  => 'finalizing',
			) + FeedManager::WAIT_DEFAULTS
		);

		try {
			$this->generator->finalize( $feed_name );
		} catch ( \Throwable $e ) {
			// A class or file missing because a plugin is being replaced, thrown
			// BEFORE the footer was written (the footer renders first): the
			// working file is still a clean body, so wait and finalize again.
			// After the footer the file changed for good — fail as before
			// (CBT-807).
			$flux = self::in_flux_reason( $e );
			if ( '' !== $flux && ! ( method_exists( $this->generator, 'wrote_footer' ) && $this->generator->wrote_footer() ) ) {
				$this->hold_finalize( $feed_name, $total, $run, $flux );
				return;
			}

			// FeedGenerator::finalize() releases the global generation lock only
			// at its very end; a throw before that (open_append / write_footer /
			// promote) would otherwise leave the site-wide lock stuck for its
			// full 600s TTL, blocking every other feed. Route the failure through
			// fail_generation() — the same handler the batch path uses — so the
			// lock is released and the feed marked failed. It deliberately does
			// NOT re-throw (this runs in the Action Scheduler worker, where a
			// re-throw would only trigger a retry storm on a deterministic error).
			$this->fail_generation( $feed_name, 0, $e );
			$this->end_wait( $feed_name, $waited, false );
			return;
		}

		// The progress record's waiting keys went with the 'finalizing' write.
		$this->end_wait( $feed_name, $waited, false );

		Logger::info( "Feed finalized: {$feed_name}" );
	}

	// =====================================================================
	// Waiting for the plugin stack (CBT-807).
	// =====================================================================

	/**
	 * Whether a caught error means a plugin's files are being replaced right
	 * now ('pro' / 'free'), '' otherwise. Never throws.
	 *
	 * @since 8.0.38
	 *
	 * @param \Throwable $e The caught error.
	 * @return string
	 */
	private static function in_flux_reason( \Throwable $e ): string {
		if ( $e instanceof FilesInFlux ) {
			return $e->reason();
		}

		try {
			return is_callable( array( Bootstrap::class, 'in_flux_reason' ) ) ? (string) Bootstrap::in_flux_reason( $e ) : '';
		} catch ( \Throwable $ignored ) {
			return '';
		}
	}

	/**
	 * After a batch: whether Pro's or free's files went missing while it ran
	 * ('pro' / 'free'), '' otherwise. Stat calls only. Never throws.
	 *
	 * @since 8.0.38
	 *
	 * @return string
	 */
	private static function files_in_flux(): string {
		if ( null !== self::$forced_files_in_flux ) {
			return self::$forced_files_in_flux;
		}

		try {
			return is_callable( array( Bootstrap::class, 'files_in_flux_reason' ) ) ? (string) Bootstrap::files_in_flux_reason() : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * The injected FeedManager, or a fresh one.
	 *
	 * @since 8.0.38
	 *
	 * @return FeedManager
	 */
	private function manager(): FeedManager {
		return $this->manager ? $this->manager : new FeedManager();
	}

	/**
	 * The run's wait clock, or null when none is running. A clock whose run
	 * went on after the wait (resumed) and then had no hold for longer than
	 * wait_episode_gap() belongs to an episode that ended and counts as
	 * none. A wait that never resumed always counts from its start, however
	 * far apart the checks are (a 5-minute system cron, a quiet night), so
	 * the cap is reached.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return array{run:string,since:int,last:int,resumed:bool,marker:array|null}|null
	 */
	private function read_wait( string $feed_name ): ?array {
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}
		$clock = get_option( self::wait_option( $feed_name ), false );
		if ( ! is_array( $clock ) || ! isset( $clock['run'], $clock['since'] ) || (int) $clock['since'] <= 0 ) {
			return null;
		}

		$now = time();
		// A start in the future (clock skew) counts from now.
		$since = min( $now, (int) $clock['since'] );
		$last  = isset( $clock['last'] ) ? min( $now, (int) $clock['last'] ) : $since;
		if ( ! empty( $clock['resumed'] ) && $now - $last > self::wait_episode_gap() ) {
			return null;
		}

		return array(
			'run'     => (string) $clock['run'],
			'since'   => $since,
			'last'    => $last,
			'resumed' => ! empty( $clock['resumed'] ),
			'marker'  => isset( $clock['marker'] ) && is_array( $clock['marker'] ) ? $clock['marker'] : null,
		);
	}

	/**
	 * Write a run's wait clock (not autoloaded): its start, and this hold.
	 *
	 * @since 8.0.38
	 *
	 * @param string     $feed_name Feed slug.
	 * @param string     $run       Run token ('' = legacy action).
	 * @param string     $reason    'pro' or 'free'.
	 * @param int        $since     Start of the wait.
	 * @param int        $now       This hold.
	 * @param bool       $resumed   The run went on after the wait, then held again.
	 * @param array|null $marker    Batch marker of the held offset (outlives its 1-hour transient here).
	 * @return void
	 */
	private function write_wait( string $feed_name, string $run, string $reason, int $since, int $now, bool $resumed = false, ?array $marker = null ): void {
		if ( function_exists( 'update_option' ) ) {
			$clock = array(
				'run'     => $run,
				'since'   => $since,
				'reason'  => $reason,
				'last'    => $now,
				'resumed' => $resumed,
			);
			if ( null !== $marker ) {
				$clock['marker'] = $marker;
			}
			update_option( self::wait_option( $feed_name ), $clock, false );
		}
	}

	/**
	 * The plugin a run waits for, for log lines.
	 *
	 * @since 8.0.38
	 *
	 * @param string $reason 'pro' or 'free'.
	 * @return string
	 */
	private static function wait_subject( string $reason ): string {
		return 'free' === $reason ? 'CTX Feed' : 'CTX Feed Pro';
	}

	/**
	 * The wait maximum in words ("10 minutes"), for log lines.
	 *
	 * @since 8.0.38
	 *
	 * @return string
	 */
	private static function wait_span(): string {
		$minutes = max( 1, (int) round( self::wait_max() / 60 ) );

		return 1 === $minutes ? '1 minute' : $minutes . ' minutes';
	}

	/**
	 * The check interval in words ("every minute"), for log lines.
	 *
	 * @since 8.0.38
	 *
	 * @return string
	 */
	private static function wait_every(): string {
		$delay = self::wait_delay();

		return 60 === $delay ? 'every minute' : 'every ' . $delay . ' seconds';
	}

	/**
	 * Whether the feed still exists. A run (or trigger) of a deleted feed has
	 * nothing left to wait for.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return bool
	 */
	private static function feed_exists( string $feed_name ): bool {
		return ! function_exists( 'get_option' ) || false !== get_option( 'wf_feed_' . $feed_name, false );
	}

	/**
	 * A held run of a deleted feed: stop holding — forget the clock and the
	 * run state, let go of the lock. No failure, no notice: there is no feed
	 * to report on (a feed re-created under the same slug starts clean).
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return void
	 */
	private function drop_deleted_wait( string $feed_name ): void {
		self::forget_wait( $feed_name );
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'ctxfeed_progress_' . $feed_name );
		}
		if ( $this->batch_calculator ) {
			$this->batch_calculator->release_lock( $feed_name );
		}
		$this->cursor()->clear( $feed_name );
		Logger::info( "Held run ended — the feed was deleted: {$feed_name}" );
	}

	/**
	 * The stack is ready in this request: when the progress record says the
	 * run waited, log once that the plugin is back. The wait itself ends in
	 * end_wait(), after the step ran without being held again — a step held
	 * again after it ran keeps the same clock and reaches the cap.
	 * One progress read per action; a clock read and write only after a wait.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @param int    $position  Offset the run resumes at (the total for finalize).
	 * @param bool   $batch     A batch resumes (its kept batch marker is put back).
	 * @return string The reason the run waited for, '' when it did not wait.
	 */
	private function note_resume( string $feed_name, int $position, bool $batch = false ): string {
		$progress = $this->manager()->get_progress( $feed_name );
		$reason   = isset( $progress['waiting'] ) ? (string) $progress['waiting'] : '';
		if ( '' === $reason ) {
			return '';
		}

		$clock = $this->read_wait( $feed_name );
		// The batch marker of this offset, kept by the wait: back in place if
		// its 1-hour transient expired, so the redo rolls the file back.
		if ( $batch && null !== $clock && is_array( $clock['marker'] ) && (int) ( $clock['marker']['offset'] ?? -1 ) === $position
			&& $this->generator && method_exists( $this->generator, 'restore_batch_marker' ) ) {
			$this->generator->restore_batch_marker( $feed_name, $clock['marker'] );
		}
		if ( null !== $clock && $clock['resumed'] ) {
			return $reason; // Said once for this wait.
		}
		if ( null !== $clock ) {
			$this->write_wait( $feed_name, $clock['run'], $reason, $clock['since'], $clock['last'], true, $clock['marker'] );
		}

		if ( $this->feed_logger ) {
			$total = isset( $progress['total'] ) ? (int) $progress['total'] : 0;
			$this->feed_logger->info(
				$feed_name,
				$total > 0 && $position >= $total
					? sprintf( '%s is back — writing the feed file.', self::wait_subject( $reason ) )
					: sprintf( '%1$s is back — resuming after %2$s.', self::wait_subject( $reason ), FeedLogger::products( $position ) )
			);
			$this->feed_logger->flush( $feed_name );
		}

		return $reason;
	}

	/**
	 * The step after a wait ran without being held again: the wait is over —
	 * drop the clock and the progress record's waiting keys.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $waited    What note_resume() returned.
	 * @param bool   $progress  Also reset the progress record's waiting keys.
	 * @return void
	 */
	private function end_wait( string $feed_name, string $waited, bool $progress = true ): void {
		if ( '' === $waited ) {
			return;
		}
		self::forget_wait( $feed_name );
		if ( $progress ) {
			$this->manager()->update_progress( $feed_name, FeedManager::WAIT_DEFAULTS );
		}
	}

	/**
	 * Hold an auto-update trigger while the stack is not ready: a single with
	 * `held_at` (the episode's start, UTC epoch) re-checks every delay. No
	 * lock, no progress write, no run token, no shared clock — the episode
	 * travels in the action itself, so it can neither outlive the occurrence
	 * nor be cut short by an earlier one. At the cap the occurrence is
	 * skipped (the series continues); while that stop is fresh (same reason,
	 * under 24 h) later occurrences are skipped at once.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $reason    'pro' or 'free'.
	 * @param int    $held      Episode start (UTC epoch), 0 on the series instance.
	 * @return void
	 */
	private function hold_trigger( string $feed_name, string $reason, int $held ): void {
		if ( ! self::feed_exists( $feed_name ) ) {
			Logger::info( "Held auto-update ended — the feed was deleted: {$feed_name}" );
			return;
		}

		$now = time();
		if ( $held <= 0 ) {
			// The series instance (or a plain lock-busy re-queue): one held
			// single per feed — the series' own next instance has no held_at,
			// so it never counts.
			if ( $this->has_held_trigger( $feed_name ) ) {
				return;
			}
			if ( $this->stopped_recently( $feed_name, $reason ) ) {
				$this->skip_stopped_trigger( $feed_name, $reason );
				return;
			}
			$since = $now;
			if ( $this->feed_logger ) {
				$this->feed_logger->info(
					$feed_name,
					sprintf( 'Scheduled update waiting for %1$s — it starts on its own once it is ready (checked %2$s).', self::wait_subject( $reason ), self::wait_every() )
				);
				$this->feed_logger->flush( $feed_name );
			}
		} else {
			$since = min( $now, $held );
		}

		if ( $now - $since >= self::wait_max() ) {
			$this->give_up_trigger( $feed_name, $reason );
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			Logger::error( "Action Scheduler is not available — the held auto-update cannot re-check: {$feed_name}" );
			return;
		}
		// The running held single is not pending, so it always queues its
		// successor; a racing duplicate finds that successor and stops.
		if ( $held > 0 && $this->has_held_trigger( $feed_name ) ) {
			return;
		}

		as_schedule_single_action(
			$now + self::wait_delay(),
			self::RECURRING_ACTION,
			array(
				'feed_name' => $feed_name,
				'held_at'   => (string) $since,
			),
			self::GROUP
		);

		Logger::info( "Auto-update held — waiting for the plugin stack ({$reason}): {$feed_name}" );
	}

	/**
	 * Whether the feed's last wait stopped at the cap for this same reason
	 * less than 24 hours ago (the stop record, kept until the feed completes
	 * a run). Never throws.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $reason    'pro' or 'free'.
	 * @return bool
	 */
	private function stopped_recently( string $feed_name, string $reason ): bool {
		try {
			if ( ! class_exists( UpdateWaitNotice::class ) ) {
				return false;
			}
			$stopped = UpdateWaitNotice::stopped_for( $feed_name );

			return null !== $stopped && $reason === $stopped['reason'];
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Skip an occurrence at once: the same wait stopped at the cap recently
	 * and nothing changed. One line in the feed log (and the system log) —
	 * no held copies, no progress record, no second failure email.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $reason    'pro' or 'free'.
	 * @return void
	 */
	private function skip_stopped_trigger( string $feed_name, string $reason ): void {
		$message = sprintf(
			'Scheduled update skipped — %1$s is still not available (an earlier update of this feed already waited %2$s for it). The previous feed file was kept; the next scheduled run tries again.',
			self::wait_subject( $reason ),
			self::wait_span()
		);
		Logger::info( "{$message} Feed: {$feed_name}" );
		if ( $this->feed_logger ) {
			$this->feed_logger->info( $feed_name, $message );
			$this->feed_logger->flush( $feed_name );
		}
	}

	/**
	 * The trigger waited past the cap: skip this occurrence and say so —
	 * feed log, system log, a failed progress record (fires
	 * `ctxfeed_feed_run_failed` once), the stop record / admin notice — and
	 * cancel any other held single of the feed, so none starts a fresh wait.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $reason    'pro' or 'free'.
	 * @return void
	 */
	private function give_up_trigger( string $feed_name, string $reason ): void {
		$this->cancel_held_triggers( $feed_name );

		$message = sprintf(
			'Scheduled update skipped — %1$s was not available for %2$s. The previous feed file was kept; the next scheduled run tries again.',
			self::wait_subject( $reason ),
			self::wait_span()
		);
		Logger::error( "{$message} Feed: {$feed_name}" );
		if ( $this->feed_logger ) {
			$this->feed_logger->error( $feed_name, $message );
			$this->feed_logger->flush( $feed_name );
		}

		$this->manager()->update_progress(
			$feed_name,
			array(
				'status'  => 'failed',
				'trigger' => 'scheduled',
			) + FeedManager::WAIT_DEFAULTS
		);
		$this->record_notice( $feed_name, $reason );
	}

	/**
	 * Cancel every pending held auto-update single (one with `held_at`) of
	 * the feed. The series and other feeds are untouched. Never throws.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return void
	 */
	private function cancel_held_triggers( string $feed_name ): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler' ) ) {
			return;
		}

		try {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => self::RECURRING_ACTION,
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => 100,
				),
				'ids'
			);
			foreach ( (array) $ids as $id ) {
				$action = \ActionScheduler::store()->fetch_action( (int) $id );
				if ( ! $action || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}
				$args = (array) $action->get_args();
				if ( isset( $args['held_at'] ) && ( $args['feed_name'] ?? '' ) === $feed_name ) {
					\ActionScheduler::store()->cancel_action( (int) $id );
				}
			}
		} catch ( \Throwable $e ) {
			Logger::debug( 'Could not cancel held auto-update copies: ' . $e->getMessage() );
		}
	}

	/**
	 * Hold a batch at the SAME offset while the stack is not ready.
	 *
	 * Re-opens the claimed offset on the cursor, refreshes the lock, keeps
	 * the progress record alive (status generating + waiting keys), keeps
	 * the batch marker and the product-ID snapshot alive, and queues ONE
	 * identical copy a delay later. Never touches the working file, the
	 * retry counter, fail_generation() or the revive budget. At the cap the
	 * run stops (give_up_waiting()). A deleted feed stops holding.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name  Feed slug.
	 * @param int    $offset     Offset to resume at.
	 * @param int    $batch_size Batch size.
	 * @param int    $total      Total products.
	 * @param int    $attempt    Retry attempt (kept as is).
	 * @param string $run        Run token ('' = legacy action).
	 * @param string $reason     'pro' or 'free'.
	 * @param array  $result     The finished batch's result when its rows will be written again (counts are taken back out).
	 * @return void
	 */
	private function hold_batch( string $feed_name, int $offset, int $batch_size, int $total, int $attempt, string $run, string $reason, array $result = array() ): void {
		if ( ! self::feed_exists( $feed_name ) ) {
			$this->drop_deleted_wait( $feed_name );
			return;
		}

		$now   = time();
		$clock = $this->read_wait( $feed_name );
		$first = null === $clock || $clock['run'] !== $run;
		$since = $first ? $now : (int) $clock['since'];
		$again = ! $first && $clock['resumed'];

		if ( $now - $since >= self::wait_max() ) {
			$this->give_up_waiting( $feed_name, $reason );
			return;
		}

		// Re-open the SAME offset for the held copy. Losing the swap means a
		// newer run or a cancel owns the feed now — nothing of ours to hold.
		if ( '' !== $run && ! $this->cursor()->hand_off( $feed_name, $run, $offset, $offset ) ) {
			Logger::info( "Held batch ended — the run was superseded or cancelled: {$feed_name} offset {$offset}" );
			return;
		}

		// Every hold: the batch marker (1 h) rolls a redone batch's rows back
		// and the product-ID snapshot (3 h) keeps the offsets stable — an
		// expired one would duplicate rows or silently shift every offset.
		// The marker of THIS offset is also kept in the wait clock (an
		// option, no expiry): a redo more than an hour after the last hold —
		// a quiet night with visit-driven cron — puts it back first.
		$marker = null;
		if ( $this->generator && method_exists( $this->generator, 'keep_batch_marker' ) ) {
			$marker = $this->generator->keep_batch_marker( $feed_name );
		}
		if ( ! is_array( $marker ) || ! isset( $marker['offset'] ) || (int) $marker['offset'] !== $offset ) {
			$marker = ! $first && is_array( $clock['marker'] ) && (int) ( $clock['marker']['offset'] ?? -1 ) === $offset ? $clock['marker'] : null;
		}
		( new ProductQuery() )->extend_snapshot( $feed_name );

		$this->write_wait( $feed_name, $run, $reason, $since, $now, false, $marker );

		// handle_batch() never reached its own refresh: keep the lock alive.
		if ( $this->batch_calculator ) {
			$this->batch_calculator->acquire_lock( $feed_name );
		}

		$manager = $this->manager();
		$fields  = array(
			'status'        => 'generating',
			'current'       => $offset,
			'waiting'       => $reason,
			'waiting_since' => $since,
		);
		if ( array() !== $result ) {
			// The finished batch is written again: take its counts back out.
			$progress                = $manager->get_progress( $feed_name );
			$fields['written_total'] = max( 0, (int) ( $progress['written_total'] ?? 0 ) - (int) ( $result['count'] ?? 0 ) );
			$fields['skipped_total'] = max( 0, (int) ( $progress['skipped_total'] ?? 0 ) - (int) ( $result['errors'] ?? 0 ) );
			$fields['batches_done']  = max( 0, (int) ( $progress['batches_done'] ?? 0 ) - 1 );
		}
		$manager->update_progress( $feed_name, $fields );

		if ( ( $first || $again ) && $this->feed_logger ) {
			$this->feed_logger->info(
				$feed_name,
				sprintf(
					'Waiting for %1$s%2$s — generation pauses %3$s and resumes on its own (checked %4$s).',
					self::wait_subject( $reason ),
					$again ? ' again' : '',
					0 === $offset ? 'before the first product' : 'after ' . FeedLogger::products( $offset ),
					self::wait_every()
				)
			);
			$this->feed_logger->flush( $feed_name );
		}

		$this->queue_held_copy( self::GENERATE_ACTION, self::batch_args( $feed_name, $offset, $batch_size, $total, $attempt, $run ) );
	}

	/**
	 * Hold the finalize step while the stack is not ready — same contract as
	 * hold_batch(), at the cursor's `final` position.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @param int    $total     Total products.
	 * @param string $run       Run token ('' = legacy action).
	 * @param string $reason    'pro' or 'free'.
	 * @return void
	 */
	private function hold_finalize( string $feed_name, int $total, string $run, string $reason ): void {
		if ( ! self::feed_exists( $feed_name ) ) {
			$this->drop_deleted_wait( $feed_name );
			return;
		}

		$now   = time();
		$clock = $this->read_wait( $feed_name );
		$first = null === $clock || $clock['run'] !== $run;
		$since = $first ? $now : (int) $clock['since'];
		$again = ! $first && $clock['resumed'];

		if ( $now - $since >= self::wait_max() ) {
			$this->give_up_waiting( $feed_name, $reason );
			return;
		}

		if ( '' !== $run && ! $this->cursor()->hand_off( $feed_name, $run, BatchCursor::FINALIZE, BatchCursor::FINALIZE ) ) {
			Logger::info( "Held finalize ended — the run was superseded or cancelled: {$feed_name}" );
			return;
		}

		$this->write_wait( $feed_name, $run, $reason, $since, $now );

		if ( $this->batch_calculator ) {
			$this->batch_calculator->acquire_lock( $feed_name );
		}

		$this->manager()->update_progress(
			$feed_name,
			array(
				'status'        => 'generating',
				'current'       => $total,
				'total'         => $total,
				'waiting'       => $reason,
				'waiting_since' => $since,
			)
		);

		if ( ( $first || $again ) && $this->feed_logger ) {
			$this->feed_logger->info(
				$feed_name,
				sprintf( 'Waiting for %1$s%2$s — the feed file is written on its own once it is ready (checked %3$s).', self::wait_subject( $reason ), $again ? ' again' : '', self::wait_every() )
			);
			$this->feed_logger->flush( $feed_name );
		}

		$this->queue_held_copy( self::FINALIZE_ACTION, self::finalize_args( $feed_name, $total, $run ) );
	}

	/**
	 * A batch or finalize waited past the cap: stop the run. Status failed
	 * (fires `ctxfeed_feed_run_failed` once), the previous feed file kept,
	 * the lock released, the reason in the feed log + system log and the
	 * stop record / admin notice. No retry, no revive.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $reason    'pro' or 'free'.
	 * @return void
	 */
	private function give_up_waiting( string $feed_name, string $reason ): void {
		self::forget_wait( $feed_name );

		$message = sprintf(
			'Generation stopped — %1$s was not available for %2$s. The previous feed file was kept; the next run starts from the beginning.',
			self::wait_subject( $reason ),
			self::wait_span()
		);
		Logger::error( "{$message} Feed: {$feed_name}" );
		if ( $this->feed_logger ) {
			$this->feed_logger->error( $feed_name, $message );
			$this->feed_logger->flush( $feed_name );
		}

		$this->manager()->update_progress( $feed_name, array( 'status' => 'failed' ) + FeedManager::WAIT_DEFAULTS );

		if ( $this->batch_calculator ) {
			$this->batch_calculator->release_lock( $feed_name );
		}

		$this->record_notice( $feed_name, $reason );
	}

	/**
	 * Remember a stopped wait — the per-feed stop record behind the admin
	 * notice (24 h) and the at-once skip of the next occurrences. Never
	 * throws.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @param string $reason    'pro' or 'free'.
	 * @return void
	 */
	private function record_notice( string $feed_name, string $reason ): void {
		try {
			if ( class_exists( UpdateWaitNotice::class ) ) {
				UpdateWaitNotice::record( $feed_name, $reason );
			}
		} catch ( \Throwable $e ) {
			Logger::warning( 'Could not record the update-wait notice: ' . $e->getMessage() );
		}
	}

	/**
	 * Queue the held copy of the current action a delay later — unless an
	 * identical pending copy exists (a duplicate of this action already held
	 * it). The running action itself is not pending, so a re-hold always
	 * queues its successor.
	 *
	 * @since 8.0.38
	 *
	 * @param string $hook Action hook.
	 * @param array  $args The action's args, exactly.
	 * @return void
	 */
	private function queue_held_copy( string $hook, array $args ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			Logger::error( "Action Scheduler is not available — the waiting run cannot re-check: {$hook}" );
			return;
		}
		if ( $this->has_pending_copy( $hook, $args ) ) {
			return;
		}

		as_schedule_single_action( time() + self::wait_delay(), $hook, $args, self::GROUP );
	}

	/**
	 * Whether a pending action with exactly these args exists. Action
	 * Scheduler's own `$unique` is not used: 4.0 matches the running action
	 * itself and 3.9 matches by hook only.
	 *
	 * @since 8.0.38
	 *
	 * @param string $hook Action hook.
	 * @param array  $args Exact args.
	 * @return bool
	 */
	private function has_pending_copy( string $hook, array $args ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		try {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'args'     => $args,
					'group'    => self::GROUP,
					'status'   => 'pending',
					'per_page' => 1,
				),
				'ids'
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return ! empty( $ids );
	}

	/**
	 * Whether a held auto-update single (one with `held_at`) is pending for
	 * the feed, due within the next delay.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return bool
	 */
	private function has_held_trigger( string $feed_name ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		try {
			$actions = as_get_scheduled_actions(
				array(
					'hook'         => self::RECURRING_ACTION,
					'group'        => self::GROUP,
					'status'       => 'pending',
					'date'         => time() + self::wait_delay() + 30,
					'date_compare' => '<=',
					'per_page'     => 100,
				)
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		foreach ( (array) $actions as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
				continue;
			}
			$args = (array) $action->get_args();
			if ( isset( $args['held_at'] ) && ( $args['feed_name'] ?? '' ) === $feed_name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Browser runner while waiting: run at most ONE of the feed's pending
	 * actions, so the re-check and the cap happen without WP-Cron. Only an
	 * action overdue by more than a check interval — cron gets a full delay
	 * to run it first, so an open tab never takes a held copy cron could
	 * run with the stack ready. Never when only THIS request sees the wait
	 * (WordPress recovery mode, Pro not loaded on this request by a
	 * per-request plugin filter): cron is unaffected there, and a copy held
	 * from here would make a healthy run stop at the cap. Never throws.
	 *
	 * @since 8.0.38
	 *
	 * @param string $feed_name Feed slug.
	 * @return void
	 */
	private function run_one_due_action( string $feed_name ): void {
		try {
			if ( self::wait_is_this_request_only() ) {
				return;
			}

			$action_id = $this->next_pending_action_id( $feed_name, time() - self::wait_delay() );
			if ( $action_id > 0 && class_exists( '\ActionScheduler' ) ) {
				\ActionScheduler::runner()->process_action( $action_id, 'ctxfeed-browser-runner' );
			}
		} catch ( \Throwable $e ) {
			Logger::debug( 'Held action run from the browser failed: ' . $e->getMessage() );
		}
	}
}
