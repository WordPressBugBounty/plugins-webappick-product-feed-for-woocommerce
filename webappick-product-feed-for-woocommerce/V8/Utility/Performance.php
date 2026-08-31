<?php
/**
 * Performance — Tracks feed generation performance metrics.
 *
 * Captures execution time, DB query count, memory usage, and peak memory
 * per labeled operation. Supports multiple concurrent trackers. Delegates
 * logging to Core\Logger::performance().
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.0
 * @implements UTIL-FRD-6.1, UTIL-FRD-6.2, UTIL-FRD-6.3
 */

namespace CTXFeed\V8\Utility;

use CTXFeed\V8\Core\Logger;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performance metrics tracking service.
 *
 * @since 8.0.0
 */
class Performance {

	/**
	 * Active trackers keyed by label.
	 *
	 * Each entry: array( 'time' => float, 'queries' => int, 'memory' => int ).
	 *
	 * @since 8.0.0
	 * @var array
	 */
	private static $trackers = array();

	/**
	 * Start tracking a labeled operation.
	 *
	 * Captures baseline metrics: timestamp, query count, and memory usage.
	 * Multiple concurrent labels are supported.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-6.1
	 *
	 * @param string $label Unique identifier for this tracking session.
	 *
	 * @return void
	 */
	public function start( string $label ): void {

		self::$trackers[ $label ] = array(
			'time'    => microtime( true ),
			'queries' => get_num_queries(),
			'memory'  => memory_get_usage( true ),
		);
	}

	/**
	 * Stop tracking and log the results.
	 *
	 * Calculates deltas for duration, queries, and memory. Logs the metrics
	 * via Logger::performance(). Silently returns if the label was never started.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-6.2, UTIL-FRD-6.3
	 *
	 * @param string $label The label passed to start().
	 *
	 * @return void
	 */
	public function stop( string $label ): void {

		// Zero overhead for unknown labels (UTIL-FRD-6.3).
		if ( ! isset( self::$trackers[ $label ] ) ) {
			return;
		}

		$start = self::$trackers[ $label ];

		$metrics = array(
			'duration_ms' => round( ( microtime( true ) - $start['time'] ) * 1000, 2 ),
			'queries'     => get_num_queries() - $start['queries'],
			'memory_mb'   => round( ( memory_get_usage( true ) - $start['memory'] ) / 1048576, 2 ),
			'peak_mb'     => round( memory_get_peak_usage( true ) / 1048576, 2 ),
		);

		// Clean up tracker.
		unset( self::$trackers[ $label ] );

		// Delegate logging to Core\Logger.
		Logger::performance( $label, $metrics['duration_ms'], $metrics );
	}
}
