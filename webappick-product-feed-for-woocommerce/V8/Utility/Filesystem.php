<?php
/**
 * Filesystem — Centralized file operations for feed output.
 *
 * Handles feed directory management, path/URL resolution, atomic writes,
 * file deletion, size reporting, and temp file cleanup. All paths use
 * sanitize_file_name() to prevent directory traversal.
 *
 * @package    CTXFeed
 * @subpackage V8/Utility
 * @since      8.0.0
 * @implements UTIL-FRD-1.1, UTIL-FRD-1.2, UTIL-FRD-1.3, UTIL-FRD-1.4,
 *             UTIL-FRD-1.5, UTIL-FRD-1.6, UTIL-FRD-1.7
 */

namespace CTXFeed\V8\Utility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed filesystem operations.
 *
 * @since 8.0.0
 */
class Filesystem {

	/**
	 * Get the feed storage directory path.
	 *
	 * Auto-creates the directory if it does not exist. The resolved path
	 * is filterable via the `ctxfeed_feed_dir` hook.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-1.1
	 * @hook ctxfeed_feed_dir
	 *
	 * @return string Absolute path to the feed directory with trailing slash.
	 */
	public function get_feed_dir(): string {

		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'woo-feed/';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		/**
		 * Filters the feed output directory path.
		 *
		 * @since 8.0.0
		 *
		 * @param string $dir Absolute path to the feed directory.
		 */
		$dir = apply_filters( 'ctxfeed_feed_dir', $dir );

		return trailingslashit( $dir );
	}

	/**
	 * Whether the feed output directory exists (or could be created) and is
	 * writable by PHP.
	 *
	 * Checked at feed save-time so the admin is warned the moment the
	 * `uploads/woo-feed/` directory can't be written — a read-only uploads
	 * dir (restrictive host, wrong ownership after a server migration, a
	 * hardening plugin) silently drops every generated feed otherwise, which
	 * surfaces to users only as "my feed never updates".
	 *
	 * @since 8.1.0
	 *
	 * @return bool True when the directory is present and writable.
	 */
	public function is_feed_dir_writable(): bool {

		// get_feed_dir() attempts wp_mkdir_p() when the directory is missing.
		$dir = $this->get_feed_dir();

		if ( ! is_dir( $dir ) ) {
			// Could not be created (parent not writable / open_basedir).
			return false;
		}

		return wp_is_writable( $dir );
	}

	/**
	 * Get the absolute file path for a feed.
	 *
	 * The feed name is sanitized to prevent directory traversal.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-1.2
	 *
	 * @param string $feed_name Feed name identifier.
	 * @param string $extension File extension without dot. Default 'xml'.
	 * @param string $provider  Channel provider slug. When set, the file is
	 *                          placed in the V5 nested layout
	 *                          `woo-feed/{provider}/{extension}/` so the
	 *                          public feed URL is unchanged after a V5→V8
	 *                          upgrade (GMC/other channels keep working).
	 *
	 * @return string Absolute path to the feed file.
	 */
	public function get_feed_path( string $feed_name, string $extension = 'xml', string $provider = '' ): string {

		return $this->feed_base_dir( $provider, $extension ) . sanitize_file_name( $feed_name ) . '.' . $extension;
	}

	/**
	 * Build the V5-parity sub-path segment `{provider}/{extension}/`.
	 *
	 * Empty when no provider is given (flat `woo-feed/` layout — used by the
	 * generic Filesystem callers/tests that don't know the channel).
	 *
	 * @since 8.0.0
	 *
	 * @param string $provider  Channel provider slug.
	 * @param string $extension Feed type / file extension.
	 * @return string Sub-path with trailing slash, or '' when no provider.
	 */
	private function feed_sub_path( string $provider, string $extension ): string {
		if ( '' === $provider ) {
			return '';
		}

		return trailingslashit( sanitize_file_name( $provider ) )
			. trailingslashit( sanitize_file_name( $extension ) );
	}

	/**
	 * Resolve the absolute directory a feed file lives in, creating the
	 * nested `{provider}/{extension}/` sub-directories when a provider is set.
	 *
	 * @since 8.0.0
	 *
	 * @param string $provider  Channel provider slug.
	 * @param string $extension Feed type / file extension.
	 * @return string Absolute directory path with trailing slash.
	 */
	private function feed_base_dir( string $provider, string $extension ): string {
		$dir = $this->get_feed_dir() . $this->feed_sub_path( $provider, $extension );

		if ( '' !== $provider && ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	/**
	 * Get the public URL for a feed file.
	 *
	 * The feed name is sanitized to prevent directory traversal.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-1.3
	 *
	 * @param string $feed_name Feed name identifier.
	 * @param string $extension File extension without dot. Default 'xml'.
	 * @param string $provider  Channel provider slug — nests the URL as
	 *                          `woo-feed/{provider}/{extension}/…` (V5 layout).
	 *
	 * @return string Public URL to the feed file.
	 */
	public function get_feed_url( string $feed_name, string $extension = 'xml', string $provider = '' ): string {

		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['baseurl'] ) . 'woo-feed/'
			. $this->feed_sub_path( $provider, $extension )
			. sanitize_file_name( $feed_name ) . '.' . $extension;
	}

	/**
	 * Write content to a feed file using atomic write.
	 *
	 * Write sequence:
	 * 1. Write to {filename}.tmp.
	 * 2. If original exists, copy to {filename}.bak.
	 * 3. Rename .tmp to final filename (atomic on POSIX).
	 * 4. Delete .bak on success.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-1.4
	 *
	 * @param string $filename Absolute path to the output file.
	 * @param string $content  Feed content to write.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function write_feed( string $filename, string $content ): bool {

		$tmp_file = $filename . '.tmp';
		$bak_file = $filename . '.bak';

		// Step 1: Write to temp file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- Writing the generated feed file to the site's own uploads directory IS the product. The bytes must land unmodified and WP_Filesystem is not initialised on the Action Scheduler cron request that generates feeds.
		$written = file_put_contents( $tmp_file, $content );

		if ( false === $written ) {
			return false;
		}

		// Step 2: Backup existing file.
		if ( file_exists( $filename ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_copy -- Backs up the previous feed file so the atomic rename below can be rolled back; WP_Filesystem is not initialised on the Action Scheduler cron request that generates feeds.
			copy( $filename, $bak_file );
		}

		// Step 3: Atomic rename.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- The atomic rename is the whole point of the write sequence: readers of the public feed URL never see a half-written file. WP_Filesystem offers no atomic move.
		$renamed = rename( $tmp_file, $filename );

		if ( false === $renamed ) {
			// Restore from backup if rename fails.
			if ( file_exists( $bak_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Rollback path: restores the previous feed file so merchants keep serving the last good feed if the atomic rename failed.
				rename( $bak_file, $filename );
			}
			// Clean up temp file.
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return false;
		}

		// Step 4: Clean up backup.
		if ( file_exists( $bak_file ) ) {
			wp_delete_file( $bak_file );
		}

		return true;
	}

	/**
	 * Delete a feed file.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-1.5
	 *
	 * @param string $feed_name Feed name identifier.
	 * @param string $extension File extension without dot. Default 'xml'.
	 * @param string $provider  Channel provider slug (nested layout).
	 *
	 * @return bool True if deleted or not found, false on failure.
	 */
	public function delete_feed( string $feed_name, string $extension = 'xml', string $provider = '' ): bool {

		$path = $this->get_feed_path( $feed_name, $extension, $provider );

		if ( ! file_exists( $path ) ) {
			return true;
		}

		wp_delete_file( $path );

		return ! file_exists( $path );
	}

	/**
	 * Get the file size of a feed in bytes.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-1.6
	 *
	 * @param string $feed_name Feed name identifier.
	 * @param string $extension File extension without dot. Default 'xml'.
	 * @param string $provider  Channel provider slug (nested layout).
	 *
	 * @return int File size in bytes, or 0 if file does not exist.
	 */
	public function get_feed_size( string $feed_name, string $extension = 'xml', string $provider = '' ): int {

		$path = $this->get_feed_path( $feed_name, $extension, $provider );

		if ( ! file_exists( $path ) ) {
			return 0;
		}

		return (int) filesize( $path );
	}

	/**
	 * Clean up temporary files older than the specified threshold.
	 *
	 * Iterates the temp directory and removes files with modification
	 * time older than the given number of hours.
	 *
	 * @since 8.0.0
	 * @implements UTIL-FRD-1.7
	 *
	 * @param int $max_age_hours Maximum age in hours. Default 24.
	 *
	 * @return int Number of files deleted.
	 */
	public function cleanup_temp_files( int $max_age_hours = 24 ): int {

		$temp_dir = $this->get_feed_dir() . 'temp/';

		if ( ! is_dir( $temp_dir ) ) {
			return 0;
		}

		$count  = 0;
		$cutoff = time() - ( $max_age_hours * 3600 );
		$files  = glob( $temp_dir . '*' );

		if ( false === $files ) {
			return 0;
		}

		foreach ( $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
				++$count;
			}
		}

		return $count;
	}
}
