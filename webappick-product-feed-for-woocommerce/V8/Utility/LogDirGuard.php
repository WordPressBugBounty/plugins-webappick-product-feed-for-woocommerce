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
