<?php
/**
 * Logger — Static logging facade wrapping WooCommerce's logger.
 *
 * Provides standard log levels (info, warning, error, critical, debug)
 * plus a dedicated performance logger with a separate WC log source.
 *
 * @package    CTXFeed
 * @subpackage V8/Core
 * @since      8.0.0
 * @implements CORE-FRD-6.1, CORE-FRD-6.2, CORE-FRD-6.3, CORE-FRD-6.4
 */

namespace CTXFeed\V8\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static logging facade.
 *
 * @since 8.0.0
 */
class Logger {

	/**
	 * Primary log source identifier.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const SOURCE = 'ctxfeed-v8';

	/**
	 * Performance log source identifier.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const PERF_SOURCE = 'ctxfeed-v8-perf';

	/**
	 * Cached debug-enabled flag.
	 *
	 * @since 8.0.0
	 * @var bool|null
	 */
	private static ?bool $debug_enabled = null;

	/**
	 * Log an informational message.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-6.1
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 *
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {

		self::log( 'info', $message, $context, self::SOURCE );
	}

	/**
	 * Log a warning message.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-6.1
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 *
	 * @return void
	 */
	public static function warning( string $message, array $context = array() ): void {

		self::log( 'warning', $message, $context, self::SOURCE );
	}

	/**
	 * Log an error message.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-6.1
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 *
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {

		self::log( 'error', $message, $context, self::SOURCE );
	}

	/**
	 * Log a critical failure message.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-6.1
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 *
	 * @return void
	 */
	public static function critical( string $message, array $context = array() ): void {

		self::log( 'critical', $message, $context, self::SOURCE );
	}

	/**
	 * Log a debug message (only when debugging is enabled).
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-6.1, CORE-FRD-6.2
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 *
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {

		if ( self::is_debug_enabled() ) {
			self::log( 'debug', $message, $context, self::SOURCE );
		}
	}

	/**
	 * Log a performance metric.
	 *
	 * Writes to a separate WC log source for performance data.
	 * Format: "[PERF] {$label}" with duration_ms added to metrics.
	 *
	 * Expected metrics keys: duration_ms, queries, memory_mb, peak_mb, products_count.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-6.3
	 *
	 * @param string $label       Performance label (e.g., 'batch_generate').
	 * @param float  $duration_ms Duration in milliseconds.
	 * @param array  $metrics     Additional metrics (queries, memory_mb, etc.).
	 *
	 * @return void
	 */
	public static function performance( string $label, float $duration_ms, array $metrics = array() ): void {

		$metrics['duration_ms'] = round( $duration_ms, 2 );

		self::log( 'info', "[PERF] {$label}", $metrics, self::PERF_SOURCE );
	}

	/**
	 * Write a log entry to WooCommerce logger.
	 *
	 * Silently skips if WooCommerce logger is not available.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-6.4
	 *
	 * @param string $level   Log level (info, warning, error, critical, debug).
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 * @param string $source  WC logger source identifier.
	 *
	 * @return void
	 */
	private static function log( string $level, string $message, array $context, string $source ): void {

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();

		$logger->log(
			$level,
			$message,
			array_merge( $context, array( 'source' => $source ) )
		);
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * Caches the result in a static property to avoid repeated get_option calls.
	 *
	 * @since 8.0.0
	 * @implements CORE-FRD-6.2
	 *
	 * @return bool True if debugging is enabled.
	 */
	private static function is_debug_enabled(): bool {

		if ( null === self::$debug_enabled ) {
			$settings            = get_option( 'woo_feed_settings', array() );
			self::$debug_enabled = isset( $settings['enable_error_debugging'] )
				&& 'on' === $settings['enable_error_debugging'];
		}

		return self::$debug_enabled;
	}
}
