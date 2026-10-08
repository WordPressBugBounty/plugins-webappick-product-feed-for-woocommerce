<?php
/**
 * LogDirGuard — keep uploads/woo-feed/logs from being served (CBT-708).
 *
 * The folder sits inside uploads, so a web server may serve it. Until
 * 8.0.32 the only guard was an Apache-2.2 `deny from all` written at
 * activation — ignored by Apache 2.4 without mod_access_compat, never
 * written when a logger created the folder later. This writes rules for
 * both Apache generations plus index files whenever the folder is used,
 * and once scrubs credentials older versions wrote into log lines (an SFTP
 * login failure logged the password).
 *
 * Nginx ignores .htaccess: there the logs stay as private as their file
 * names; credentials are no longer written to them at all.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.32
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LogDirGuard
 *
 * @since 8.0.32
 */
class LogDirGuard {

	/**
	 * Deny rules for Apache 2.4 (mod_authz_core) and 2.2 (mod_access).
	 *
	 * @var string
	 */
	const HTACCESS = "# CTX Feed — logs are private.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	/**
	 * Option flag: legacy log lines scrubbed once.
	 *
	 * @var string
	 */
	const SCRUBBED_OPTION = 'ctxfeed_logs_scrubbed_cbt708';

	/**
	 * Option holding this site's random log-folder key (CBT-725).
	 *
	 * @var string
	 */
	const DIR_OPTION = 'ctxfeed_log_dir_key';

	/**
	 * Per-request memo of dir().
	 *
	 * @var string|null
	 */
	private static $dir = null;

	/**
	 * This site's log folder: `uploads/woo-feed/logs-{random}/`.
	 *
	 * Nginx ignores the .htaccess deny rules, so a guessable
	 * `uploads/woo-feed/logs/{feed}.log` was readable by anyone there; an
	 * unguessable per-site folder name closes that (CBT-725). The key is
	 * created once (moving the old `logs/` contents in) and never changes.
	 * Override with the `ctxfeed_log_dir` filter — from a must-use plugin,
	 * since the path is resolved while plugins load.
	 *
	 * @since 8.0.33
	 * @return string Absolute path with trailing slash.
	 */
	public static function dir(): string {
		if ( null !== self::$dir ) {
			return self::$dir;
		}

		$base = self::base_dir();
		$new  = false;
		// Logging must never break a request: option I/O failures fall back
		// to a fresh key for this request.
		try {
			$key = function_exists( 'get_option' ) ? get_option( self::DIR_OPTION ) : '';
		} catch ( \Throwable $e ) {
			$key = '';
		}
		if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9]{16,40}$/', $key ) ) {
			$key = self::random_key();
			$new = true;
			try {
				if ( function_exists( 'update_option' ) ) {
					update_option( self::DIR_OPTION, $key, true );
				}
			} catch ( \Throwable $e ) {
				$new = false; // Not persisted: do not move the old logs around.
			}
		}

		$dir = $base . 'logs-' . $key . '/';
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters CTX Feed's log folder (absolute path).
			 *
			 * @since 8.0.33
			 *
			 * @param string $dir Log folder.
			 */
			$dir = (string) apply_filters( 'ctxfeed_log_dir', $dir );
		}
		self::$dir = rtrim( $dir, '/\\' ) . '/';

		if ( $new ) {
			self::migrate_legacy();
		}

		return self::$dir;
	}

	/**
	 * Move the logs out of the old guessable `woo-feed/logs/` folder into
	 * this site's random folder, then remove the old folder (CBT-725).
	 * Idempotent — a no-op once the old folder is gone.
	 *
	 * @since 8.0.33
	 * @return int Files moved.
	 */
	public static function migrate_legacy(): int {
		$legacy = self::base_dir() . 'logs/';
		$target = self::dir();
		if ( ! is_dir( $legacy ) || rtrim( $legacy, '/' ) === rtrim( $target, '/' ) ) {
			return 0;
		}

		if ( ! is_dir( $target ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $target );
		}
		self::ensure( $target );

		$moved = 0;
		foreach ( (array) glob( $legacy . '*' ) as $file ) {
			$name = basename( (string) $file );
			if ( ! is_file( $file ) || in_array( $name, array( '.htaccess', 'index.php', 'index.html' ), true ) ) {
				continue;
			}
			if ( ! file_exists( $target . $name ) && @rename( $file, $target . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden, WordPress.WP.AlternativeFunctions.rename_rename, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Moving our own log files inside uploads; a failure leaves the file where it was.
				++$moved;
			}
		}

		// Remove the old folder only when nothing but its guard files is left.
		$left = array_filter(
			(array) glob( $legacy . '{,.}*', GLOB_BRACE ),
			static function ( $f ) {
				return ! in_array( basename( (string) $f ), array( '.', '..', '.htaccess', 'index.php', 'index.html' ), true );
			}
		);
		if ( empty( $left ) ) {
			foreach ( array( '.htaccess', 'index.php', 'index.html' ) as $guard ) {
				if ( is_file( $legacy . $guard ) ) {
					@unlink( $legacy . $guard ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- Our own guard file in uploads.
				}
			}
			@rmdir( $legacy ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Our own empty folder in uploads.
		}

		return $moved;
	}

	/**
	 * Forget the memoized folder (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$dir = null;
	}

	/**
	 * `uploads/woo-feed/` with trailing slash.
	 *
	 * @return string
	 */
	private static function base_dir(): string {
		$basedir = '';
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$upload  = wp_get_upload_dir();
			$basedir = (string) ( $upload['basedir'] ?? '' );
		}
		if ( '' === $basedir ) {
			$basedir = ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/uploads';
		}

		return rtrim( $basedir, '/\\' ) . '/woo-feed/';
	}

	/**
	 * 20 lowercase hex-ish characters, cryptographically random when
	 * possible (wp_generate_password() is not loaded yet while plugins load).
	 *
	 * @return string
	 */
	private static function random_key(): string {
		try {
			return bin2hex( random_bytes( 10 ) );
		} catch ( \Throwable $e ) {
			return substr( md5( uniqid( '', true ) . mt_rand() ), 0, 20 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Fallback only; wp_rand() is pluggable and not loaded yet.
		}
	}

	/**
	 * Write the guard files into a log directory (idempotent, cheap).
	 *
	 * Replaces the legacy Apache-2.2-only .htaccess; never touches a
	 * customised one.
	 *
	 * @since 8.0.32
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	public static function ensure( string $dir ): void {
		$dir = rtrim( $dir, '/\\' );
		if ( '' === $dir || ! is_dir( $dir ) || ! is_writable( $dir ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only permission probe of our own uploads folder before writing guard files.
			return;
		}

		$htaccess = $dir . '/.htaccess';
		$current  = file_exists( $htaccess ) ? trim( (string) file_get_contents( $htaccess ) ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Reads our own local guard file.
		if ( null === $current || '' === $current || 'deny from all' === strtolower( $current ) ) {
			self::write( $htaccess, self::HTACCESS );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			self::write( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			self::write( $dir . '/index.html', '' );
		}
	}

	/**
	 * Once per site: remove credentials older versions wrote into existing
	 * log files (the SFTP "… and password X ." line, and any saved FTP
	 * password).
	 *
	 * @since 8.0.32
	 *
	 * @param string $dir Log directory.
	 * @return void
	 */
	public static function scrub_legacy_once( string $dir ): void {
		if ( ! function_exists( 'get_option' ) || get_option( self::SCRUBBED_OPTION ) ) {
			return;
		}

		$dir   = rtrim( $dir, '/\\' );
		$files = is_dir( $dir ) ? (array) glob( $dir . '/*.log' ) : array();
		if ( ! empty( $files ) ) {
			$secrets = Redactor::saved_ftp_passwords();
			foreach ( $files as $file ) {
				if ( ! is_string( $file ) || ! is_file( $file ) || ! is_writable( $file ) || filesize( $file ) > 20 * MB_IN_BYTES ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Permission probe of our own log file before the one-time scrub.
					continue;
				}
				$text  = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Our own local log file.
				$clean = Redactor::text( $text, $secrets );
				if ( $clean !== $text ) {
					self::write( $file, $clean );
				}
			}
		}

		update_option( self::SCRUBBED_OPTION, 1, false );
	}

	/**
	 * Write a small file, silently (uploads may be read-only).
	 *
	 * @param string $file    Path.
	 * @param string $content Content.
	 * @return void
	 */
	private static function write( string $file, string $content ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Forbidden -- Guard files in our own uploads folder; WP_Filesystem is not available on cron/activation; failure is non-fatal.
		@file_put_contents( $file, $content, LOCK_EX );
	}
}
