<?php
/**
 * FeedLogger — Per-feed buffered log writer for V8 feed generation.
 *
 * Writes per-feed log files to /wp-content/uploads/woo-feed/logs/ (same
 * directory as V5), so the Download Log action works for V8-generated feeds.
 *
 * Performance strategy:
 * - Entries are buffered in memory during processing.
 * - flush() writes all buffered entries to disk in a single fwrite() call.
 * - FeedGenerator calls flush() once at the end of each batch, reducing
 *   disk I/O from potentially hundreds of writes to just one per batch.
 * - Auto-flush in __destruct() as a safety net for uncaught exits.
 *
 * Log file naming: {feed_slug}.log (simple, no date/hash — one file per feed,
 * overwritten each generation cycle via init(), appended during batches).
 *
 * Log rotation: 5 MB limit with .0 → .9 rotation (same as V5).
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.0
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-feed buffered log writer.
 *
 * @since 8.0.0
 */
class FeedLogger {

	/**
	 * Whether logging is enabled (from Settings → enable_error_debugging).
	 *
	 * @since 8.0.0
	 * @var bool
	 */
	private $enabled;

	/**
	 * Log directory path.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private $log_dir;

	/**
	 * Buffered log entries keyed by feed slug.
	 *
	 * @since 8.0.0
	 * @var array<string, string[]>
	 */
	private $buffer = array();

	/**
	 * Constructor.
	 *
	 * Per-feed logging is always on: the log is buffered in memory and written
	 * with a single fwrite per batch, so the cost is negligible (~1 ms/batch)
	 * and the log is the primary support artifact when a generation fails.
	 * The `ctxfeed_feed_log_enabled` filter is a kill-switch for sites that
	 * need to disable it (disk quota, privacy).
	 *
	 * @since 8.0.0
	 * @since 8.0.11 Always enabled; `enable_error_debugging` now only gates the system log.
	 */
	public function __construct() {
		/**
		 * Filter whether per-feed generation logs are written.
		 *
		 * @since 8.0.11
		 *
		 * @param bool $enabled Default true.
		 */
		$this->enabled = (bool) apply_filters( 'ctxfeed_feed_log_enabled', true );

		$upload_dir    = wp_get_upload_dir();
		$this->log_dir = trailingslashit( $upload_dir['basedir'] ) . 'woo-feed/logs/';
	}

	/**
	 * Initialize a fresh log for a feed generation cycle.
	 *
	 * Creates (or truncates) the log file and writes a header.
	 * Call this once at the start of schedule_generation().
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug identifier.
	 * @param array  $meta      Generation metadata (total, batch_size, etc.).
	 *
	 * @return void
	 */
	public function init( string $feed_slug, array $meta = array() ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$this->ensure_dir();

		$file = $this->get_log_path( $feed_slug );

		// One log file per feed: each run starts the file over below, and
		// rotated/dated copies left by older builds are removed.
		$this->delete_legacy_copies( $feed_slug );

		// Truncate and write header.
		$header  = '===== Feed Generation Log =====' . PHP_EOL;
		$header .= 'Feed: ' . $feed_slug . PHP_EOL;
		$header .= 'Started: ' . current_time( 'Y-m-d H:i:s' ) . PHP_EOL;

		if ( ! empty( $meta ) ) {
			foreach ( $meta as $key => $value ) {
				$header .= ucfirst( str_replace( '_', ' ', $key ) ) . ': ' . $value . PHP_EOL;
			}
		}

		$header .= str_repeat( '-', 50 ) . PHP_EOL;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Writes the feed run log into the plugin's own uploads/woo-feed/logs directory. WP_Filesystem is not initialised on the Action Scheduler cron request that generates feeds, and this must not fail the run.
		file_put_contents( $file, $header );
	}

	/**
	 * Add a log entry to the buffer.
	 *
	 * Does NOT write to disk — call flush() to persist.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug identifier.
	 * @param string $level     Log level (info, warning, error, debug).
	 * @param string $message   Log message.
	 *
	 * @return void
	 */
	public function log( string $feed_slug, string $level, string $message ): void {
		if ( ! $this->enabled ) {
			return;
		}

		$timestamp = current_time( 'Y-m-d H:i:s' );
		$level_tag = strtoupper( $level );

		$this->buffer[ $feed_slug ][] = "[{$timestamp}] [{$level_tag}] {$message}";
	}

	/**
	 * Shorthand: log an info message.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug.
	 * @param string $message   Message.
	 *
	 * @return void
	 */
	public function info( string $feed_slug, string $message ): void {
		$this->log( $feed_slug, 'info', $message );
	}

	/**
	 * Shorthand: log a warning message.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug.
	 * @param string $message   Message.
	 *
	 * @return void
	 */
	public function warning( string $feed_slug, string $message ): void {
		$this->log( $feed_slug, 'warning', $message );
	}

	/**
	 * Shorthand: log an error message.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug.
	 * @param string $message   Message.
	 *
	 * @return void
	 */
	public function error( string $feed_slug, string $message ): void {
		$this->log( $feed_slug, 'error', $message );
	}

	/**
	 * Flush buffered entries for a feed to disk.
	 *
	 * Writes all buffered entries in a single I/O operation, then clears
	 * the buffer. Call once at the end of each batch for minimal overhead.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug identifier.
	 *
	 * @return void
	 */
	public function flush( string $feed_slug ): void {
		if ( ! $this->enabled || empty( $this->buffer[ $feed_slug ] ) ) {
			return;
		}

		$this->ensure_dir();

		$file    = $this->get_log_path( $feed_slug );
		$content = implode( PHP_EOL, $this->buffer[ $feed_slug ] ) . PHP_EOL;

		// Single append write — minimal I/O.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Single locked append per batch into the plugin's own log directory; WP_Filesystem has no append-with-lock equivalent and is unavailable on the cron request.
		file_put_contents( $file, $content, FILE_APPEND | LOCK_EX );

		unset( $this->buffer[ $feed_slug ] );
	}

	/**
	 * Flush all buffered feeds.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function flush_all(): void {
		foreach ( array_keys( $this->buffer ) as $feed_slug ) {
			$this->flush( $feed_slug );
		}
	}

	/**
	 * Delete every persisted feed log file.
	 *
	 * Native (V5-free) replacement for `V5\Utility\Logs::delete_all_logs()`:
	 * removes all `*.log` files under the feed log directory.
	 *
	 * @since 8.0.0
	 *
	 * @return int Number of log files deleted.
	 */
	public function delete_all(): int {
		if ( ! is_dir( $this->log_dir ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( (array) glob( $this->log_dir . '*.log' ) as $file ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Bulk "clear logs" over the plugin's own log directory: a file the glob listed may already be gone or be root-owned, and the boolean return is what drives the counter, so the warning is noise.
			if ( is_file( $file ) && @unlink( $file ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Write a completion footer to the log.
	 *
	 * Call once after finalization.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug identifier.
	 * @param array  $summary   Completion summary (products, duration, file_size, etc.).
	 *
	 * @return void
	 */
	public function complete( string $feed_slug, array $summary = array() ): void {
		if ( ! $this->enabled ) {
			return;
		}

		// Closing line — identical to the live console's last line.
		if ( isset( $summary['total_products'] ) ) {
			$this->info( $feed_slug, sprintf( 'Completed — %s exported', self::products( (int) $summary['total_products'] ) ) );
			unset( $summary['total_products'] );
		} else {
			$this->info( $feed_slug, 'Completed' );
		}

		foreach ( $summary as $key => $value ) {
			$this->info( $feed_slug, ucfirst( str_replace( '_', ' ', $key ) ) . ': ' . $value );
		}

		$this->buffer[ $feed_slug ][] = str_repeat( '-', 50 );
		$this->buffer[ $feed_slug ][] = 'Finished: ' . current_time( 'Y-m-d H:i:s' );
		$this->buffer[ $feed_slug ][] = str_repeat( '=', 50 );

		$this->flush( $feed_slug );
	}

	/**
	 * "1 product" / "1,234 products" — the feed log is plain English, so this
	 * stays out of the translation catalogue on purpose.
	 *
	 * @since 8.0.10
	 *
	 * @param int $count Product count.
	 * @return string
	 */
	public static function products( int $count ): string {
		return number_format_i18n( $count ) . ( 1 === $count ? ' product' : ' products' );
	}

	/**
	 * "1 batch" / "12 batches".
	 *
	 * @since 8.0.10
	 *
	 * @param int $count Batch count.
	 * @return string
	 */
	public static function batches( int $count ): string {
		return number_format_i18n( $count ) . ( 1 === $count ? ' batch' : ' batches' );
	}

	/**
	 * Get the log file path for a feed.
	 *
	 * @since 8.0.0
	 *
	 * @param string $feed_slug Feed slug.
	 *
	 * @return string Full file path.
	 */
	public function get_log_path( string $feed_slug ): string {
		return $this->log_dir . sanitize_file_name( $feed_slug ) . '.log';
	}

	/**
	 * Delete a feed's log file (and any legacy copies) — used when the feed
	 * itself is deleted.
	 *
	 * @since 8.0.10
	 *
	 * @param string $feed_slug Feed slug.
	 * @return int Files deleted.
	 */
	public function delete( string $feed_slug ): int {
		$deleted = 0;
		$file    = $this->get_log_path( $feed_slug );
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
			++$deleted;
		}
		unset( $this->buffer[ $feed_slug ] );

		return $deleted + $this->delete_legacy_copies( $feed_slug );
	}

	/**
	 * Remove rotated (`{slug}.N.log`) and dated (`{slug}-*.log`) copies left
	 * by earlier builds. One file per feed is the contract now.
	 *
	 * @since 8.0.10
	 *
	 * @param string $feed_slug Feed slug.
	 * @return int Files deleted.
	 */
	private function delete_legacy_copies( string $feed_slug ): int {
		$base    = $this->log_dir . sanitize_file_name( $feed_slug );
		$deleted = 0;
		foreach ( array_merge( (array) glob( $base . '.[0-9]*.log' ), (array) glob( $base . '-*.log' ) ) as $copy ) {
			if ( is_file( $copy ) ) {
				wp_delete_file( $copy );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Ensure the log directory exists.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	private function ensure_dir(): void {
		if ( ! is_dir( $this->log_dir ) ) {
			wp_mkdir_p( $this->log_dir );
		}
	}

	/**
	 * Destructor — safety-net flush for any un-flushed buffers.
	 *
	 * @since 8.0.0
	 */
	public function __destruct() {
		$this->flush_all();
	}
}
