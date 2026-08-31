<?php
/**
 * BatchCalculator — Auto-calculated adaptive batch sizing.
 *
 * Reads PHP ini values (memory_limit, max_execution_time) at runtime to
 * compute a safe initial batch size. After each batch, records actual
 * performance and adapts the next batch size using a rolling window of
 * the last 3 measurements with 50% dampening.
 *
 * History is persisted per-feed via WordPress transients so the rolling
 * window survives across Action Scheduler requests (each batch runs in
 * a fresh PHP process).
 *
 * When multiple feeds share the same interval, the calculator uses a
 * global generation lock to ensure only one feed generates at a time,
 * preventing memory contention on shared hosting.
 *
 * The #1 customer loss driver for 10 years was fixed batch sizes failing
 * on shared hosting (128 MB, 30 sec) with large catalogs (100 K+ products).
 * This class solves it by auto-calculating per-server optimal batch sizes.
 *
 * @package    CTXFeed
 * @subpackage V8/Feed
 * @since      8.0.0
 * @implements FEED-FRD-11.1, FEED-FRD-11.2, FEED-FRD-11.3, FEED-FRD-11.4, FEED-FRD-11.5
 */

namespace CTXFeed\V8\Feed;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adaptive batch size calculator for feed generation.
 *
 * @since 8.0.0
 */
class BatchCalculator {

	/**
	 * Estimated memory usage per product (bytes).
	 *
	 * Includes WC product object, meta cache, DTO, and transform overhead.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.5
	 * @var int
	 */
	const MEMORY_PER_PRODUCT = 153600; // 150 KB.

	/**
	 * Estimated time per product (seconds).
	 *
	 * Includes query, cache warm, filter, resolve, transform, and write.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.5
	 * @var float
	 */
	const TIME_PER_PRODUCT = 0.05;

	/**
	 * Time safety margin (70% of max_execution_time).
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.5
	 * @var float
	 */
	const TIME_SAFETY_MARGIN = 0.70;

	/**
	 * Memory safety margin (60% of memory_limit).
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.5
	 * @var float
	 */
	const MEMORY_SAFETY_MARGIN = 0.60;

	/**
	 * WordPress + WooCommerce overhead as fraction of memory_limit.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.5
	 * @var float
	 */
	const WP_OVERHEAD_PERCENT = 0.40;

	/**
	 * Minimum batch size to prevent degenerate 1-product batches.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.5
	 * @var int
	 */
	const BATCH_FLOOR = 25;

	/**
	 * Maximum batch size to prevent memory spikes.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.5
	 * @var int
	 */
	const BATCH_CEILING = 500;

	/**
	 * Maximum allowed batch size change per iteration (50%).
	 *
	 * Prevents wild oscillation between batches.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.5
	 * @var float
	 */
	const MAX_BATCH_CHANGE = 0.50;

	/**
	 * Generation lock TTL in seconds.
	 *
	 * If a lock is held longer than this, it's considered stale and
	 * can be overridden. Set to 10 minutes — much longer than any
	 * single batch but short enough to recover from crashes.
	 *
	 * @since 8.0.0
	 * @var int
	 */
	const LOCK_TTL = 600;

	/**
	 * Rolling window of batch performance measurements (in memory).
	 *
	 * Each entry: array( 'count' => int, 'time' => float, 'memory' => int,
	 *                     'time_per' => float, 'memory_per' => int ).
	 * Keeps only the last 3 entries for dampened averaging.
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private $history = array();

	/**
	 * Feed name for per-feed history persistence.
	 *
	 * When set, record_batch() and calculate_next() persist history
	 * to a WordPress transient so the rolling window survives across
	 * Action Scheduler requests.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private $feed_name = '';

	/**
	 * Set the feed name for per-feed history persistence.
	 *
	 * Must be called before record_batch() / calculate_next() for
	 * the rolling window to persist across PHP requests.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed slug identifier.
	 *
	 * @return void
	 */
	public function set_feed_name( string $feed_name ): void {
		$this->feed_name = $feed_name;
		$this->load_history();
	}

	/**
	 * Calculate initial batch size based on server limits.
	 *
	 * Reads PHP ini values for memory_limit and max_execution_time,
	 * computes a memory-safe and time-safe batch count, takes the minimum,
	 * clamps between floor and ceiling, then returns 50% of capacity
	 * for the conservative first batch.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.1
	 *
	 * @return int Recommended initial batch size (conservative — 50% of capacity).
	 */
	public function calculate_initial(): int {
		$memory_limit = $this->parse_memory_limit( ini_get( 'memory_limit' ) );
		$max_time     = (int) ini_get( 'max_execution_time' );

		// A value of 0 means no limit — cap at 300s to avoid absurd batch sizes.
		if ( 0 === $max_time ) {
			$max_time = 300;
		}

		// Available memory after WP overhead and safety margin.
		$available_memory = $memory_limit * self::MEMORY_SAFETY_MARGIN * ( 1 - self::WP_OVERHEAD_PERCENT );

		// Memory-safe batch count.
		$memory_safe = (int) floor( $available_memory / self::MEMORY_PER_PRODUCT );

		// Time-safe batch count.
		$time_safe = (int) floor( ( $max_time * self::TIME_SAFETY_MARGIN ) / self::TIME_PER_PRODUCT );

		// Take the more restrictive limit.
		$initial = min( $memory_safe, $time_safe );

		// Clamp between floor and ceiling.
		$initial = $this->clamp( $initial, self::BATCH_FLOOR, self::BATCH_CEILING );

		// First batch is conservative: 50% of calculated capacity.
		return max( self::BATCH_FLOOR, (int) floor( $initial * 0.5 ) );
	}

	/**
	 * Record actual batch performance for adaptive calculation.
	 *
	 * Stores the measurement in a rolling window of 3 entries.
	 * Computes derived per-product values for use in calculate_next().
	 * Persists to transient if feed_name is set.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.2
	 *
	 * @param int   $count  Number of products processed in this batch.
	 * @param float $time   Total time in seconds for this batch.
	 * @param int   $memory Memory delta in bytes for this batch.
	 *
	 * @return void
	 */
	public function record_batch( int $count, float $time, int $memory ): void {
		if ( $count <= 0 ) {
			return;
		}

		$this->history[] = array(
			'count'      => $count,
			'time'       => $time,
			'memory'     => $memory,
			'time_per'   => $time / $count,
			'memory_per' => (int) ceil( $memory / $count ),
		);

		// Rolling window: keep only the last 3 measurements.
		if ( count( $this->history ) > 3 ) {
			$this->history = array_slice( $this->history, -3 );
		}

		$this->save_history();
	}

	/**
	 * Calculate adaptive next batch size using actual measurements.
	 *
	 * Averages the rolling window, computes new memory-safe and time-safe
	 * limits from real data, applies 50% dampening to prevent oscillation,
	 * enforces the MAX_BATCH_CHANGE limit, and clamps between floor and ceiling.
	 *
	 * Falls back to calculate_initial() if no history exists.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.3
	 *
	 * @return int Recommended batch size for the next batch.
	 */
	public function calculate_next(): int {
		if ( empty( $this->history ) ) {
			return $this->calculate_initial();
		}

		// Average time and memory per product across rolling window.
		$total_time_per   = 0.0;
		$total_memory_per = 0;
		$window_size      = count( $this->history );

		foreach ( $this->history as $entry ) {
			$total_time_per   += $entry['time_per'];
			$total_memory_per += $entry['memory_per'];
		}

		$avg_time_per   = $total_time_per / $window_size;
		$avg_memory_per = $total_memory_per / $window_size;

		// Prevent division by zero.
		if ( $avg_time_per <= 0 ) {
			$avg_time_per = self::TIME_PER_PRODUCT;
		}
		if ( $avg_memory_per <= 0 ) {
			$avg_memory_per = self::MEMORY_PER_PRODUCT;
		}

		// Recompute safe limits from actual measurements.
		$memory_limit = $this->parse_memory_limit( ini_get( 'memory_limit' ) );
		$max_time     = (int) ini_get( 'max_execution_time' );
		if ( 0 === $max_time ) {
			$max_time = 300;
		}

		$available_memory = $memory_limit * self::MEMORY_SAFETY_MARGIN * ( 1 - self::WP_OVERHEAD_PERCENT );
		$memory_safe      = (int) floor( $available_memory / $avg_memory_per );
		$time_safe        = (int) floor( ( $max_time * self::TIME_SAFETY_MARGIN ) / $avg_time_per );

		$raw = min( $memory_safe, $time_safe );

		// Get the last batch size as reference point.
		$last_entry = end( $this->history );
		$current    = $last_entry['count'];

		// Apply 50% dampening: only move halfway toward the raw target.
		$dampened = $current + ( $raw - $current ) * 0.5;

		// Enforce MAX_BATCH_CHANGE: delta cannot exceed 50% of current.
		$max_delta = $current * self::MAX_BATCH_CHANGE;
		$delta     = $dampened - $current;

		if ( abs( $delta ) > $max_delta ) {
			$dampened = $current + ( $delta > 0 ? $max_delta : -$max_delta );
		}

		// Clamp between floor and ceiling.
		return $this->clamp( (int) round( $dampened ), self::BATCH_FLOOR, self::BATCH_CEILING );
	}

	/**
	 * Get the server environment profile.
	 *
	 * Classifies the server as 'shared', 'vps', or 'dedicated' based
	 * on memory_limit and max_execution_time. Used for logging and
	 * dashboard display.
	 *
	 * @since 8.0.0
	 * @implements FEED-FRD-11.4
	 *
	 * @return string Environment profile: 'shared', 'vps', or 'dedicated'.
	 */
	public function get_environment_profile(): string {
		$memory_limit = $this->parse_memory_limit( ini_get( 'memory_limit' ) );
		$max_time     = (int) ini_get( 'max_execution_time' );

		// memory_limit >= 1 GB and timeout >= 300s (or unlimited) = dedicated.
		if ( $memory_limit >= 1073741824 && ( 0 === $max_time || $max_time >= 300 ) ) {
			return 'dedicated';
		}

		// memory_limit >= 256 MB and timeout >= 60s = VPS.
		if ( $memory_limit >= 268435456 && ( 0 === $max_time || $max_time >= 60 ) ) {
			return 'vps';
		}

		return 'shared';
	}

	/**
	 * Acquire a global generation lock.
	 *
	 * Only one feed can hold the lock at a time. This prevents multiple
	 * feeds from running batches simultaneously and competing for the
	 * same server memory. Uses a WordPress transient with LOCK_TTL.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed requesting the lock.
	 *
	 * @return bool True if lock acquired, false if another feed holds it.
	 */
	public function acquire_lock( string $feed_name ): bool {
		$lock = get_transient( 'ctxfeed_generation_lock' );

		// No lock exists — acquire it.
		if ( false === $lock ) {
			set_transient(
				'ctxfeed_generation_lock',
				array(
					'feed_name' => $feed_name,
					'acquired'  => time(),
				),
				self::LOCK_TTL 
			);
			return true;
		}

		// Lock belongs to this feed — re-acquire (refresh TTL).
		if ( is_array( $lock ) && $feed_name === $lock['feed_name'] ) {
			set_transient(
				'ctxfeed_generation_lock',
				array(
					'feed_name' => $feed_name,
					'acquired'  => time(),
				),
				self::LOCK_TTL 
			);
			return true;
		}

		// Lock belongs to another feed — check if stale.
		if ( is_array( $lock ) && isset( $lock['acquired'] ) ) {
			$age = time() - (int) $lock['acquired'];
			if ( $age > self::LOCK_TTL ) {
				// Stale lock — override it.
				set_transient(
					'ctxfeed_generation_lock',
					array(
						'feed_name' => $feed_name,
						'acquired'  => time(),
					),
					self::LOCK_TTL 
				);
				return true;
			}
		}

		return false;
	}

	/**
	 * Release the global generation lock.
	 *
	 * Only the feed that holds the lock can release it.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed releasing the lock.
	 *
	 * @return void
	 */
	public function release_lock( string $feed_name ): void {
		$lock = get_transient( 'ctxfeed_generation_lock' );

		if ( is_array( $lock ) && $feed_name === $lock['feed_name'] ) {
			delete_transient( 'ctxfeed_generation_lock' );
		}
	}

	/**
	 * Check which feed currently holds the generation lock.
	 *
	 * @since 8.0.0
	 *
	 * @return string|false Feed name holding the lock, or false if no lock.
	 */
	public function get_lock_holder() {
		$lock = get_transient( 'ctxfeed_generation_lock' );

		if ( is_array( $lock ) && isset( $lock['feed_name'] ) ) {
			return $lock['feed_name'];
		}

		return false;
	}

	/**
	 * Clear persisted history for a feed.
	 *
	 * Called after feed finalization so the next generation starts fresh.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_name Feed slug identifier. Empty = use current.
	 *
	 * @return void
	 */
	public function clear_history( string $feed_name = '' ): void {
		$name          = $feed_name ? $feed_name : $this->feed_name;
		$this->history = array();

		if ( $name ) {
			delete_transient( "ctxfeed_batch_history_{$name}" );
		}
	}

	/**
	 * Load persisted history from transient.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function load_history(): void {
		if ( ! $this->feed_name ) {
			return;
		}

		$saved = get_transient( "ctxfeed_batch_history_{$this->feed_name}" );

		if ( is_array( $saved ) ) {
			$this->history = $saved;
		}
	}

	/**
	 * Save current history to transient.
	 *
	 * TTL is 1 hour — long enough for any generation cycle but auto-cleans
	 * if the feed generation was interrupted.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function save_history(): void {
		if ( ! $this->feed_name ) {
			return;
		}

		set_transient( "ctxfeed_batch_history_{$this->feed_name}", $this->history, HOUR_IN_SECONDS );
	}

	/**
	 * Parse PHP memory_limit string to bytes.
	 *
	 * Handles shorthand notation: '128M' → 134217728, '1G' → 1073741824.
	 * Returns -1 for unlimited memory.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value PHP ini memory_limit value.
	 *
	 * @return int Memory limit in bytes.
	 */
	private function parse_memory_limit( string $value ): int {
		$value = trim( $value );

		if ( '-1' === $value ) {
			// Unlimited — use 2 GB as practical ceiling.
			return 2147483648;
		}

		$last = strtolower( substr( $value, -1 ) );
		$num  = (int) $value;

		switch ( $last ) {
			case 'g':
				$num *= 1073741824; // 1024^3.
				break;
			case 'm':
				$num *= 1048576; // 1024^2.
				break;
			case 'k':
				$num *= 1024;
				break;
		}

		return $num;
	}

	/**
	 * Clamp a value between minimum and maximum.
	 *
	 * @since 8.0.0
	 *
	 * @param int $value Value to clamp.
	 * @param int $min   Minimum bound.
	 * @param int $max   Maximum bound.
	 *
	 * @return int Clamped value.
	 */
	private function clamp( int $value, int $min, int $max ): int {
		return max( $min, min( $max, $value ) );
	}
}
