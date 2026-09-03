<?php
/**
 * Logger — the plugin's two log streams.
 *
 * 1. WooCommerce → Status → Logs, source `ctxfeed`: PHP ERRORS ONLY. Caught
 *    exceptions the plugin reports through error()/critical(), and PHP fatal
 *    errors raised inside the plugin's own files (captured by the shutdown
 *    handler). Nothing else goes there, so a merchant opening the WooCommerce
 *    log viewer sees problems, not chatter.
 *
 * 2. The plugin's own system trace, `uploads/woo-feed/logs/ctxfeed-system.log`:
 *    info()/warning()/debug() lines about scheduling, batches and internals.
 *    Written ONLY while "Enable error debugging" is on in CTX Feed settings,
 *    and never shown in the WooCommerce log viewer.
 *
 * Per-feed generation logs (`uploads/woo-feed/logs/{feed}.log`) are a third,
 * separate stream owned by Utility\FeedLogger — one file per feed,
 * downloadable from the feed list, deleted with the feed.
 *
 * @package    CTXFeed
 * @subpackage V8/Core
 * @since      8.0.0
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
	 * WooCommerce log source. Errors only.
	 *
	 * @since 8.0.10 renamed from `ctxfeed-v8`.
	 */
	const SOURCE = 'ctxfeed';

	/**
	 * System trace file name (inside uploads/woo-feed/logs/).
	 */
	const SYSTEM_LOG_FILE = 'ctxfeed-system.log';

	/**
	 * System trace size cap — the file is restarted beyond this.
	 */
	const SYSTEM_LOG_LIMIT = 5242880; // 5 MB.

	/**
	 * Memoised "Enable error debugging" setting.
	 *
	 * @var bool|null
	 */
	private static ?bool $debug_enabled = null;

	/**
	 * Guard so the shutdown handler registers once.
	 *
	 * @var bool
	 */
	private static bool $fatal_handler_registered = false;

	/**
	 * Informational trace (system log, debug mode only).
	 *
	 * @since 8.0.0
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {
		self::trace( 'info', $message, $context );
	}

	/**
	 * Warning trace (system log, debug mode only).
	 *
	 * @since 8.0.0
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::trace( 'warning', $message, $context );
	}

	/**
	 * Verbose debug trace (system log, debug mode only).
	 *
	 * @since 8.0.0
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::trace( 'debug', $message, $context );
	}

	/**
	 * A caught error — WooCommerce → Status → Logs, source `ctxfeed`.
	 *
	 * @since 8.0.0
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::wc_log( 'error', $message, $context );
	}

	/**
	 * A critical error — WooCommerce → Status → Logs, source `ctxfeed`.
	 *
	 * @since 8.0.0
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function critical( string $message, array $context = array() ): void {
		self::wc_log( 'critical', $message, $context );
	}

	/**
	 * Capture PHP fatal errors raised inside the plugin into the `ctxfeed` log.
	 *
	 * Registers once. The handler only reports fatals whose file lives in a
	 * CTX Feed plugin folder, so unrelated fatals stay in WooCommerce's own
	 * `fatal-errors` log.
	 *
	 * @since 8.0.10
	 * @return void
	 */
	public static function register_fatal_handler(): void {
		if ( self::$fatal_handler_registered ) {
			return;
		}
		self::$fatal_handler_registered = true;
		register_shutdown_function( array( __CLASS__, 'handle_shutdown' ) );
	}

	/**
	 * Shutdown handler: log a plugin-originated fatal error.
	 *
	 * @since 8.0.10
	 *
	 * @param array|null $error Error to inspect; defaults to error_get_last().
	 * @return bool True when a fatal was logged.
	 */
	public static function handle_shutdown( ?array $error = null ): bool {
		$error = null === $error ? error_get_last() : $error;
		if ( ! is_array( $error ) || ! isset( $error['type'], $error['message'], $error['file'] ) ) {
			return false;
		}

		$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
		if ( ! ( (int) $error['type'] & $fatal_types ) ) {
			return false;
		}

		if ( ! self::is_plugin_file( (string) $error['file'] ) ) {
			return false;
		}

		self::wc_log(
			'critical',
			sprintf( 'PHP fatal error: %s in %s:%d', $error['message'], $error['file'], (int) ( $error['line'] ?? 0 ) ),
			array(
				'file' => $error['file'],
				'line' => (int) ( $error['line'] ?? 0 ),
				'type' => (int) $error['type'],
			)
		);

		return true;
	}

	/**
	 * Absolute path of the system trace file.
	 *
	 * @since 8.0.10
	 * @return string
	 */
	public static function system_log_path(): string {
		$upload_dir = wp_get_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . 'woo-feed/logs/' . self::SYSTEM_LOG_FILE;
	}

	/**
	 * Whether a file path belongs to a CTX Feed plugin folder.
	 *
	 * @since 8.0.10
	 *
	 * @param string $file Absolute file path.
	 * @return bool
	 */
	private static function is_plugin_file( string $file ): bool {
		/**
		 * Filter the folder names whose PHP fatals are captured into the `ctxfeed` log.
		 *
		 * @since 8.0.10
		 *
		 * @param string[] $needles Folder-name fragments matched against the fatal's file path.
		 */
		$needles = (array) apply_filters( 'ctxfeed_fatal_log_paths', array( 'webappick-product-feed-for-woocommerce' ) );
		$file    = str_replace( '\\', '/', $file );

		foreach ( $needles as $needle ) {
			if ( '' !== (string) $needle && false !== strpos( $file, (string) $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Write to WooCommerce's logger under the `ctxfeed` source.
	 *
	 * Bails silently when WooCommerce is inactive — a logging call must
	 * never fatal the plugin.
	 *
	 * @param string $level   PSR-3 level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	private static function wc_log( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log(
			$level,
			$message,
			array_merge( $context, array( 'source' => self::SOURCE ) )
		);
	}

	/**
	 * Append a line to the system trace file (debug mode only).
	 *
	 * @param string $level   Level tag.
	 * @param string $message Message.
	 * @param array  $context Context, JSON-encoded after the message.
	 * @return void
	 */
	private static function trace( string $level, string $message, array $context ): void {
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		$line = sprintf( '[%s] [%s] %s', gmdate( 'Y-m-d H:i:s' ), strtoupper( $level ), $message );
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		self::write_system_line( $line );
	}

	/**
	 * Append to the system log, restarting it past the size cap.
	 *
	 * @param string $line One log line (no trailing newline).
	 * @return void
	 */
	private static function write_system_line( string $line ): void {
		$file = self::system_log_path();
		$dir  = dirname( $file );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$flags = FILE_APPEND | LOCK_EX;
		if ( file_exists( $file ) && filesize( $file ) > self::SYSTEM_LOG_LIMIT ) {
			$flags = LOCK_EX; // Start over — one bounded file, no rotations.
			$line  = '[' . gmdate( 'Y-m-d H:i:s' ) . '] [INFO] System log restarted (size cap reached).' . PHP_EOL . $line;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Appends to the plugin's own trace file under uploads/woo-feed/logs; WP_Filesystem has no append and is not initialised on cron requests.
		file_put_contents( $file, $line . PHP_EOL, $flags );
	}

	/**
	 * "Enable error debugging" setting, read once per request.
	 *
	 * @return bool
	 */
	private static function is_debug_enabled(): bool {
		if ( null === self::$debug_enabled ) {
			$settings            = get_option( 'woo_feed_settings', array() );
			self::$debug_enabled = is_array( $settings )
				&& isset( $settings['enable_error_debugging'] )
				&& 'on' === $settings['enable_error_debugging'];
		}

		return self::$debug_enabled;
	}
}
